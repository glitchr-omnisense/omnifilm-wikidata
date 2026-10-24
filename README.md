# omnifilm/wikidata

[omniscient/omnifilm](https://github.com/glitchr-studio/omnifilm)'s Wikidata, under any PHP host,
with no key: films, series and episodes from Wikidata's open records through the MediaWiki API.

- **search** - CirrusSearch narrowed on the server to works (`haswbstatement:P31=Q11424|...`:
  film, television film, animated, feature, short, documentary and silent films; television,
  animated, mini, web and anime series), then one `wbgetentities` call for the answers;
- **a work** by its Q-number, or by an identifier Wikidata keeps: IMDb (P345), TMDB (P4947 for a
  film, P4983 for a series), TheTVDB (P12196, P4835, P7043 for an episode) - found by
  `haswbstatement`; its genres, countries, languages, director, writers, cast (30 at most, with
  their character), producers and composer labelled in the language asked in one more call; its
  poster, logo and image as Wikimedia Commons file paths.

```php
use Omnifilm\Wikidata\WikidataFactory;

$provider = (new WikidataFactory($httpClient))->create([
    'user_agent' => 'MySite/1.0 (https://example.org; films@example.org)',
]);
$stalker = $provider->work(WorkRef::imdb('tt0079944'), 'fr');
```

Options: `user_agent` (required), `language` ("en"), `api` (Wikidata's; another Wikibase's),
`maxlag` (0: not sent - set it for batch jobs).

## Wikimedia's policies, as read on 2026-10-24

- [User-Agent policy](https://foundation.wikimedia.org/wiki/Policy:User-Agent_policy): a
  User-Agent naming the application and a way to reach its operator; the factory refuses one
  without a name and a version or a contact.
- [API etiquette](https://www.mediawiki.org/wiki/API:Etiquette): requests one after another, not
  in parallel; `maxlag` for non-interactive work. A page a reader waits for is interactive, and
  with `maxlag` Wikidata refuses reads whenever its query service lags (it answered "8.1 seconds
  lagged" during this package's writing): it is off by default, an option for batch jobs. Keep
  what you read (a cache of the application's): the records change slowly.
- Data under [CC0](https://www.wikidata.org/wiki/Wikidata:Licensing); the pictures on Wikimedia
  Commons each have their own licence and author, to credit where shown.

## Verified, and not

- Verified (the core's harness, PHP 8.4): search, lookups and labels on Wikidata's answers
  recorded on 2026-10-24 (claims trimmed to the properties read, references dropped); an error
  answered with a 200 (maxlag) raised; the User-Agent required; `BareTest`; PHPStan max.
- Verified live (`docker compose run --rm omnifilm live`, 2026-10-24): Stalker found by a search
  narrowed to 1979, The Shawshank Redemption by its IMDb number (TMDB 278, Frank Darabont), Game
  of Thrones by its TMDB series number (Q23572, tt0944947, TheTVDB 121361, 2011).
- Not done: episodes of a season (Wikidata's season and ordinal qualifiers vary too much from one
  series to another to be listed reliably); people's IMDb numbers (one more call per person).

## Responsibility

An independent, unofficial client, affiliated with, endorsed by or sponsored by none of the services
it reaches. The MIT licence covers this code only and grants no right to the service or its data:
whoever runs it complies with the terms above and obtains what they require. The authors publish
it free of charge, make no commercial use of it and accept no liability for its use.

## Licence

MIT.
