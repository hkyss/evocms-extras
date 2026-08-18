<?php

namespace hkyss\Extras\Console\Commands;

use hkyss\Extras\Domain\Extra;

class InfoCommand extends AbstractExtraCommand
{
    protected $signature = 'extra:info
        {coordinate? : vendor/package or org/repo; omit to pick from the catalog}
        {--format=text : text or json}';

    protected $description = 'Show everything known about an extra';

    public function handle(): int
    {
        $coordinate = $this->argument('coordinate');
        $extra = is_string($coordinate) && $coordinate !== ''
            ? $this->resolve($coordinate)
            : $this->pick();

        if ($extra === null) {
            return $this->failedToChoose($coordinate);
        }

        $state = $this->installers->stateOf($extra);

        if ((string) $this->option('format') === 'json') {
            $this->line((string) json_encode(
                $extra->toArray() + [
                    'installed' => $state->isInstalled(),
                    'installed_version' => $state->version(),
                    'constraint' => $state->constraint(),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            return self::SUCCESS;
        }

        $this->presenter()->card($extra, $state);

        return self::SUCCESS;
    }

    private function failedToChoose(mixed $coordinate): int
    {
        if (is_string($coordinate) && $coordinate !== '') {
            return self::FAILURE;
        }

        if ($this->isInteractive()) {
            return self::SUCCESS;
        }

        return $this->bail('Which extra? Pass a coordinate, or run this in a terminal to pick one.');
    }

    private function pick(): ?Extra
    {
        if (!$this->isInteractive()) {
            return null;
        }

        $extras = $this->spin('loading catalog', fn () => $this->catalog->all());

        if ($extras === []) {
            $this->reportCatalogProblems();

            return null;
        }

        $rows = [];
        $byCoordinate = [];

        foreach ($extras as $extra) {
            $state = $this->installers->stateOf($extra);

            $rows[] = $this->optionFor(
                $extra,
                $state->isInstalled() ? 'installed ' . $state->describe() : ''
            );

            $byCoordinate[(string) $extra->coordinate()] = $extra;
        }

        $chosen = $this->choose('Which extra?', $rows, false);

        return $chosen === null || $chosen === [] ? null : ($byCoordinate[$chosen[0]] ?? null);
    }
}
