<?php

namespace hkyss\Extras\Catalog;

use hkyss\Extras\Domain\CompatibilityStatus;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Support\Http;

class PackagistSource implements CatalogSource
{
    private const LIST_URL = 'https://packagist.org/packages/list.json';
    private const METADATA_URL = 'https://repo.packagist.org/p2/%s.json';
    private const METADATA_DEV_URL = 'https://repo.packagist.org/p2/%s~dev.json';

    private string $name;
    /** @var list<string> */
    private array $types;
    private Http $http;
    private CatalogCache $cache;
    private ?string $unavailable = null;
    /** @var list<Extra>|null */
    private ?array $extras = null;

    /** @param list<string> $types */
    public function __construct(Http $http, CatalogCache $cache, array $types, string $name = 'Packagist')
    {
        $this->http = $http;
        $this->cache = $cache;
        $this->types = $types;
        $this->name = $name;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function all(): array
    {
        if ($this->extras !== null) {
            return $this->extras;
        }

        $names = $this->cache->remember('packagist-names', function (): array {
            $collected = [];

            foreach ($this->types as $type) {
                $payload = $this->http->json(self::LIST_URL, ['type' => $type]);

                if ($payload === null) {
                    continue;
                }

                foreach ((array) ($payload['packageNames'] ?? []) as $name) {
                    if (is_string($name) && $name !== '') {
                        $collected[$name] = true;
                    }
                }
            }

            return array_keys($collected);
        });

        if ($names === []) {
            $this->unavailable = 'Packagist returned no packages for types ' . implode(', ', $this->types);
            $this->extras = [];

            return [];
        }

        $extras = [];

        foreach ($names as $name) {
            $coordinate = Coordinate::tryParse((string) $name);

            if ($coordinate === null) {
                continue;
            }

            $extra = $this->describe($coordinate);

            if ($extra !== null) {
                $extras[] = $extra;
            }
        }

        $this->extras = $extras;

        return $extras;
    }

    public function find(Coordinate $coordinate): ?Extra
    {
        return $this->describe($coordinate);
    }

    public function unavailableReason(): ?string
    {
        return $this->unavailable;
    }

    private function describe(Coordinate $coordinate): ?Extra
    {
        $releases = $this->releases($coordinate);

        if ($releases === []) {
            return null;
        }

        $versions = [];
        $latestStable = '';

        foreach ($releases as $release) {
            $version = (string) ($release['version'] ?? '');

            if ($version === '') {
                continue;
            }

            $versions[] = $version;

            if ($latestStable === '' && !str_starts_with($version, 'dev-')) {
                $latestStable = $version;
            }
        }

        $newest = $releases[0];

        return new Extra(
            $coordinate,
            ExtraFormat::Composer,
            (string) ($newest['name'] ?? $coordinate->name()),
            (string) ($newest['description'] ?? ''),
            $latestStable,
            $versions,
            '',
            CompatibilityStatus::Verified,
            $this->name,
            (string) ($newest['homepage'] ?? ''),
            $coordinate->namespace(),
            array_filter(
                (array) ($newest['require'] ?? []),
                static fn ($k) => $k !== 'php' && !str_starts_with((string) $k, 'ext-'),
                ARRAY_FILTER_USE_KEY
            )
        );
    }

    /**
     * Packagist keeps stable and dev releases in separate files, and a package with no
     * tags at all still serves an empty stable file.
     *
     * @return list<array<string,mixed>>
     */
    private function releases(Coordinate $coordinate): array
    {
        $stable = $this->cache->remember(
            'packagist-' . $coordinate->key(),
            fn () => $this->http->json(sprintf(self::METADATA_URL, $coordinate->key())) ?? []
        );

        $releases = $stable['packages'][$coordinate->key()] ?? [];

        if (is_array($releases) && $releases !== []) {
            return array_values($releases);
        }

        $dev = $this->cache->remember(
            'packagist-dev-' . $coordinate->key(),
            fn () => $this->http->json(sprintf(self::METADATA_DEV_URL, $coordinate->key())) ?? []
        );

        $releases = $dev['packages'][$coordinate->key()] ?? [];

        return is_array($releases) ? array_values($releases) : [];
    }
}
