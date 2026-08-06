<?php

namespace hkyss\Extras\Console\Commands;

class InfoCommand extends AbstractExtraCommand
{
    protected $signature = 'extra:info
        {coordinate : vendor/package or org/repo}
        {--format=text : text or json}';

    protected $description = 'Show everything known about an extra';

    public function handle(): int
    {
        $extra = $this->resolve((string) $this->argument('coordinate'));

        if ($extra === null) {
            return self::FAILURE;
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

        $this->newLine();
        $this->line('<options=bold>' . $extra->coordinate() . '</>');

        if ($extra->description() !== '') {
            $this->line($extra->description());
        }

        $this->newLine();

        $rows = [
            ['Format', $extra->format()->label()],
            ['Source', $extra->sourceName() !== '' ? $extra->sourceName() : '—'],
            ['Author', $extra->author()],
            ['Latest', $extra->latestVersion() !== '' ? $extra->latestVersion() : '—'],
            ['Default ref', $extra->defaultVersion()],
            ['Installed', $state->isInstalled() ? $state->describe() : 'no'],
            ['Evo 3', $extra->compatibility()->tag()],
        ];

        if ($extra->homepage() !== '') {
            $rows[] = ['Homepage', $extra->homepage()];
        }

        $this->table([], $rows);

        if ($extra->versions() !== []) {
            $this->line('<fg=cyan>versions</>');
            $this->line('  ' . implode(', ', array_slice($extra->versions(), 0, 15)));

            if (count($extra->versions()) > 15) {
                $this->line(sprintf('  <fg=gray>… and %d more</>', count($extra->versions()) - 15));
            }

            $this->newLine();
        }

        if ($extra->requires() !== []) {
            $this->line('<fg=cyan>requires</>');

            foreach ($extra->requires() as $name => $constraint) {
                $this->line("  {$name}: {$constraint}");
            }

            $this->newLine();
        }

        $this->line('<fg=gray>' . $extra->compatibility()->explain() . '</>');

        return self::SUCCESS;
    }
}
