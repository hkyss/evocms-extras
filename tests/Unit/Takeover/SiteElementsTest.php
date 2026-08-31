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

        self::assertTrue($this->elements->restore(ElementType::Plugin, $id, 'CodeMirror'));
        self::assertSame(0, (int) $this->tables->row('site_plugins', $id)['disabled']);
    }

    public function testARowSetAsideChangesItsNameSoAnInstallerWillNotFindIt(): void
    {
        $id = $this->tables->insert('site_plugins', ['name' => 'Updater', 'description' => '<strong>0.9.2</strong>']);
        $element = $this->elements->listing(ElementType::Plugin)[0];

        self::assertTrue($this->elements->setAside($element, '.old'));

        $row = $this->tables->row('site_plugins', $id);

        self::assertSame('Updater.old', $row['name']);
        self::assertSame(1, (int) $row['disabled']);

        self::assertTrue($this->elements->restore(ElementType::Plugin, $id, 'Updater'));

        $row = $this->tables->row('site_plugins', $id);

        self::assertSame('Updater', $row['name']);
        self::assertSame(0, (int) $row['disabled']);
    }

    public function testARowIsNotSetAsideOntoANameAlreadyTaken(): void
    {
        $this->tables->insert('site_plugins', ['name' => 'Updater.old', 'description' => 'kept from last time']);
        $this->tables->insert('site_plugins', ['name' => 'Updater', 'description' => '<strong>0.9.2</strong>']);

        $element = $this->elements->listing(ElementType::Plugin)[1];

        self::assertFalse($this->elements->setAside($element, '.old'));
        self::assertSame('Updater', $this->tables->row('site_plugins', $element->id())['name']);
    }

    public function testASetAsideNameIsCutToFitTheColumn(): void
    {
        $name = str_repeat('a', 50);
        $id = $this->tables->insert('site_plugins', ['name' => $name, 'description' => '<strong>1.0</strong>']);

        self::assertTrue($this->elements->setAside($this->elements->listing(ElementType::Plugin)[0], '.old'));
        self::assertSame(str_repeat('a', 46) . '.old', $this->tables->row('site_plugins', $id)['name']);
    }

    public function testARowThatIsNoLongerThereSaysSo(): void
    {
        self::assertFalse($this->elements->restore(ElementType::Plugin, 404, 'CodeMirror'));
    }
}
