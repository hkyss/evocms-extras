<?php

namespace hkyss\Extras\Tests\Unit\Manager;

use hkyss\Extras\Manager\ManagerModule;
use PHPUnit\Framework\TestCase;

class ModulePageTest extends TestCase
{
    private function page(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/resources/views/module.blade.php');
    }

    public function testTheManagerIsHandedTheViewAndNothingElse(): void
    {
        $entry = (string) file_get_contents(ManagerModule::file());

        self::assertStringContainsString('IN_MANAGER_MODE', $entry);
        self::assertStringContainsString("view('extras::module')", $entry);
    }

    public function testTheLookComesFromTheManagerRatherThanAssetsOfItsOwn(): void
    {
        self::assertStringContainsString('media/style/default/css/styles.min.css', $this->page());
        self::assertStringContainsString('media/script/tabpane.js', $this->page());
        self::assertStringContainsString('media/script/jquery/jquery.min.js', $this->page());
        self::assertStringContainsString('<body class="module">', $this->page());
    }

    public function testEveryAssetThePageAsksForIsOneThePackagePublishes(): void
    {
        preg_match_all('~/assets/(modules/Extras/[^"?]+)~', $this->page(), $matches);

        self::assertNotEmpty($matches[1]);

        foreach ($matches[1] as $relative) {
            self::assertFileExists(ManagerModule::assetsPath() . '/' . $relative);
        }
    }

    public function testEveryTabIsHandedToTheTabPane(): void
    {
        $tabs = substr_count($this->page(), 'class="tab-page"');

        self::assertGreaterThan(0, $tabs);
        self::assertSame($tabs, substr_count($this->page(), 'tpSettings.addTabPage('));
    }

    public function testTheScriptLinksTheRepositoryTheEndpointHandsIt(): void
    {
        $rows = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Manager/InstalledExtras.php');
        $script = (string) file_get_contents(ManagerModule::assetsPath() . '/modules/Extras/js/module.js');

        self::assertStringContainsString("'repository' =>", $rows);
        self::assertStringContainsString('extra.repository', $script);
    }

    public function testThePageAndTheRoutesAgreeOnWhereTheEndpointsAre(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Http/routes/api/v1.php');
        $script = (string) file_get_contents(ManagerModule::assetsPath() . '/modules/Extras/js/module.js');

        self::assertStringContainsString("'prefix' => 'api/v1/extras/admin'", $routes);
        self::assertStringContainsString("this.adminUrl = '/api/v1/extras/admin'", $script);

        foreach (['/installed', '/catalog', '/extras/{vendor}/{package}'] as $endpoint) {
            self::assertStringContainsString($endpoint, $routes);
        }
    }
}
