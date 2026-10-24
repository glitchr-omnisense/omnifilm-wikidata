<?php

namespace Omnifilm\Wikidata;

use Omnifilm\Capabilities;
use Omnifilm\Exception\NotSupportedException;
use Omnifilm\Exception\ProviderException;
use Omnifilm\Http\Data;
use Omnifilm\Http\JsonClient;
use Omnifilm\LookupInterface;
use Omnifilm\Model\Credit;
use Omnifilm\Model\Identifiers;
use Omnifilm\Model\Image;
use Omnifilm\Model\ImageKind;
use Omnifilm\Model\Query;
use Omnifilm\Model\Role;
use Omnifilm\Model\Scheme;
use Omnifilm\Model\Work;
use Omnifilm\Model\WorkRef;
use Omnifilm\Model\WorkType;
use Omnifilm\ProviderInterface;
use Omnifilm\SearchInterface;

/**
 * Wikidata's records of films, series and episodes, through the MediaWiki
 * API (no key): a search narrowed by "instance of" on the server
 * (haswbstatement), a work by its Q-number or by any identifier Wikidata
 * keeps (IMDb, TMDB, TheTVDB), the labels of its genres and people in a
 * second call. Every request names the application (User-Agent), as
 * Wikimedia's policy asks; maxlag is sent when configured (batch jobs).
 */
final class WikidataProvider implements ProviderInterface, SearchInterface, LookupInterface
{
    /** The classes ("instance of", P31) a work of each type is found by. */
    public const CLASSES = [
        'movie' => ['Q11424', 'Q506240', 'Q202866', 'Q24869', 'Q24862', 'Q93204', 'Q226730'],
        'series' => ['Q5398426', 'Q581714', 'Q1259759', 'Q526877', 'Q63952888'],
        'episode' => ['Q21191270', 'Q21664088'],
    ];

    /** The identifiers Wikidata keeps, per type: the property of each register. */
    public const IDENTIFIERS = [
        'movie' => ['imdb' => 'P345', 'tmdb' => 'P4947', 'tvdb' => 'P12196'],
        'series' => ['imdb' => 'P345', 'tmdb' => 'P4983', 'tvdb' => 'P4835'],
        'episode' => ['imdb' => 'P345', 'tvdb' => 'P7043'],
    ];

    private const CREDITS = ['P57' => Role::Director, 'P58' => Role::Writer, 'P161' => Role::Cast, 'P162' => Role::Producer, 'P86' => Role::Composer];
    private const CAST_LIMIT = 30;
    private const UNITS = ['Q7727' => 1, 'Q11574' => 1 / 60, 'Q25235' => 60];

    public function __construct(
        private readonly JsonClient $json,
        private readonly string $api = 'https://www.wikidata.org/w/api.php',
        private readonly string $language = 'en',
        private readonly int $maxlag = 0,
    ) {
    }

    public function getName(): string
    {
        return 'wikidata';
    }

