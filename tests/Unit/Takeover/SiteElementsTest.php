<?php

namespace hkyss\Extras\Tests\Unit\Takeover;

use hkyss\Extras\Legacy\ElementType;
use hkyss\Extras\Takeover\SiteElements;
use hkyss\Extras\Tests\Support\SiteTables;
use PHPUnit\Framework\TestCase;

class SiteElementsTest extends TestCase
{
    private SiteTables $tables;
    private SiteElements $elements;

    protected function setUp(): void
    {
        $this->tables = new SiteTables();
        $this->elements = new SiteElements($this->tables->connection());
    }

    public function testItReadsTheVersionOutOfTheDescription(): void
    {
        $this->tables->insert('site_plugins', [
            'name' => 'CodeMirror',
            'description' => '<strong>1.7</strong> JavaScript library',
        ]);

        $rows = $this->elements->listing(ElementType::Plugin);

        self::assertCount(1, $rows);
        self::assertSame('CodeMirror', $rows[0]->name());
        self::assertSame('1.7', $rows[0]->version());
        self::assertFalse($rows[0]->isDisabled());
    }

    public function testAnElementWrittenByHandCarriesNoVersion(): void
    {
        $this->tables->insert('site_snippets', ['name' => 'Nav', 'description' => 'our menu']);

        self::assertSame('', $this->elements->listing(ElementType::Snippet)[0]->version());
    }

    public function testItFindsAModuleByWhatItRuns(): void
    {
        $this->tables->insert('site_modules', [
            'name' => 'Дополнения',
            'modulecode' => "include_once('../assets/modules/store/core.php');",
        ]);
        $this->tables->insert('site_modules', ['name' => 'Other', 'modulecode' => 'echo 1;']);

        $found = $this->elements->including(ElementType::Module, 'assets/modules/store/core.php');

        self::assertCount(1, $found);
        self::assertSame('Дополнения', $found[0]->name());
    }

    public function testItSwitchesARowOffAndBackOn(): void
    {
        $id = $this->tables->insert('site_plugins', ['name' => 'CodeMirror', 'description' => '<strong>1.7</strong>']);
        $element = $this->elements->listing(ElementType::Plugin)[0];

        self::assertTrue($this->elements->disable($element));
        self::assertSame(1, (int) $this->tables->row('site_plugins', $id)['disabled']);

        self::assertTrue($this->elements->enable(ElementType::Plugin, $id));
        self::assertSame(0, (int) $this->tables->row('site_plugins', $id)['disabled']);
    }

    public function testARowThatIsNoLongerThereSaysSo(): void
    {
        self::assertFalse($this->elements->enable(ElementType::Plugin, 404));
    }
}
