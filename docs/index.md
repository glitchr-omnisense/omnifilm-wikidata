# omnifilm/wikidata

Wikidata for [omnisense/omnifilm](https://github.com/glitchr-omnisense/omnifilm): films, series and
episodes from Wikidata's open records, with no key, through the MediaWiki API
(`https://www.wikidata.org/w/api.php`). The provider calls, with `format=json` and
`formatversion=1`:

| Operation | Calls |
|---|---|
| search | `action=query&list=search` (CirrusSearch, narrowed to works' classes with `haswbstatement:P31=...`), then `action=wbgetentities` for the answers |
| a work by its Q-number | `wbgetentities` (labels, descriptions, claims, sitelinks), then `wbgetentities` for the labels of the items it names (one call per 50) |
| a work by another register | a `haswbstatement:<property>=<id>` search first, then the same two calls |

Every request sends the configured User-Agent and `Accept: application/json`, one after another
(never in parallel), and `maxlag` when configured. Wikidata has no sandbox; another Wikibase can be
named with `api`.

## Installation

```sh
composer require omnifilm/wikidata
```

Requires PHP 8.2, `omnisense/omnifilm` (1.x) and `symfony/http-client` (6.4, 7 or 8).

## Credentials

None. Wikimedia's [User-Agent policy](https://foundation.wikimedia.org/wiki/Policy:User-Agent_policy)
asks every client to name itself and a way to reach its operator:
`MySite/1.0 (https://example.org; films@example.org)`. The factory refuses a `user_agent` without
a `/` or a `(` (`InvalidConfigException`).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `user_agent` | string | - | yes | the application's name, version and contact |
| `language` | string | `en` | no | the labels' language when a call names none |
| `api` | string | `https://www.wikidata.org/w/api.php` | no | the MediaWiki API: Wikidata's, or another Wikibase's |
| `maxlag` | int | `0` | no | `0`: not sent; seconds: sent, and Wikidata refuses reads while its servers lag more |

A language is reduced to MediaWiki's code: `fr-FR` and `fr_FR` are asked as `fr`; `pt-br`,
`zh-hans`, `zh-hant`, `en-gb` and `de-ch` are kept whole. `languagefallback=1` is sent, so a label
missing in that language comes in the one Wikidata falls back to.

## Capabilities

| Contract | |
|---|---|
| `SearchInterface` | yes: films and series |
| `LookupInterface` | film and series by wikidata, imdb, tmdb, tvdb; episode by wikidata, imdb, tvdb |
| `EpisodesInterface` | no |
| `AvailabilityInterface` | no |
| images, credits | yes, keyless |

`attribution`: `Data from Wikidata (CC0); pictures from Wikimedia Commons under their own licences`.

## Behaviour

**What a work is.** An item is a work when one of its "instance of" (P31) classes is one of:

| Type | Classes |
|---|---|
| movie | Q11424 (film), Q506240 (television film), Q202866 (animated film), Q24869 (feature film), Q24862 (short film), Q93204 (documentary film), Q226730 (silent film) |
| series | Q5398426 (television series), Q581714 (animated series), Q1259759 (miniseries), Q526877 (web series), Q63952888 (anime television series) |
| episode | Q21191270 (television series episode), Q21664088 (two-part episode) |

An item of none of them is not a work (`null`); an item of another type than the ref's is `null`
too (`WikidataProvider::CLASSES`, a public constant).

**Search.** CirrusSearch with the words and `haswbstatement:P31=...|...` over the classes of the
type asked (films and series when none), `srlimit` twice the query's limit (50 at most), then one
`wbgetentities` for labels, descriptions and claims. A year, when given, is compared with each
answer's; episodes are dropped; the list is cut to the limit. Search answers carry no genres,
countries, languages nor credits (their labels are not fetched).

**Lookup.** By Q-number directly (`q200437` is upper-cased; anything not `Q<digits>` is `null`);
by another register, the first item found by `haswbstatement`:

| Register | movie | series | episode |
|---|---|---|---|
| imdb | P345 | P345 | P345 |
| tmdb | P4947 | P4983 | - |
| tvdb | P12196 | P4835 | P7043 |

**The record.**

| Field | Read from |
|---|---|
| `title` | the label in the language asked (or Wikidata's fallback), else the original title, else the Q-number |
| `originalTitle` | "title" (P1476), when it differs from the label |
| `released`, `year` | a film: the earliest publication date (P577); a series: its start time (P580), else P577. The year is the earliest; the day is kept only when a date has day precision and falls in that year |
| `runtime` | duration (P2047) in minutes, from minutes, seconds or hours |
| `overview` | the description in the language asked (a short line: `film sorti en 1979`) |
| `genres`, `countries`, `languages` | genre (P136), country of origin (P495), original language (P364), as labels |
| `identifiers` | the Q-number and the properties of the table above |
| `images` | poster (P3383) as `Poster`, logo (P154) as `Logo`, image (P18) as `Other`: Wikimedia Commons `Special:FilePath` addresses |
| `credits` | director (P57), screenwriter (P58), cast member (P161) with its "character role" qualifiers (P453), producer (P162), composer (P86): 30 statements at most per property, each person's Wikidata number as identifier, the cast's order its rank among the statements |
| `url` | `https://www.wikidata.org/wiki/<Q-number>` |
| `series` | an episode's "part of the series" (P179), as a Wikidata number |

Deprecated statements are left out; preferred ones come first. People's IMDb numbers are not read
(one more call per person). `season` and `number` are never set: episodes of a season are not
listed (Wikidata's season and ordinal qualifiers vary too much from one series to another).

**Errors in a 200.** The API answers some errors with status 200 and `{"error": {"code": ...}}`
(`maxlag`, a bad parameter): they become `ProviderException`s.

## Example

Run live on 2026-10-26 (15:22 UTC), in the core's harness - five calls:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnifilm\Model\Query;
use Omnifilm\Model\Role;
use Omnifilm\Model\WorkRef;
use Omnifilm\Model\WorkType;
use Omnifilm\Wikidata\WikidataFactory;
use Symfony\Component\HttpClient\HttpClient;

$wikidata = (new WikidataFactory(HttpClient::create(['timeout' => 30])))->create([
    'user_agent' => 'OmnisenseDocs/1.0 (https://github.com/glitchr-omnisense/omnifilm)',
]);

foreach ($wikidata->search(new Query('Stalker', WorkType::Movie, 1979, 'fr', limit: 3)) as $work) {
    printf("%s  %s (%d)  %s\n", $work->identifiers->get('wikidata'), $work->title, $work->year, $work->overview);
}

$stalker = $wikidata->work(WorkRef::imdb('tt0079944'), 'fr');
echo $stalker->title, ' / ', $stalker->originalTitle, ', ', $stalker->released?->format('Y-m-d'), ', ', $stalker->runtime, " min\n";
echo json_encode($stalker->identifiers->toArray()), "\n";
echo implode(', ', $stalker->genres), "\n";
echo implode(', ', array_map(static fn ($c) => $c->name, $stalker->credits(Role::Director))), "\n";
```

```
Q200437  Stalker (1979)  film sorti en 1979
Stalker / Сталкер, 1979-05-25, 163 min
{"imdb":"tt0079944","tmdb":"1398","tvdb":"4087","wikidata":"Q200437"}
cinéma de science-fiction, film d'art, drame, arthouse science fiction film
Andreï Tarkovski
```

In Symfony (no bundle: [the core's Symfony page](https://github.com/glitchr-omnisense/omnifilm/blob/1.x/docs/symfony.md)):

```yaml
services:
    Omnifilm\Wikidata\WikidataFactory:
        arguments: ['@http_client']
    Omnifilm\Registry:
        arguments:
            $factories: ['@Omnifilm\Wikidata\WikidataFactory']
            $config:
                open: { factory: wikidata, options: { user_agent: 'MySite/1.0 (https://example.org; films@example.org)', language: fr } }
```

## Errors

- an error in a 200 (`maxlag`...): `ProviderException` (`Wikidata answered maxlag for query.
  Waiting for a database server: 8.1 seconds lagged.`, on a recorded answer);
- a status of 400 or more but 404: `ProviderException`; a network error: `UnreachableException`;
- an item missing, not a work, or of another type: `null`;
- an episode by a TMDB number, a Trakt number: `NotSupportedException` before any call;
- a `user_agent` missing or naming no application: `InvalidConfigException`.

## Verified, and not

Tested offline (`Tests/WikidataProviderTest.php`, in the core's harness, PHP 8.4), on answers
recorded from Wikidata on 2026-10-24 (claims trimmed to the properties read, references dropped):
a search narrowed to films on the server and read in one more call, a work by its IMDb number with
its people labelled in the language asked, a work of another type or unknown, an error answered
with a 200, the User-Agent required. `Tests/BareTest.php` loads every class with the package's
requirements alone.

Verified live: `docker compose run --rm omnifilm live` (`Tests/LiveTest.php`), 2026-10-24 -
Stalker by a search narrowed to 1979, The Shawshank Redemption by its IMDb number (TMDB 278, Frank
Darabont), Game of Thrones by its TMDB series number (Q23572, tt0944947, TheTVDB 121361, 2011);
and on 2026-10-26 the example above and Game of Thrones again through `Registry::work()`.

Not done: episodes of a season; people's IMDb numbers.

## Responsibility

An independent, unofficial client, affiliated with the Wikimedia Foundation in no way. Whoever runs
it keeps Wikimedia's [User-Agent policy](https://foundation.wikimedia.org/wiki/Policy:User-Agent_policy)
and [API etiquette](https://www.mediawiki.org/wiki/API:Etiquette) (requests one after another,
`maxlag` for batch work, a cache of the application's); the data is
[CC0](https://www.wikidata.org/wiki/Wikidata:Licensing), the Commons pictures each under their own
licence and author, to credit where shown: see the README.
