<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Installer\InstallPlan;
use hkyss\Extras\Installer\Intent;
use PHPUnit\Framework\TestCase;

class InstallPlanTest extends TestCase
{
    private function plan(): InstallPlan
    {
        return new InstallPlan(
            Coordinate::parse('vendor/package'),
            ExtraFormat::Composer,
            Intent::Install
        );
    }

    public function testAPlanWithoutObjectionsIsNotBlocked(): void
    {
        $plan = $this->plan();

        self::assertFalse($plan->isBlocked());
        self::assertFalse($plan->isForbidden());
    }

    public function testABlockerIsBlockingButNotForbidding(): void
    {
        $plan = $this->plan()->block('not installed');

        self::assertTrue($plan->isBlocked());
        self::assertFalse($plan->isForbidden());
        self::assertSame(['not installed'], $plan->blockers());
        self::assertSame([], $plan->forbidden());
    }

    public function testSomethingForbiddenAlsoBlocks(): void
    {
        $plan = $this->plan()->forbid('needs PHP ^8.3');

        self::assertTrue($plan->isBlocked(), '--force checks isBlocked; a forbidden plan must stop there too');
        self::assertTrue($plan->isForbidden());
        self::assertSame(['needs PHP ^8.3'], $plan->forbidden());
        self::assertSame([], $plan->blockers(), 'the two kinds must stay apart');
    }

    public function testReasonsAreNotRepeated(): void
    {
        $plan = $this->plan()->forbid('needs PHP ^8.3')->forbid('needs PHP ^8.3')->block('x')->block('x');

        self::assertSame(['needs PHP ^8.3'], $plan->forbidden());
        self::assertSame(['x'], $plan->blockers());
    }

    public function testBothKindsAreSerialised(): void
    {
        $array = $this->plan()->block('soft')->forbid('hard')->toArray();

        self::assertSame(['soft'], $array['blockers']);
        self::assertSame(['hard'], $array['forbidden']);
    }
}
