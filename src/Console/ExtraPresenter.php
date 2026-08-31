<?php

namespace hkyss\Extras\Console;

use hkyss\Extras\Domain\CompatibilityStatus;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;

final class ExtraPresenter
{
    private const VERSIONS_SHOWN = 15;

    private const HINT_WIDTH = 48;

    private Ui $ui;

    public function __construct(Ui $ui)
    {
        $this->ui = $ui;
    }

    public function coordinateLabel(Coordinate $coordinate): string
    {
        return $this->ui->dim($coordinate->namespace() . '/') . $coordinate->name();
    }

    public function formatTag(ExtraFormat $format): string
    {
        return match ($format) {
            ExtraFormat::Composer => '<fg=cyan>' . $format->label() . '</>',
            ExtraFormat::Legacy => '<fg=magenta>' . $format->label() . '</>',
        };
    }

    public function compatibilityBadge(CompatibilityStatus $status): string
    {
        $colour = match ($status->level()) {
            'ok' => 'green',
            'fail' => 'red',
            default => 'yellow',
        };

        return $this->ui->mark($status->level()) . " <fg={$colour}>" . $status->label() . '</>';
    }

    public function installedLabel(InstalledState $state): string
    {
        return $state->isInstalled()
            ? '<fg=green>' . $state->describe() . '</>'
            : $this->ui->absent();
    }

    /** @return array{value:string,label:string,hint:string,search:string} */
    public function option(Extra $extra, string $hint = ''): array
    {
        if ($hint === '') {
            $hint = $this->ui->truncate($extra->description(), self::HINT_WIDTH);
        }

        return [
            'value' => (string) $extra->coordinate(),
            'label' => $this->coordinateLabel($extra->coordinate()),
            'hint' => trim($extra->format()->label() . '  ' . $hint),
            'search' => mb_strtolower($extra->coordinate() . ' ' . $extra->title() . ' ' . $extra->description()),
        ];
    }

    public function card(Extra $extra, InstalledState $state): void
    {
        $ui = $this->ui;
        $ui->heading((string) $extra->coordinate(), $extra->description());

        $rows = [
            ['Format', $this->formatTag($extra->format())],
            ['Source', $extra->sourceName() !== '' ? $extra->sourceName() : $ui->absent()],
            ['Author', $extra->author()],
            ['Latest', $extra->latestVersion() !== '' ? $extra->latestVersion() : $ui->absent()],
            ['Default ref', $extra->defaultVersion()],
            ['Installed', $state->isInstalled() ? $this->installedLabel($state) : $ui->dim('no')],
            ['Evo 3', $this->compatibilityBadge($extra->compatibility())],
        ];

        if ($extra->homepage() !== '') {
            $rows[] = ['Homepage', $ui->dim($extra->homepage())];
        }

        if ($extra->repository() !== '') {
            $rows[] = ['Repository', $ui->dim($extra->repository())];
        }

        $ui->details($rows);

        $this->versions($extra->versions());
        $this->requires($extra->requires());

        $ui->blank();
        $ui->note($extra->compatibility()->level(), $extra->compatibility()->explain());
    }

    /** @param list<string> $versions */
    private function versions(array $versions): void
    {
        if ($versions === []) {
            return;
        }

        $ui = $this->ui;
        $shown = array_slice($versions, 0, self::VERSIONS_SHOWN);
        $hidden = count($versions) - count($shown);

        $ui->blank();
        $ui->section('versions');

        foreach ($ui->wrap(implode(', ', $shown), $ui->width() - 2) as $line) {
            $ui->write('  ' . $line);
        }

        if ($hidden > 0) {
            $ui->write('  ' . $ui->dim(sprintf('%s and %d more', $ui->glyph('ellipsis'), $hidden)));
        }
    }

    /** @param array<string, string> $requires */
    private function requires(array $requires): void
    {
        if ($requires === []) {
            return;
        }

        $rows = [];

        foreach ($requires as $name => $constraint) {
            $rows[] = [(string) $name, $constraint];
        }

        $this->ui->blank();
        $this->ui->section('requires');
        $this->ui->details($rows);
    }
}
