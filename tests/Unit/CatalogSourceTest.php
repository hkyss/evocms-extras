<?php

namespace hkyss\Extras\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use hkyss\Extras\Catalog\CatalogCache;
use hkyss\Extras\Catalog\GitHubOrgSource;
use hkyss\Extras\Catalog\PackagistSource;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Support\Http;
use hkyss\Extras\Support\Paths;
use PHPUnit\Framework\TestCase;

/** Where the two links a row carries — the repository and the site — come from. */
class CatalogSourceTest extends TestCase
{
    /** @param array<string,mixed> $payload */
    private function http(array $payload): Http
    {
        $responses = [new Response(200, [], (string) json_encode($payload))];

        return new Http(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
    }

    private function cache(): CatalogCache
    {
        return new CatalogCache(new Paths(sys_get_temp_dir()), 3600, false);
    }

    public function testPackagistTakesTheRepositoryFromTheUrlComposerClones(): void
    {
        $http = $this->http(['packages' => ['acme/thing' => [[
            'name' => 'acme/thing',
            'version' => '1.0.0',
            'homepage' => 'https://acme.test',
            'source' => ['type' => 'git', 'url' => 'https://github.com/acme/thing.git'],
        ]]]]);

        $extra = (new PackagistSource($http, $this->cache(), []))->find(Coordinate::parse('acme/thing'));

        self::assertNotNull($extra);
        self::assertSame('https://github.com/acme/thing', $extra->repository());
        self::assertSame('https://acme.test', $extra->homepage());
    }

    public function testGitHubKeepsTheRepositoryApartFromTheSiteItPointsAt(): void
    {
        $http = $this->http([
            'name' => 'thing',
            'full_name' => 'acme/thing',
            'html_url' => 'https://github.com/acme/thing',
            'homepage' => 'https://acme.test',
            'default_branch' => 'main',
        ]);

        $extra = (new GitHubOrgSource($http, $this->cache(), 'acme'))->find(Coordinate::parse('acme/thing'));

        self::assertNotNull($extra);
        self::assertSame('https://github.com/acme/thing', $extra->repository());
        self::assertSame('https://acme.test', $extra->homepage());
    }

    /** GitHub keeps that field as it was typed, and a bare domain would link back into the manager. */
    public function testAHomepageNoBrowserCouldFollowIsDropped(): void
    {
        $http = $this->http([
            'name' => 'thing',
            'full_name' => 'acme/thing',
            'html_url' => 'https://github.com/acme/thing',
            'homepage' => 'acme.test',
        ]);

        $extra = (new GitHubOrgSource($http, $this->cache(), 'acme'))->find(Coordinate::parse('acme/thing'));

        self::assertNotNull($extra);
        self::assertSame('https://github.com/acme/thing', $extra->repository());
        self::assertSame('', $extra->homepage());
    }
}
