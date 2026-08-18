<?php

namespace hkyss\Extras\Console\Commands;

use Symfony\Component\Console\Command\Command as SymfonyCommand;

class HelpCommand extends AbstractExtraCommand
{
    /** @var array<string, array{0:string,1:string}> */
    private const HINTS = [
        'extra:list' => ['browse the catalog', '--search=  --installed  --verified  --format='],
        'extra:info' => ['everything about one extra', '--format=json'],
        'extra:install' => ['install one or more', '--use-version=  --dry-run  --force'],
        'extra:update' => ['update what is installed', '--dry-run  --force'],
        'extra:remove' => ['remove what is installed', '--dry-run  --force'],
        'extra:doctor' => ['check this installation', ''],
        'extra:cache' => ['the catalog cache', '--clear  --rebuild-snapshot='],
    ];

    private const GLOSS_WIDTH = 28;

    protected $signature = 'extra:help';

    protected $description = 'What this package does, in one screen';

    public function handle(): int
    {
        $ui = $this->ui();

        $ui->heading('extras', 'install, update and remove Evolution CMS extras');

        $ui->section('commands');
        $ui->details($this->commands(), 2);

        $ui->blank();
        $ui->section('shared flags');
        $ui->details([
            ['--dry-run', 'print the plan, change nothing'],
            ['--force', 'proceed when the plan is blocked'],
            ['--file=', 'read coordinates from a file, one per line'],
            ['--continue-on-error', 'keep going after a failure'],
            ['--ignore-platform-reqs', 'install despite an unmet php or ext-* requirement'],
            ['--no-interaction', 'never ask; CI and pipes get this anyway'],
        ], 2);

        $ui->blank();
        $ui->section('interactive');
        $ui->write('  Run list, info, install, update or remove without arguments in a terminal');
        $ui->write('  and the command offers a list instead of failing.');
        $ui->blank();
        $ui->details([
            [$ui->glyph('updown') . ' type', 'move, then type to filter'],
            ['space enter', 'select several, confirm'],
            ['esc', 'clear the filter, then back out'],
        ], 2);

        $ui->footer([
            'coordinates are vendor/package',
            'EXTRAS_ASCII=1 for plain ASCII',
            'php artisan extra:NAME --help for every option',
        ]);

        return self::SUCCESS;
    }

    /** @return list<array{0:string,1:string}> */
    private function commands(): array
    {
        $application = $this->getApplication();
        $rows = [];

        foreach ($application === null ? [] : $application->all('extra') as $command) {
            $name = (string) $command->getName();

            if ($name === $this->getName() || $command->isHidden()) {
                continue;
            }

            $rows[] = [$name, $this->describe($command, $name)];
        }

        return $rows;
    }

    private function describe(SymfonyCommand $command, string $name): string
    {
        [$gloss, $flags] = self::HINTS[$name] ?? [$command->getDescription(), ''];

        return $flags === '' ? $gloss : $this->ui()->pad($gloss, self::GLOSS_WIDTH) . $this->ui()->dim($flags);
    }
}
