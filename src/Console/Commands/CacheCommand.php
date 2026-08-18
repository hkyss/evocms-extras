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

        $ui = $this->ui();

        if ($this->option('clear')) {
            $entries = $this->cache->stats()['entries'];

            if ($entries > 0 && $this->isInteractive()
                && !$this->confirmStep(sprintf('Drop %d cached response(s)?', $entries))) {
                $ui->note('warn', 'Cancelled, the cache is untouched.');

                return self::SUCCESS;
            }

            $ui->banner(true, sprintf('Cleared %d cached response(s).', $this->cache->clear()));

            return self::SUCCESS;
        }

        $stats = $this->cache->stats();

        $ui->heading('extra:cache', 'catalog responses kept on disk');
        $ui->details([
            [
                'Enabled',
                $stats['enabled'] ? '<fg=green>yes</>' : '<fg=yellow>no</>',
            ],
            ['Directory', $ui->dim($stats['directory'])],
            ['Entries', (string) $stats['entries']],
            [
                'Expired',
                $stats['expired'] > 0 ? '<fg=yellow>' . $stats['expired'] . '</>' : '0',
            ],
            ['Size', $this->humanBytes($stats['bytes'])],
        ]);

        return self::SUCCESS;
    }

    private function rebuildSnapshot(string $target): int
    {
        $ui = $this->ui();
        $ui->heading(
            'extra:cache --rebuild-snapshot',
            'walks every source and needs GITHUB_PAT'
        );

        if (is_file($target) && $this->isInteractive()
            && !$this->confirmStep("Overwrite the snapshot at {$target}?")) {
            $ui->note('warn', 'Cancelled, the snapshot is untouched.');

            return self::SUCCESS;
        }

        $known = [];

        foreach ((new SnapshotSource('previous', $target))->all() as $extra) {
            $known[$extra->coordinate()->key()] = $extra->compatibility();
        }

        $collected = [];
        $nameWidth = 0;

        foreach ($this->catalog->sources() as $source) {
            $nameWidth = max($nameWidth, mb_strlen($source->name()));
        }

        foreach ($this->catalog->sources() as $source) {
            if ($source instanceof SnapshotSource) {
                continue;
            }

            $scanned = $source instanceof GitHubOrgSource ? $source->withDeepScan() : $source;
            $found = $scanned->all();
            $reason = $scanned->unavailableReason();

            $ui->checks([[
                'level' => $reason === null ? 'ok' : 'warn',
                'name' => str_pad($source->name(), $nameWidth),
                'detail' => $reason === null
                    ? sprintf('%d extra(s)', count($found))
                    : sprintf('%d extra(s); %s', count($found), $reason),
            ]]);

            foreach ($found as $extra) {
                $key = $extra->coordinate()->key();

                if (!isset($collected[$key])) {
                    $collected[$key] = $extra;
                }
            }
        }

        if ($collected === []) {
            return $this->bail('No sources answered; the existing snapshot was left untouched.');
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
            return $this->bail("Cannot write '{$target}'.");
        }

        $ui->banner(true, sprintf('Wrote %d extra(s) to %s.', count($extras), $target));
        $ui->write('  ' . $ui->dim(sprintf(
            '%d hand-set compatibility status(es) preserved.',
            $preserved
        )));

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
