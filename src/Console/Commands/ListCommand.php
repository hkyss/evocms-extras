<?php

namespace hkyss\Extras\Console\Commands;

use hkyss\Extras\Domain\CompatibilityStatus;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;
use hkyss\Extras\Installer\Intent;

class ListCommand extends AbstractExtraCommand
{
    private const DESCRIPTION_WIDTH = 60;

    protected $signature = 'extra:list
        {--search= : Filter by coordinate, title or description}
        {--format=auto : auto, list, table or json}
        {--installed : Show only what is installed}
        {--legacy : Show only legacy extras}
        {--composer : Show only composer extras}
        {--verified : Show only extras verified against Evolution CMS 3}';

    protected $description = 'List available Evolution CMS extras';

    public static function resolveFormat(string $requested, bool $interactive): string
    {
        if ($requested === 'json') {
            return 'json';
        }

        if ($requested === 'list') {
            return $interactive ? 'list' : 'table';
        }

        if ($requested === 'auto') {
            return $interactive ? 'list' : 'table';
        }

        return 'table';
    }

    public function handle(): int
    {
        $extras = $this->spin(
            'loading catalog',
            fn () => $this->catalog->search((string) ($this->option('search') ?? ''), $this->formatFilter())
        );

        $matched = $this->option('installed')
            ? $this->installedEntries($extras)
            : $this->catalogEntries($extras);

        $rows = [];

        foreach ($matched as $entry) {
            $extra = $entry['extra'];
            $state = $entry['state'];
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

        $format = self::resolveFormat((string) ($this->option('format') ?? 'auto'), $this->isInteractive());

        if ($format === 'json') {
            $this->line((string) json_encode(
                ['count' => count($rows), 'extras' => $rows],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            return self::SUCCESS;
        }

        $ui = $this->ui();

        if ($matched === []) {
            $ui->note('warn', 'Nothing matched.');
            $this->reportCatalogProblems();

            return self::SUCCESS;
        }

        if ($format === 'list') {
            return $this->browse($matched);
        }

        $ui->blank();
        $ui->table(
            ['Extra', 'Format', 'Version', 'Installed', 'Evo 3', 'Description'],
            array_map(function (array $entry): array {
                $extra = $entry['extra'];
                $ui = $this->ui();

                return [
                    $this->presenter()->coordinateLabel($extra->coordinate()),
                    $this->presenter()->formatTag($extra->format()),
                    $extra->defaultVersion() !== '' ? $extra->defaultVersion() : $ui->absent(),
                    $this->presenter()->installedLabel($entry['state']),
                    $this->presenter()->compatibilityBadge($extra->compatibility()),
                    $ui->dim($ui->truncate($extra->description(), self::DESCRIPTION_WIDTH)),
                ];
            }, $matched)
        );

        $this->renderFooter($matched);
        $this->reportCatalogProblems();

        return self::SUCCESS;
    }

    /**
     * @param list<Extra> $extras
     * @return list<array{extra:Extra,state:InstalledState}>
     */
    private function catalogEntries(array $extras): array
    {
        $entries = [];

        foreach ($extras as $extra) {
            if ($this->option('verified') && $extra->compatibility() !== CompatibilityStatus::Verified) {
                continue;
            }

            $entries[] = ['extra' => $extra, 'state' => $this->installers->stateOf($extra)];
        }

        return $entries;
    }

    /**
     * @param list<Extra> $extras
     * @return list<array{extra:Extra,state:InstalledState}>
     */
    private function installedEntries(array $extras): array
    {
        $known = [];

        foreach ($extras as $extra) {
            $known[(string) $extra->coordinate()] = $extra;
        }

        $entries = [];

        foreach ($this->installers->installed() as $coordinate => $record) {
            $extra = $known[$coordinate] ?? $this->describeUnlisted($coordinate, $record['format']);

            if ($extra === null) {
                continue;
            }

            if ($this->option('verified') && $extra->compatibility() !== CompatibilityStatus::Verified) {
                continue;
            }

            $entries[] = ['extra' => $extra, 'state' => $record['state']];
        }

        return $entries;
    }

    private function describeUnlisted(string $coordinate, ExtraFormat $format): ?Extra
    {
        $parsed = Coordinate::tryParse($coordinate);

        if ($parsed === null) {
            return null;
        }

        return $this->catalog->find($parsed) ?? Extra::fromArray([
            'coordinate' => $coordinate,
            'format' => $format->value,
            'description' => 'installed; not listed in the catalog',
            'compatibility' => CompatibilityStatus::Unknown->value,
            'source' => '',
        ]);
    }

    /** @param list<array{extra:Extra,state:InstalledState}> $matched */
    private function browse(array $matched): int
    {
        $rows = [];
        $byCoordinate = [];

        foreach ($matched as $entry) {
            $extra = $entry['extra'];
            $state = $entry['state'];

            $rows[] = $this->optionFor($extra, $state->isInstalled() ? 'installed ' . $state->describe() : '');
            $byCoordinate[(string) $extra->coordinate()] = $entry;
        }

        $title = $this->plural(count($rows), 'extra', 'extras');

        while (true) {
            $chosen = $this->choose($title, $rows, false);

            if ($chosen === null || $chosen === []) {
                return self::SUCCESS;
            }

            $entry = $byCoordinate[$chosen[0]] ?? null;

            if ($entry === null) {
                return self::SUCCESS;
            }

            $this->presenter()->card($entry['extra'], $entry['state']);

            $status = $this->act($entry);

            if ($status !== null) {
                return $status;
            }

            $this->ui()->blank();
        }
    }

    /**
     * @param array{extra:Extra,state:InstalledState} $entry
     * @return int|null
     */
    private function act(array $entry): ?int
    {
        $extra = $entry['extra'];
        $state = $entry['state'];
        $actions = [];

        if ($state->isInstalled()) {
            $actions[] = ['value' => 'update', 'hint' => $state->describe() . ' installed'];
            $actions[] = ['value' => 'remove', 'hint' => $state->describe() . ' installed'];
        } else {
            $actions[] = ['value' => 'install', 'hint' => $extra->defaultVersion()];
        }

        $actions[] = ['value' => 'back', 'label' => 'back to the list', 'hint' => ''];

        $chosen = $this->choose((string) $extra->coordinate(), $actions, false);
        $action = $chosen === null ? 'back' : ($chosen[0] ?? 'back');

        if ($action === 'back') {
            return null;
        }

        return $this->runOne($extra, match ($action) {
            'install' => Intent::Install,
            'update' => Intent::Update,
            default => Intent::Remove,
        }, '', false, false);
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

    /** @param list<array{extra:Extra,state:InstalledState}> $matched */
    private function renderFooter(array $matched): void
    {
        $ui = $this->ui();
        $installed = 0;
        $unverified = 0;

        foreach ($matched as $entry) {
            if ($entry['state']->isInstalled()) {
                $installed++;
            }

            if ($entry['extra']->compatibility() !== CompatibilityStatus::Verified) {
                $unverified++;
            }
        }

        $ui->footer([
            $this->plural(count($matched), 'extra', 'extras'),
            $installed > 0 ? $installed . ' installed' : '',
            $unverified > 0 ? $unverified . ' unverified' : '',
            $this->plural(count($this->catalog->sources()), 'source', 'sources'),
        ]);

        if ($unverified === 0) {
            return;
        }

        $ui->blank();
        $ui->note('warn', sprintf(
            '%s not verified against Evolution CMS 3. Most legacy extras were written for '
            . 'MODX Evolution 1.x; installing them needs --force.',
            $unverified === 1 ? 'One extra is' : $unverified . ' extras are'
        ));
    }

    private function plural(int $count, string $one, string $many): string
    {
        return $count . ' ' . ($count === 1 ? $one : $many);
    }
}
