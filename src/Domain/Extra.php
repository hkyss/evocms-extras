<?php

namespace hkyss\Extras\Domain;

final class Extra
{
    private Coordinate $coordinate;
    private ExtraFormat $format;
    private string $title;
    private string $description;
    private string $latestVersion;
    /** @var list<string> */
    private array $versions;
    private string $defaultBranch;
    private CompatibilityStatus $compatibility;
    private string $sourceName;
    private string $homepage;
    private string $author;
    /** @var array<string,string> */
    private array $requires;

    /**
     * @param list<string>         $versions
     * @param array<string,string> $requires
     */
    public function __construct(
        Coordinate $coordinate,
        ExtraFormat $format,
        string $title = '',
        string $description = '',
        string $latestVersion = '',
        array $versions = [],
        string $defaultBranch = '',
        ?CompatibilityStatus $compatibility = null,
        string $sourceName = '',
        string $homepage = '',
        string $author = '',
        array $requires = []
    ) {
        $this->coordinate = $coordinate;
        $this->format = $format;
        $this->title = $title !== '' ? $title : $coordinate->name();
        $this->description = $description;
        $this->latestVersion = $latestVersion;
        $this->versions = array_values(array_unique(array_filter(
            $versions,
            static fn ($v) => is_string($v) && $v !== ''
        )));
        $this->defaultBranch = $defaultBranch;
        $this->compatibility = $compatibility ?? CompatibilityStatus::forFormat($format);
        $this->sourceName = $sourceName;
        $this->homepage = $homepage;
        $this->author = $author !== '' ? $author : $coordinate->namespace();
        $this->requires = $requires;
    }

    public function coordinate(): Coordinate
    {
        return $this->coordinate;
    }

    public function format(): ExtraFormat
    {
        return $this->format;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function shortDescription(int $limit = 60): string
    {
        if (mb_strlen($this->description) <= $limit) {
            return $this->description;
        }

        return mb_substr($this->description, 0, $limit - 1) . '…';
    }

    /** Legacy extras often have no tags at all, so the default branch is the last resort. */
    public function defaultVersion(): string
    {
        if ($this->latestVersion !== '') {
            return $this->latestVersion;
        }

        if ($this->versions !== []) {
            return $this->versions[0];
        }

        return $this->defaultBranch !== '' ? $this->defaultBranch : 'master';
    }

    public function latestVersion(): string
    {
        return $this->latestVersion;
    }

    /** @return list<string> */
    public function versions(): array
    {
        return $this->versions;
    }

    public function defaultBranch(): string
    {
        return $this->defaultBranch;
    }

    public function compatibility(): CompatibilityStatus
    {
        return $this->compatibility;
    }

    public function sourceName(): string
    {
        return $this->sourceName;
    }

    public function homepage(): string
    {
        return $this->homepage;
    }

    public function author(): string
    {
        return $this->author;
    }

    /** @return array<string,string> */
    public function requires(): array
    {
        return $this->requires;
    }

    public function matches(string $needle): bool
    {
        $needle = mb_strtolower(trim($needle));

        if ($needle === '') {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', [
            (string) $this->coordinate,
            $this->title,
            $this->description,
        ]));

        return str_contains($haystack, $needle);
    }

    public function fromSource(string $sourceName): self
    {
        $clone = clone $this;
        $clone->sourceName = $sourceName;

        return $clone;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'coordinate' => (string) $this->coordinate,
            'format' => $this->format->value,
            'title' => $this->title,
            'description' => $this->description,
            'version' => $this->defaultVersion(),
            'latest_release' => $this->latestVersion,
            'versions' => $this->versions,
            'default_branch' => $this->defaultBranch,
            'compatibility' => $this->compatibility->value,
            'source' => $this->sourceName,
            'homepage' => $this->homepage,
            'author' => $this->author,
            'require' => $this->requires,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Coordinate::parse((string) ($data['coordinate'] ?? '')),
            ExtraFormat::from((string) ($data['format'] ?? ExtraFormat::Legacy->value)),
            (string) ($data['title'] ?? ''),
            (string) ($data['description'] ?? ''),
            (string) ($data['latest_release'] ?? ''),
            array_values((array) ($data['versions'] ?? [])),
            (string) ($data['default_branch'] ?? ''),
            CompatibilityStatus::fromNullable($data['compatibility'] ?? null),
            (string) ($data['source'] ?? ''),
            (string) ($data['homepage'] ?? ''),
            (string) ($data['author'] ?? ''),
            (array) ($data['require'] ?? [])
        );
    }
}
