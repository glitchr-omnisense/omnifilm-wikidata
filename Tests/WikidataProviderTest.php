<?php

namespace Omnifilm\Wikidata\Tests;

use Omnifilm\Exception\InvalidConfigException;
use Omnifilm\Exception\NotSupportedException;
use Omnifilm\Exception\ProviderException;
use Omnifilm\Model\Credit;
use Omnifilm\Model\ImageKind;
use Omnifilm\Model\Query;
use Omnifilm\Model\Role;
use Omnifilm\Model\Work;
use Omnifilm\Model\WorkRef;
use Omnifilm\Model\WorkType;
use Omnifilm\Testing\RecordedHttpClient;
use Omnifilm\Wikidata\WikidataFactory;
use Omnifilm\Wikidata\WikidataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Wikidata's answers as recorded on 2026-10-24 (claims trimmed to the properties read, references dropped). */
final class WikidataProviderTest extends TestCase
{
    private const UA = 'omnifilm-tests/1.0 (+https://github.com/glitchr-omnisense/omnifilm)';

    public function testASearchIsNarrowedToFilmsOnTheServerAndReadInOneMoreCall(): void
    {
        $http = self::http();
        $works = self::provider($http, ['user_agent' => self::UA])->search(new Query('stalker', WorkType::Movie, limit: 2));

        self::assertSame(['Stalker', 'The Stalker'], array_map(static fn (Work $w) => $w->title, $works));
        self::assertSame([1979, 2014], array_map(static fn (Work $w) => $w->year, $works));
        self::assertSame(['imdb' => 'tt0079944', 'tmdb' => '1398', 'tvdb' => '4087', 'wikidata' => 'Q200437'], $works[0]->identifiers->toArray());
        self::assertSame([], $works[0]->credits, 'a search answer is short: no labels fetched');
        self::assertCount(2, $http->requests);
        $query = $http->requests[0]['options']['query'] ?? [];
        self::assertIsArray($query);
        self::assertSame('stalker haswbstatement:P31=Q11424|P31=Q506240|P31=Q202866|P31=Q24869|P31=Q24862|P31=Q93204|P31=Q226730', $query['srsearch'] ?? null);
        self::assertArrayNotHasKey('maxlag', $query);
        self::assertSame(self::UA, $http->header('User-Agent'));
        self::assertSame([1979], array_map(static fn (Work $w) => $w->year, self::provider(self::http(), ['user_agent' => self::UA])->search(new Query('stalker', WorkType::Movie, 1979))));
    }

    public function testAWorkByItsImdbNumberWithItsPeopleLabelledInTheLanguageAsked(): void
    {
        $http = self::http();
        $work = self::provider($http, ['user_agent' => self::UA, 'maxlag' => 5])->work(WorkRef::imdb('tt0079944'), 'fr-FR');

        self::assertNotNull($work);
        self::assertSame(['Stalker', 'Сталкер', 1979, '1979-05-25', 163], [$work->title, $work->originalTitle, $work->year, $work->released?->format('Y-m-d'), $work->runtime]);
        self::assertSame('film sorti en 1979', $work->overview);
        self::assertSame(['cinéma de science-fiction', "film d'art", 'drame', 'arthouse science fiction film'], $work->genres);
        self::assertSame(['Union soviétique'], $work->countries);
        self::assertSame(['russe'], $work->languages);
        self::assertSame(['Andreï Tarkovski'], array_map(static fn (Credit $c) => $c->name, $work->credits(Role::Director)));
        self::assertSame(['Anatoli Solonitsyne', 'Alisa Freindlich', 'Alexandre Kaïdanovski', 'Nikolaï Grinko', 'Vladimir Zamanski'], array_map(static fn (Credit $c) => $c->name, $work->credits(Role::Cast)));
        self::assertSame('Q853', $work->credits(Role::Director)[0]->identifiers->get('wikidata'));
        self::assertSame(['Edouard Artemiev'], array_map(static fn (Credit $c) => $c->name, $work->credits(Role::Composer)));
        self::assertSame('https://commons.wikimedia.org/wiki/Special:FilePath/%D0%A1%D1%82%D0%B0%D0%BB%D0%BA%D0%B5%D1%80_logo.png', $work->image(ImageKind::Logo)?->url);
        self::assertSame('https://www.wikidata.org/wiki/Q200437', $work->url);
        self::assertCount(3, $http->requests, 'the identifier, the entity, the labels');
        $query = $http->requests[0]['options']['query'] ?? [];
        self::assertIsArray($query);
        self::assertSame('haswbstatement:P345=tt0079944', $query['srsearch'] ?? null);
        self::assertSame(5, $query['maxlag'] ?? null);
    }

    public function testAWorkOfAnotherTypeOrUnknownIsNotFound(): void
    {
        $provider = self::provider(self::http(), ['user_agent' => self::UA]);

        self::assertNull($provider->work(WorkRef::wikidata('Q200437', WorkType::Series)));
        self::assertNull($provider->work(WorkRef::imdb('tt0000000')));
        self::assertNull($provider->work(WorkRef::wikidata('not-a-q-number')));

        $this->expectException(NotSupportedException::class);
        $provider->work(WorkRef::trakt(1));
    }

    public function testAnErrorAnsweredWithA200IsAnError(): void
    {
        $http = RecordedHttpClient::routes(['~~' => [200, '{"error": {"code": "maxlag", "info": "Waiting for wdqs1013: 8.1 seconds lagged."}}']]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Wikidata answered maxlag for query. Waiting for wdqs1013: 8.1 seconds lagged.');
        self::provider($http, ['user_agent' => self::UA])->search(new Query('stalker'));
    }

    public function testAUserAgentNamingTheApplicationIsRequired(): void
    {
        $this->expectException(InvalidConfigException::class);
        self::provider(self::http(), ['user_agent' => 'curl']);
    }

    private static function http(): RecordedHttpClient
    {
        $f = '@'.__DIR__.'/Fixtures/';

        return RecordedHttpClient::routes([
            '~srsearch=stalker~' => [200, $f.'search-stalker.json'],
            '~srsearch=haswbstatement%3AP345%3Dtt0079944~' => [200, $f.'find-imdb.json'],
            '~srsearch=haswbstatement~' => [200, '{"batchcomplete": "", "query": {"searchinfo": {"totalhits": 0}, "search": []}}'],
            '~ids=Q200437%7CQ16615177~' => [200, $f.'entities-search.json'],
            '~ids=Q200437&props=labels%7Cdescriptions%7Cclaims%7Csitelinks~' => [200, $f.'entity-stalker-fr.json'],
            '~action=wbgetentities&ids=Q[^&]+&props=labels&languages=fr~' => [200, $f.'labels-stalker-fr.json'],
        ]);
    }

    /** @param array<string, mixed> $options */
    private static function provider(HttpClientInterface $http, array $options): WikidataProvider
    {
        $provider = (new WikidataFactory($http))->create($options);
        self::assertInstanceOf(WikidataProvider::class, $provider);

        return $provider;
    }
}
