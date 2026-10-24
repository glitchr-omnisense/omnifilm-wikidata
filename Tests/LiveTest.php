<?php

namespace Omnifilm\Wikidata\Tests;

use Omnifilm\Model\Query;
use Omnifilm\Model\Role;
use Omnifilm\Model\WorkRef;
use Omnifilm\Model\WorkType;
use Omnifilm\Wikidata\WikidataFactory;
use Omnifilm\Wikidata\WikidataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Wikidata itself, within its policies (a named User-Agent, a handful of
 * requests one after another): a search, a film by its IMDb number, a
 * series by its TMDB number. `docker compose run --rm omnifilm live`; never
 * in the default run.
 */
#[Group('live')]
final class LiveTest extends TestCase
{
    private const UA = 'omnifilm-tests/1.0 (+https://github.com/glitchr-studio/omnifilm)';

    public function testSearchAndLookups(): void
    {
        $provider = self::provider(HttpClient::create(['timeout' => 30]), ['user_agent' => self::UA]);

        $found = $provider->search(new Query('Stalker', WorkType::Movie, 1979));
        self::assertSame('Q200437', $found[0]->identifiers->get('wikidata') ?? null);

        $film = $provider->work(WorkRef::imdb('tt0111161'), 'en');
        self::assertNotNull($film);
        self::assertSame(['The Shawshank Redemption', 1994, '278'], [$film->title, $film->year, $film->identifiers->get('tmdb')]);
        self::assertSame('Frank Darabont', $film->credits(Role::Director)[0]->name ?? null);

        $series = $provider->work(WorkRef::tmdb(1399, WorkType::Series), 'fr');
        self::assertNotNull($series);
        self::assertSame(['Q23572', 'tt0944947', '121361', 2011], [$series->identifiers->get('wikidata'), $series->identifiers->get('imdb'), $series->identifiers->get('tvdb'), $series->year]);
    }

    /** @param array<string, mixed> $options */
    private static function provider(HttpClientInterface $http, array $options): WikidataProvider
    {
        $provider = (new WikidataFactory($http))->create($options);
        self::assertInstanceOf(WikidataProvider::class, $provider);

        return $provider;
    }
}
