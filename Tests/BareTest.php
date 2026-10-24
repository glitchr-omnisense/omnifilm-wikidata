<?php

namespace Omnifilm\Wikidata\Tests;

use Omnifilm\Testing\BareTestCase;
use Omnifilm\Wikidata\WikidataProvider;

/** omnifilm/wikidata's classes load with omniscient/omnifilm and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return WikidataProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
