<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Installer\PlatformCheck;
use PHPUnit\Framework\TestCase;

class PlatformCheckTest extends TestCase
{
    public function testUnsatisfiedConstraintIsReported(): void
    {
        $message = PlatformCheck::unmetPhpRequirement(['php' => '^8.3'], '8.2.4');

        self::assertNotNull($message);
        self::assertStringContainsString('^8.3', $message);
        self::assertStringContainsString('8.2.4', $message);
    }

    public function testSatisfiedConstraintSaysNothing(): void
    {
        self::assertNull(PlatformCheck::unmetPhpRequirement(['php' => '^8.2'], '8.2.4'));
        self::assertNull(PlatformCheck::unmetPhpRequirement(['php' => '>=8.0'], '8.2.4'));
        self::assertNull(PlatformCheck::unmetPhpRequirement(['php' => '^8.2 || ^8.3'], '8.2.4'));
    }

    public function testNoConstraintSaysNothing(): void
    {
        self::assertNull(PlatformCheck::unmetPhpRequirement([]));
        self::assertNull(PlatformCheck::unmetPhpRequirement(['vendor/pkg' => '^1.0']));
        self::assertNull(PlatformCheck::unmetPhpRequirement(['php' => '']));
    }

    public function testSuffixedRuntimeVersionsStillCompare(): void
    {
        self::assertNull(PlatformCheck::unmetPhpRequirement(['php' => '^8.2'], '8.2.4-dev'));
        self::assertNotNull(PlatformCheck::unmetPhpRequirement(['php' => '^8.3'], '8.2.4-1+ubuntu'));
    }

    public function testAnUnparseableConstraintIsNotTreatedAsAFailure(): void
    {
        self::assertNull(PlatformCheck::unmetPhpRequirement(['php' => 'not a constraint'], '8.2.4'));
    }

    public function testMissingExtensionsAreListed(): void
    {
        $missing = PlatformCheck::missingExtensions([
            'php' => '^8.2',
            'ext-json' => '*',
            'ext-definitely-not-loaded' => '*',
            'vendor/pkg' => '^1.0',
        ]);

        self::assertSame(['ext-definitely-not-loaded'], $missing);
    }

    public function testNoExtensionRequirements(): void
    {
        self::assertSame([], PlatformCheck::missingExtensions(['php' => '^8.2']));
    }
}
