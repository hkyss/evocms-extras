<?php

namespace hkyss\Extras\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

class Http
{
    private Client $client;
    private ?string $githubToken;

    public function __construct(?Client $client = null, ?string $githubToken = null, int $timeout = 30)
    {
        $this->githubToken = $githubToken !== null && trim($githubToken) !== '' ? trim($githubToken) : null;
        $this->client = $client ?? new Client([
            RequestOptions::TIMEOUT => $timeout,
            RequestOptions::HEADERS => [
                'User-Agent' => 'hkyss-evocms-extras',
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function hasGithubToken(): bool
    {
        return $this->githubToken !== null;
    }

    /**
     * @param array<string,scalar> $query
     * @return array<mixed>|null Null on any failure; catalog sources must survive a dead network.
     */
    public function json(string $url, array $query = [], bool $github = false): ?array
    {
        $options = [];

        if ($query !== []) {
            $options[RequestOptions::QUERY] = $query;
        }

        if ($github) {
            $options[RequestOptions::HEADERS] = ['Accept' => 'application/vnd.github+json']
                + ($this->githubToken !== null ? ['Authorization' => 'Bearer ' . $this->githubToken] : []);
        }

        try {
            $response = $this->client->get($url, $options);
        } catch (GuzzleException) {
            return null;
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function body(string $url, bool $github = false): ?string
    {
        $options = [];

        if ($github && $this->githubToken !== null) {
            $options[RequestOptions::HEADERS] = ['Authorization' => 'Bearer ' . $this->githubToken];
        }

        try {
            return (string) $this->client->get($url, $options)->getBody();
        } catch (GuzzleException) {
            return null;
        }
    }

    public function download(string $url, string $destination, bool $github = false): bool
    {
        $options = [RequestOptions::SINK => $destination];

        if ($github && $this->githubToken !== null) {
            $options[RequestOptions::HEADERS] = ['Authorization' => 'Bearer ' . $this->githubToken];
        }

        try {
            $this->client->get($url, $options);
        } catch (GuzzleException) {
            @unlink($destination);

            return false;
        }

        return is_file($destination) && filesize($destination) > 0;
    }
}
