<?php

namespace hkyss\Extras\Manager;

use hkyss\Extras\Catalog\CatalogSource;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;
use hkyss\Extras\Domain\WebUrl;
use hkyss\Extras\Installer\InstallerRegistry;
use hkyss\Extras\Record\InstallRecordStore;
use hkyss\Extras\Support\Paths;

/**
 * What the site itself can answer about an extra: the manifest, the install records and the
 * copy in vendor. Removal and the vendor tab go through here, so neither waits on the network.
 */
class InstalledExtras
{
    private InstallerRegistry $installers;
    private InstallRecordStore $records;
    private CatalogSource $snapshot;
    private ?Paths $paths;

    public function __construct(
        InstallerRegistry $installers,
        InstallRecordStore $records,
        CatalogSource $snapshot,
        ?Paths $paths = null
    ) {
        $this->installers = $installers;
        $this->records = $records;
        $this->snapshot = $snapshot;
        $this->paths = $paths;
    }

    /** @return array<string, array{state:InstalledState,format:ExtraFormat}> keyed by lowercase coordinate */
    public function map(): array
    {
        $map = [];

        foreach ($this->installers->installed() as $coordinate => $entry) {
            $map[strtolower((string) $coordinate)] = $entry;
        }

        return $map;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $rows = [];

        foreach ($this->installers->installed() as $coordinate => $entry) {
            $parsed = Coordinate::tryParse((string) $coordinate);

            if ($parsed === null || ManagerModule::isSelf($parsed)) {
                continue;
            }

            $extra = $this->extraFor($parsed);

            if ($extra !== null) {
                $rows[] = $this->describe($extra, $entry['state'], $this->snapshot->find($parsed) !== null);
            }
        }

        return $rows;
    }

    /** The extra an installer is handed: the installed format wins, whatever the snapshot says. */
    public function extraFor(Coordinate $coordinate): ?Extra
    {
        foreach ($this->installers->installed() as $installed => $entry) {
            $known = Coordinate::tryParse((string) $installed);

            if ($known === null || !$known->equals($coordinate)) {
                continue;
            }

            $listed = $this->snapshot->find($known);
            $data = $listed !== null ? $listed->toArray() : [];

            $data['coordinate'] = (string) $known;
            $data['format'] = $entry['format']->value;

            return Extra::fromArray($data);
        }

        return null;
    }

    /**
     * @param bool $listed whether the catalog carries it, or it is only here
     * @return array<string,mixed>
     */
    public function describe(Extra $extra, InstalledState $state, bool $listed): array
    {
        $coordinate = $extra->coordinate();
        $manifest = $state->isInstalled() && $extra->format() === ExtraFormat::Composer
            ? $this->vendorManifest($coordinate)
            : [];

        return [
            'coordinate' => (string) $coordinate,
            'format' => $extra->format()->value,
            'title' => $extra->title(),
            'description' => $this->pick($manifest['description'] ?? null, $extra->description()),
            'homepage' => $this->pick($manifest['homepage'] ?? null, $extra->homepage()),
            'repository' => $this->pick(WebUrl::from($manifest['support']['source'] ?? null), $extra->repository()),
            'author' => $extra->author(),
            'type' => (string) ($manifest['type'] ?? ''),
            'latest' => $extra->defaultVersion(),
            'versions' => $extra->versions(),
            'compatibility' => $extra->compatibility()->value,
            'installed' => $state->isInstalled(),
            'version' => $state->version(),
            'constraint' => $state->constraint(),
            'listed' => $listed,
        ] + $this->recorded($coordinate, $extra->format(), $state);
    }

    /** @return array<string,mixed> */
    private function recorded(Coordinate $coordinate, ExtraFormat $format, InstalledState $state): array
    {
        $empty = ['installed_at' => '', 'files' => 0, 'elements' => 0];

        if ($format !== ExtraFormat::Legacy || !$state->isInstalled()) {
            return $empty;
        }

        $record = $this->records->find($coordinate);

        if ($record === null) {
            return $empty;
        }

        return [
            'installed_at' => $record->installed_at?->format(DATE_ATOM) ?? '',
            'files' => count($record->fileList()),
            'elements' => count($record->elementList()),
        ];
    }

    /** @return array<string,mixed> */
    private function vendorManifest(Coordinate $coordinate): array
    {
        if ($this->paths === null) {
            return [];
        }

        $file = $this->paths->packageDir((string) $coordinate) . 'composer.json';

        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param mixed $preferred */
    private function pick($preferred, string $fallback): string
    {
        return is_string($preferred) && trim($preferred) !== '' ? trim($preferred) : $fallback;
    }
}
