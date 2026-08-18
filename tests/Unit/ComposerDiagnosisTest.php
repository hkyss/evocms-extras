<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Installer\ComposerDiagnosis;
use PHPUnit\Framework\TestCase;

class ComposerDiagnosisTest extends TestCase
{
    /** @return list<string> */
    private static function phpVersionFailure(): array
    {
        return [
            'Loading composer repositories with package information',
            'Updating dependencies',
            'Your requirements could not be resolved to an installable set of packages.',
            '  Problem 1',
            '    - Root composer.json requires evolution-cms/etinymce 8.3.2 -> satisfiable by '
                . 'evolution-cms/etinymce[8.3.2].',
            '    - evolution-cms/etinymce 8.3.2 requires php ^8.3 -> your php version (8.2.4) '
                . 'does not satisfy that requirement.',
        ];
    }

    public function testItNamesThePhpConstraintAndTheRunningVersion(): void
    {
        $notes = ComposerDiagnosis::explain(self::phpVersionFailure());

        self::assertCount(1, $notes);
        self::assertStringContainsString('evolution-cms/etinymce 8.3.2', $notes[0]);
        self::assertStringContainsString('PHP ^8.3', $notes[0]);
        self::assertStringContainsString('8.2.4', $notes[0]);
    }

    public function testMissingExtension(): void
    {
        $notes = ComposerDiagnosis::explain([
            'Your requirements could not be resolved to an installable set of packages.',
            '    - vendor/thing 2.0.0 requires ext-imagick * -> it is missing from your system. '
                . 'Install or enable PHP\'s imagick extension.',
        ]);

        self::assertCount(1, $notes);
        self::assertStringContainsString('ext-imagick', $notes[0]);
        self::assertStringContainsString('vendor/thing 2.0.0', $notes[0]);
    }

    public function testPackageMissingFromPackagist(): void
    {
        $notes = ComposerDiagnosis::explain([
            '    - Root composer.json requires evocms-community/ghost, it could not be found in any version, '
                . 'there may be a typo in the package name.',
        ]);

        self::assertCount(1, $notes);
        self::assertStringContainsString('evocms-community/ghost', $notes[0]);
    }

    public function testAnOrdinaryConflictIsNotParaphrased(): void
    {
        $notes = ComposerDiagnosis::explain([
            'Your requirements could not be resolved to an installable set of packages.',
            '  Problem 1',
            '    - vendor/a 1.0.0 requires vendor/b ^2.0 -> found vendor/b[1.5.0] but it does not '
                . 'match the constraint.',
        ]);

        self::assertSame([], $notes);
    }

    public function testSuccessfulOutputSaysNothing(): void
    {
        self::assertSame([], ComposerDiagnosis::explain([
            'Loading composer repositories with package information',
            'Updating dependencies',
            'Lock file operations: 1 install, 0 updates, 0 removals',
            'Writing lock file',
        ]));
    }

    public function testEmptyOutput(): void
    {
        self::assertSame([], ComposerDiagnosis::explain([]));
    }

    public function testRepeatedCausesAreCollapsed(): void
    {
        $notes = ComposerDiagnosis::explain(array_merge(
            self::phpVersionFailure(),
            self::phpVersionFailure()
        ));

        self::assertCount(1, $notes);
    }
}
