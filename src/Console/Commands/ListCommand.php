<?php

namespace hkyss\Extras\Console\Commands;

use hkyss\Extras\Domain\CompatibilityStatus;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;

class ListCommand extends AbstractExtraCommand
{
    protected $signature = 'extra:list
        {--search= : Filter by coordinate, title or description}
        {--format=table : table or json}
        {--installed : Show only what is installed}
        {--legacy : Show only legacy extras}
        {--composer : Show only composer extras}
        {--verified : Show only extras verified against Evolution CMS 3}';

    protected $description = 'List available Evolution CMS extras';

    public function handle(): int
    {
        $extras = $this->catalog->search((string) ($this->option('search') ?? ''), $this->formatFilter());
        $rows = [];

        foreach ($extras as $extra) {
            $state = $this->installers->stateOf($extra);

            if ($this->option('installed') && !$state->isInstalled()) {
                continue;
            }

            if ($this->option('verified') && $extra->compatibility() !== CompatibilityStatus::Verified) {
                continue;
            }

            $rows[] = [
                'coordinate' => (string) $extra->coordinate(),
                'format' => $extra->format()->label(),
                'version' => $extra->defaultVersion(),
                'installed' => $state->describe(),
                'compatibility' => $extra->compatibility()->value,
                'description' => $extra->shortDescription(),
                'source' => $extra->sourceName(),
            ];
        }

        if ((string) $this->option('format') === 'json') {
            $this->line((string) json_encode(
                ['count' => count($rows), 'extras' => $rows],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('Nothing matched.');
            $this->reportCatalogProblems();

            return self::SUCCESS;
        }

        $this->table(
            ['Extra', 'Format', 'Version', 'Installed', 'Evo 3', 'Description'],
            array_map(static fn (array $row): array => [
                $row['coordinate'],
                $row['format'],
                $row['version'] !== '' ? $row['version'] : '—',
                $row['installed'],
                CompatibilityStatus::from($row['compatibility'])->tag(),
                $row['description'],
            ], $rows)
        );

        $this->line(sprintf('<fg=gray>%d extra(s)</>', count($rows)));
        $this->explainUnverified($extras);
        $this->reportCatalogProblems();

        return self::SUCCESS;
    }

    private function formatFilter(): ?ExtraFormat
    {
        if ($this->option('legacy') && !$this->option('composer')) {
            return ExtraFormat::Legacy;
        }

        if ($this->option('composer') && !$this->option('legacy')) {
            return ExtraFormat::Composer;
        }

        return null;
    }

    /** @param list<Extra> $extras */
    private function explainUnverified(array $extras): void
    {
        $unverified = 0;

        foreach ($extras as $extra) {
            if ($extra->compatibility() !== CompatibilityStatus::Verified) {
                $unverified++;
            }
        }

        if ($unverified === 0) {
            return;
        }

        $this->newLine();
        $this->line(sprintf(
            '<comment>%d extra(s) are not verified against Evolution CMS 3.</comment> '
            . 'Most legacy extras were written for MODX Evolution 1.x; installing them needs <info>--force</info>.',
            $unverified
        ));
    }
}
