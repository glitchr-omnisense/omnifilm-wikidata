<?php

namespace Omnifilm\Wikidata;

use Omnifilm\Config;
use Omnifilm\Exception\InvalidConfigException;
use Omnifilm\Http\JsonClient;
use Omnifilm\ProviderFactory;
use Omnifilm\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "wikidata": options user_agent (required: Wikimedia's User-Agent policy
 * asks a name and a contact, "MySite/1.0 (https://example.org; films@example.org)"),
 * language ("en"), api (Wikidata's own; another Wikibase's), maxlag (0: not sent; set it
 * for batch jobs, as Wikimedia asks of non-interactive work - a page a
 * reader waits for would fail whenever the servers lag).
 */
final class WikidataFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnifilm.factory_name' => 'wikidata',
            'omnifilm.factory_title' => 'Wikidata',
            'omnifilm.required_options' => ['user_agent'],
            'api' => 'https://www.wikidata.org/w/api.php',
            'language' => 'en',
            'maxlag' => 0,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $agent = (string) $c->string('user_agent');
        if (!preg_match('~[/(]~', $agent)) {
            throw new InvalidConfigException('Wikidata needs a User-Agent naming the application and a way to reach its operator: "MySite/1.0 (https://example.org; films@example.org)".');
        }

        return new WikidataProvider(
            new JsonClient($this->http ?? HttpClient::create(['timeout' => 20]), 'Wikidata', ['User-Agent' => $agent, 'Accept' => 'application/json']),
            (string) $c->string('api'),
            (string) $c->string('language'),
            $c->int('maxlag'),
        );
    }
}
