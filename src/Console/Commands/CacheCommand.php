<?php

namespace hkyss\Extras\Console\Commands;

use hkyss\Extras\Catalog\Catalog;
use hkyss\Extras\Catalog\CatalogCache;
use hkyss\Extras\Catalog\GitHubOrgSource;
use hkyss\Extras\Catalog\SnapshotSource;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Installer\InstallerRegistry;

class CacheCommand extends AbstractExtraCommand
{
    protected $signature = 'extra:cache
        {--clear : Drop every cached catalog response}
        {--rebuild-snapshot= : Regenerate the catalog snapshot into the given path}';

    protected $description = 'Inspect and manage the catalog cache';

    private CatalogCache $cache;

    public function __construct(Catalog $catalog, InstallerRegistry $installers, CatalogCache $cache)
    {
        parent::__construct($catalog, $installers);
        $this->cache = $cache;
    }

    public function handle(): int
    {
        $snapshotTarget = $this->option('rebuild-snapshot');

        if (is_string($snapshotTarget) && $snapshotTarget !== '') {
            return $this->rebuildSnapshot($snapshotTarget);
        }

        if ($this->option('clear')) {
            $this->info(sprintf('Cleared %d cached response(s).', $this->cache->clear()));

            return self::SUCCESS;
        }

        $stats = $this->cache->stats();

        $this->table([], [
            ['Enabled', $stats['enabled'] ? 'yes' : 'no'],
            ['Directory', $stats['directory']],
            ['Entries', (string) $stats['entries']],
            ['Expired', (string) $stats['expired']],
            ['Size', $this->humanBytes($stats['bytes'])],
        ]);

        return self::SUCCESS;
    }

    /** Walks every source with deep scanning, which needs GITHUB_PAT. */
    private function rebuildSnapshot(string $target): int
    {
        $this->line('Rebuilding the catalog snapshot. This walks every source and needs GITHUB_PAT.');
        $this->newLine();

        $known = [];

        foreach ((new SnapshotSource('previous', $target))->all() as $extra) {
            $known[$extra->coordinate()->key()] = $extra->compatibility();
        }

        $collected = [];

        foreach ($this->catalog->sources() as $source) {
            if ($source instanceof SnapshotSource) {
                continue;
            }

            $scanned = $source instanceof GitHubOrgSource ? $source->withDeepScan() : $source;
            $found = $scanned->all();

            $this->line(sprintf('  %-24s %d extra(s)', $source->name(), count($found)));

            $reason = $scanned->unavailableReason();

            if ($reason !== null) {
                $this->warn('    ' . $reason);
            }

            foreach ($found as $extra) {
                $key = $extra->coordinate()->key();

                if (!isset($collected[$key])) {
                    $collected[$key] = $extra;
                }
            }
        }

        if ($collected === []) {
            $this->error('No sources answered; the existing snapshot was left untouched.');

            return self::FAILURE;
        }

        $preserved = 0;
        $extras = [];

        foreach ($collected as $key => $extra) {
            if (isset($known[$key]) && $known[$key] !== $extra->compatibility()) {
                $extras[] = Extra::fromArray(['compatibility' => $known[$key]->value] + $extra->toArray());
                $preserved++;

                continue;
            }

            $extras[] = $extra;
        }

        $document = SnapshotSource::document($extras, gmdate('c'));
        $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false || @file_put_contents($target, $encoded . "\n") === false) {
            $this->error("Cannot write '{$target}'.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf(
            'Wrote %d extra(s) to %s (%d hand-set compatibility status(es) preserved).',
            count($extras),
            $target,
            $preserved
        ));

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
}
