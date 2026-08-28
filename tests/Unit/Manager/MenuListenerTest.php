<?php

namespace hkyss\Extras\Tests\Unit\Manager;

use hkyss\Extras\Manager\ManagerModule;
use hkyss\Extras\Manager\MenuListener;
use PHPUnit\Framework\TestCase;

class MenuListenerTest extends TestCase
{
    public function testAnswersWithTheWholeMenuSerialized(): void
    {
        $answer = (new ReachableMenuListener())->handle(['menu' => ['site' => ['site', 'main']]]);

        self::assertIsString($answer);

        $menu = unserialize($answer, ['allowed_classes' => false]);

        self::assertArrayHasKey('site', $menu);
        self::assertArrayHasKey('extras', $menu);
    }

    public function testSaysNothingWhenTheModuleCannotBeReached(): void
    {
        self::assertNull((new MenuListener())->handle(['menu' => ['site' => ['site', 'main']]]));
    }

    public function testSaysNothingWithoutAMenuToAddTo(): void
    {
        self::assertNull((new ReachableMenuListener())->handle([]));
        self::assertNull((new ReachableMenuListener())->handle(['menu' => []]));
        self::assertNull((new ReachableMenuListener())->handle(['menu' => 'not an array']));
    }

    public function testTheEntryPointsAtTheRegisteredModule(): void
    {
        $answer = (string) (new ReachableMenuListener())->handle(['menu' => ['site' => ['site', 'main']]]);
        $menu = unserialize($answer, ['allowed_classes' => false]);

        self::assertSame(ManagerModule::url(), $menu['extras'][3]);
    }
}

class ReachableMenuListener extends MenuListener
{
    protected function isReachable(): bool
    {
        return true;
    }
}