    public function getTitle(): string
    {
        return 'Wikidata';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            search: true,
            lookup: true,
            types: [WorkType::Movie, WorkType::Series, WorkType::Episode],
            lookupBy: [
                'movie' => [Scheme::Wikidata, Scheme::Imdb, Scheme::Tmdb, Scheme::Tvdb],
                'series' => [Scheme::Wikidata, Scheme::Imdb, Scheme::Tmdb, Scheme::Tvdb],
                'episode' => [Scheme::Wikidata, Scheme::Imdb, Scheme::Tvdb],
            ],
            images: true,
            credits: true,
            attribution: 'Data from Wikidata (CC0); pictures from Wikimedia Commons under their own licences',
            terms: ['https://foundation.wikimedia.org/wiki/Policy:User-Agent_policy', 'https://www.mediawiki.org/wiki/API:Etiquette', 'https://www.wikidata.org/wiki/Wikidata:Licensing'],
            keyless: true,
        );
    }

    public function search(Query $query): array
    {
        $types = null === $query->type ? ['movie', 'series'] : [$query->type->value];
        $classes = array_merge(...array_map(static fn (string $type) => self::CLASSES[$type], $types));
        $ids = $this->find($query->text.' haswbstatement:'.implode('|', array_map(static fn (string $class) => 'P31='.$class, $classes)), min(50, $query->limit * 2));
        $language = self::language($query->language ?? $this->language);
        $works = [];
        foreach ($this->entities($ids, 'labels|descriptions|claims', $language) as $entity) {
            $work = $this->work0($entity, $language, []);
            if (null !== $work && WorkType::Episode !== $work->type && (null === $query->year || $query->year === $work->year)) {
                $works[] = $work;
            }
        }

        return \array_slice($works, 0, $query->limit);
    }

    public function work(WorkRef $ref, ?string $language = null): ?Work
    {
        if (!$this->capabilities()->resolves($ref)) {
            throw NotSupportedException::ref($this->getTitle(), $ref);
        }
        if (Scheme::Wikidata === $ref->scheme) {
            $id = preg_match('~^Q[1-9][0-9]*$~', strtoupper($ref->id)) ? strtoupper($ref->id) : null;
        } else {
            $property = self::IDENTIFIERS[$ref->type->value][$ref->scheme->value] ?? throw NotSupportedException::ref($this->getTitle(), $ref);
            $id = $this->find('haswbstatement:'.$property.'='.str_replace(['"', ' '], '', $ref->id), 1)[0] ?? null;
        }
        $language = self::language($language ?? $this->language);
        $entity = null === $id ? null : ($this->entities([$id], 'labels|descriptions|claims|sitelinks/urls', $language)[0] ?? null);
        if (null === $entity) {
            return null;
        }
        $labels = $this->labels($entity, $language);
        $work = $this->work0($entity, $language, $labels);

        return $work?->type === $ref->type ? $work : null;
    }

    /**
     * Q-numbers found by CirrusSearch, best first.
     *
     * @return list<string>
     */
    private function find(string $search, int $limit): array
    {
        $answer = $this->call(['action' => 'query', 'list' => 'search', 'srsearch' => $search, 'srlimit' => $limit, 'srprop' => '', 'srnamespace' => 0]);

        return array_values(array_filter($answer?->strings('query.search', 'title') ?? [], static fn (string $id) => (bool) preg_match('~^Q[0-9]+$~', $id)));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Data>
     */
    private function entities(array $ids, string $props, string $language): array
    {
        if ([] === $ids) {
            return [];
        }
        $answer = $this->call(['action' => 'wbgetentities', 'ids' => implode('|', $ids), 'props' => $props, 'languages' => $language, 'languagefallback' => 1]);
        $entities = $answer?->data('entities');
        $found = [];
        foreach ($ids as $id) {
            $entity = $entities?->data($id);
            if (null !== $entity && null !== $entity->string('id') && !$entity->has('missing')) {
                $found[] = $entity;
            }
        }

        return $found;
    }

    /**
     * The labels of the items a work names (genres, people, characters,
     * countries, languages, its series), in one call per 50.
     *
     * @return array<string, string>
     */
    private function labels(Data $entity, string $language): array
    {
        $ids = [];
        foreach (['P136', 'P495', 'P364', 'P179', ...array_keys(self::CREDITS)] as $property) {
            foreach (\array_slice(self::statements($entity, $property), 0, self::CAST_LIMIT) as $statement) {
                $ids[] = $statement->string('mainsnak.datavalue.value.id');
                foreach ($statement->list('qualifiers.P453') as $character) {
                    $ids[] = $character->string('datavalue.value.id');
                }
            }
        }
        $labels = [];
        foreach (array_chunk(array_values(array_unique(array_filter($ids))), 50) as $chunk) {
            foreach ($this->entities($chunk, 'labels', $language) as $item) {
                $label = self::label($item, $language);
                if (null !== $label && null !== $id = $item->string('id')) {
                    $labels[$id] = $label;
                }
            }
        }

        return $labels;
    }

    /** @param array<string, string> $labels */
    private function work0(Data $entity, string $language, array $labels): ?Work
    {
        $id = (string) $entity->string('id');
        $classes = array_filter(array_map(static fn (Data $s) => $s->string('mainsnak.datavalue.value.id'), self::statements($entity, 'P31')));
        $type = null;
        foreach (self::CLASSES as $value => $known) {
            if (array_intersect($classes, $known)) {
                $type = WorkType::from($value);
                break;
            }
        }
        if (null === $type) {
            return null;
        }
        $titles = self::statements($entity, 'P1476');
        $originalTitle = isset($titles[0]) ? $titles[0]->string('mainsnak.datavalue.value.text') : null;
        $title = self::label($entity, $language) ?? $originalTitle ?? $id;
        [$released, $year] = self::released($entity, WorkType::Series === $type ? ['P580', 'P577'] : ['P577']);
        $ids = ['wikidata' => $id];
        foreach (self::IDENTIFIERS[$type->value] as $scheme => $property) {
            $ids[$scheme] = self::value($entity, $property);
        }
        $series = self::itemOf($entity, 'P179');

        return new Work(
            type: $type,
            title: $title,
            identifiers: Identifiers::of($ids),
            source: 'wikidata',
            originalTitle: $originalTitle !== $title ? $originalTitle : null,
            year: $year,
            released: $released,
            runtime: self::runtime($entity),
            overview: $entity->string('descriptions.'.$language.'.value') ?? self::first($entity->data('descriptions')),
            genres: self::named($entity, 'P136', $labels),
            countries: self::named($entity, 'P495', $labels),
            languages: self::named($entity, 'P364', $labels),
            images: self::images($entity),
            credits: [] === $labels ? [] : self::credits($entity, $labels),
            url: 'https://www.wikidata.org/wiki/'.$id,
            series: WorkType::Episode === $type && null !== $series ? Identifiers::of(['wikidata' => $series]) : null,
        );
    }

    /**
     * One API call; an error the API answers with a 200 (maxlag, a bad
     * parameter) is a ProviderException too.
     *
     * @param array<string, string|int> $query
     */
    private function call(array $query): ?Data
    {
        $answer = $this->json->get($this->api, $query + ['format' => 'json', 'formatversion' => 1, 'maxlag' => $this->maxlag > 0 ? $this->maxlag : null]);
        if (null !== $code = $answer?->string('error.code')) {
            throw ProviderException::answered('Wikidata', (string) $query['action'], $code, (string) $answer->string('error.info'));
        }

        return $answer;
    }

    /**
     * A statement list, the deprecated ones left out, the preferred ones first.
     *
     * @return list<Data>
     */
    private static function statements(Data $entity, string $property): array
    {
        $statements = array_values(array_filter($entity->list('claims.'.$property), static fn (Data $s) => 'deprecated' !== $s->string('rank')));
        usort($statements, static fn (Data $a, Data $b) => ('preferred' === $b->string('rank')) <=> ('preferred' === $a->string('rank')));

        return $statements;
    }

    private static function value(Data $entity, string $property): ?string
    {
        foreach (self::statements($entity, $property) as $statement) {
            if (null !== $value = $statement->string('mainsnak.datavalue.value')) {
                return $value;
            }
        }

        return null;
    }

    private static function itemOf(Data $entity, string $property): ?string
    {
        $statements = self::statements($entity, $property);

        return isset($statements[0]) ? $statements[0]->string('mainsnak.datavalue.value.id') : null;
    }

    /**
     * @param array<string, string> $labels
     *
     * @return list<string>
     */
    private static function named(Data $entity, string $property, array $labels): array
    {
        $names = [];
        foreach (self::statements($entity, $property) as $statement) {
            $id = $statement->string('mainsnak.datavalue.value.id');
            if (null !== $id && isset($labels[$id])) {
                $names[] = $labels[$id];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The earliest date of the first property that has one (a film's first
     * release, a series' start), and its year.
     *
     * @param list<string> $properties
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?int}
     */
    private static function released(Data $entity, array $properties): array
    {
        foreach ($properties as $property) {
            $dates = [];
            foreach (self::statements($entity, $property) as $statement) {
                $time = $statement->string('mainsnak.datavalue.value.time');
                if (null !== $time && preg_match('~^\+(\d{4})-(\d{2})-(\d{2})~', $time, $m)) {
                    $precision = $statement->int('mainsnak.datavalue.value.precision') ?? 9;
                    $dates[] = [$m[1].'-'.$m[2].'-'.$m[3], (int) $m[1], $precision >= 11];
                }
            }
            if ($dates) {
                usort($dates, static fn (array $a, array $b) => $a[0] <=> $b[0]);
                $day = array_values(array_filter($dates, static fn (array $d) => $d[2]))[0] ?? null;
                $year = min(array_column($dates, 1));

                return [null !== $day && $day[1] === $year ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $day[0], new \DateTimeZone('UTC')) ?: null) : null, $year];
            }
        }

        return [null, null];
    }

    private static function runtime(Data $entity): ?int
    {
        foreach (self::statements($entity, 'P2047') as $statement) {
            $amount = $statement->float('mainsnak.datavalue.value.amount');
            $unit = basename((string) $statement->string('mainsnak.datavalue.value.unit'));
            if (null !== $amount && isset(self::UNITS[$unit])) {
                return (int) round($amount * self::UNITS[$unit]);
            }
        }

        return null;
    }

    /** @return list<Image> */
    private static function images(Data $entity): array
    {
        $images = [];
        foreach (['P3383' => ImageKind::Poster, 'P154' => ImageKind::Logo, 'P18' => ImageKind::Other] as $property => $kind) {
            $file = self::value($entity, $property);
            if (null !== $file) {
                $images[] = new Image($kind, 'https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode(str_replace(' ', '_', $file)));
            }
        }

        return $images;
    }

    /**
     * @param array<string, string> $labels
     *
     * @return list<Credit>
     */
    private static function credits(Data $entity, array $labels): array
    {
        $credits = [];
        foreach (self::CREDITS as $property => $role) {
            foreach (\array_slice(self::statements($entity, $property), 0, self::CAST_LIMIT) as $order => $statement) {
                $id = $statement->string('mainsnak.datavalue.value.id');
                if (null === $id || !isset($labels[$id])) {
                    continue;
                }
                $characters = array_filter(array_map(static fn (Data $q) => $labels[(string) $q->string('datavalue.value.id')] ?? $q->string('datavalue.value'), $statement->list('qualifiers.P453')));
                $credits[] = new Credit($labels[$id], $role, $characters ? implode(' / ', $characters) : null, Role::Cast === $role ? $order : null, Identifiers::of(['wikidata' => $id]));
            }
        }

        return $credits;
    }

    private static function label(Data $entity, string $language): ?string
    {
        return $entity->string('labels.'.$language.'.value') ?? self::first($entity->data('labels'));
    }

    /** The value of the first language given (the fallback Wikidata chose). */
    private static function first(Data $texts): ?string
    {
        foreach ($texts->keys() as $key) {
            if (null !== $value = $texts->data($key)->string('value')) {
                return $value;
            }
        }

        return null;
    }

    /** "fr-FR" → "fr": Wikidata's language codes are MediaWiki's. */
    private static function language(string $language): string
    {
        $language = strtolower(str_replace('_', '-', $language));

        return \in_array($language, ['pt-br', 'zh-hans', 'zh-hant', 'en-gb', 'de-ch'], true) ? $language : explode('-', $language)[0];
    }
}
