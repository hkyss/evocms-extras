<?php

namespace hkyss\Extras\Tests\Unit\Manager;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Manager\ManagerModule;
use PHPUnit\Framework\TestCase;

class ManagerModuleTest extends TestCase
{
    public function testIdentifierIsDerivedFromTheNameTheCoreRegisters(): void
    {
        self::assertSame(md5(ManagerModule::NAME), ManagerModule::id());
        self::assertStringContainsString(ManagerModule::id(), ManagerModule::url());
    }

    public function testCarriesThePageAndTheAssetsItAsksFor(): void
    {
        self::assertFileExists(ManagerModule::file());
        self::assertNotSame('', ManagerModule::inline()['css']);
        self::assertNotSame('', ManagerModule::inline()['js']);
    }

    public function testAddsATopLevelEntryWithoutTouchingTheRest(): void
    {
        $menu = ManagerModule::promote(['site' => ['site', 'main'], 'modules' => ['modules', 'main']]);

        self::assertSame(['site', 'main'], $menu['site']);
        self::assertSame(['modules', 'main'], $menu['modules']);
        self::assertArrayHasKey('extras', $menu);
    }

    public function testTheEntrySitsInTheHeaderAndOpensTheModule(): void
    {
        $entry = ManagerModule::promote([])['extras'];

        self::assertSame('main', $entry[1]);
        self::assertSame(ManagerModule::url(), $entry[3]);
        self::assertStringContainsString(ManagerModule::NAME, $entry[2]);
        self::assertStringContainsString(ManagerModule::ICON, $entry[2]);
    }

    public function testTheEntryFollowsTheModulesTabItLeft(): void
    {
        self::assertSame(31, ManagerModule::promote([])['extras'][9]);
    }

    public function testKnowsThePackageItIsPartOf(): void
    {
        self::assertTrue(ManagerModule::isSelf(Coordinate::parse(ManagerModule::PACKAGE)));
        self::assertTrue(ManagerModule::isSelf(Coordinate::parse('HKYSS/EvoCMS-Extras')));
        self::assertFalse(ManagerModule::isSelf(Coordinate::parse('acme/thing')));
    }
}
