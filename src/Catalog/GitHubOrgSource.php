<?php

namespace hkyss\Extras\Catalog;

use hkyss\Extras\Domain\CompatibilityStatus;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Support\Http;

class GitHubOrgSource implements CatalogSource
{
    private const API = 'https://api.github.com';

    private string $name;
    private string $organization;
    private Http $http;
    private CatalogCache $cache;
    private ?string $unavailable = null;
    /** @var list<Extra>|null */
    private ?array $extras = null;
    private bool $deep;

    public function __construct(
        Http $http,
        CatalogCache $cache,
        string $organization,
        string $name = '',
        bool $deep = false
    ) {
        $this->http = $http;
        $this->cache = $cache;
        $this->organization = $organization;
        $this->name = $name !== '' ? $name : $organization;
        $this->deep = $deep;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Also fetches tags per repository, which costs one extra request each. */
    public function withDeepScan(): self
    {
        $clone = clone $this;
        $clone->deep = true;
        $clone->extras = null;

        return $clone;
    }

    public function all(): array
    {
        if ($this->extras !== null) {
            return $this->extras;
        }

        $repos = $this->repositories();

        if ($repos === []) {
            $this->extras = [];

            return [];
        }

        $extras = [];

        foreach ($repos as $repo) {
            $extra = $this->toExtra($repo);

            if ($extra !== null) {
                $extras[] = $extra;
            }
        }

        $this->extras = $extras;

        return $extras;
    }

    public function find(Coordinate $coordinate): ?Extra
    {
        if (strcasecmp($coordinate->namespace(), $this->organization) !== 0) {
            return null;
        }

        $repo = $this->cache->remember(
            'github-repo-' . $coordinate->key(),
            fn () => $this->http->json(self::API . '/repos/' . $coordinate, [], true) ?? []
        );

        if (!isset($repo['name'])) {
            return null;
        }

        return $this->toExtra($repo);
    }

    public function unavailableReason(): ?string
    {
        $this->all();

        return $this->unavailable;
    }

    /** @return list<array<string,mixed>> */
    private function repositories(): array
    {
        $cached = $this->cache->remember('github-org-' . strtolower($this->organization), function (): array {
            $all = [];

            for ($page = 1; $page <= 4; $page++) {
                $batch = $this->http->json(
                    self::API . '/orgs/' . $this->organization . '/repos',
                    ['per_page' => 100, 'page' => $page, 'sort' => 'pushed'],
                    true
                );

                if (!is_array($batch) || $batch === [] || isset($batch['message'])) {
                    break;
                }

                foreach ($batch as $repo) {
                    if (is_array($repo)) {
                        $all[] = $repo;
                    }
                }

                if (count($batch) < 100) {
                    break;
                }
            }

            return $all;
        });

        if ($cached === []) {
            $this->unavailable = $this->http->hasGithubToken()
                ? "GitHub returned nothing for organisation '{$this->organization}'"
                : "GitHub returned nothing for organisation '{$this->organization}'; "
                    . 'anonymous requests are capped at 60 per hour, set GITHUB_PAT';

            return [];
        }

        return $cached;
    }

    /** @param array<string,mixed> $repo */
    private function toExtra(array $repo): ?Extra
    {
        $name = (string) ($repo['name'] ?? '');
        $fullName = (string) ($repo['full_name'] ?? '');

        if ($name === '' || $fullName === '' || !empty($repo['archived'])) {
            return null;
        }

        $coordinate = Coordinate::tryParse($fullName);

        if ($coordinate === null) {
            return null;
        }

        $versions = $this->deep ? $this->tags($coordinate) : [];

        return new Extra(
            $coordinate,
            ExtraFormat::Legacy,
            $name,
            (string) ($repo['description'] ?? ''),
            $versions[0] ?? '',
            $versions,
            (string) ($repo['default_branch'] ?? 'master'),
            CompatibilityStatus::Unknown,
            $this->name,
            (string) ($repo['html_url'] ?? ''),
            $coordinate->namespace()
        );
    }

    /** @return list<string> */
    private function tags(Coordinate $coordinate): array
    {
        $payload = $this->cache->remember(
            'github-tags-' . $coordinate->key(),
            fn () => $this->http->json(self::API . '/repos/' . $coordinate . '/tags', ['per_page' => 20], true) ?? []
        );

        $tags = [];

        foreach ($payload as $tag) {
            $tagName = is_array($tag) ? (string) ($tag['name'] ?? '') : '';

            if ($tagName !== '') {
                $tags[] = $tagName;
            }
        }

        return array_values(array_unique($tags));
    }
}
