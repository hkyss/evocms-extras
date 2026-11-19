<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Legacy\ElementDescriptor;
use hkyss\Extras\Legacy\ElementType;
use hkyss\Extras\Legacy\ElementWriter;
use hkyss\Extras\Tests\Support\SiteTables;
use PHPUnit\Framework\TestCase;

class ElementWriterTest extends TestCase
{
    private SiteTables $tables;
    private ElementWriter $writer;

    protected function setUp(): void
    {
        $this->tables = new SiteTables();
        $this->writer = new ElementWriter($this->tables->connection());
    }

    public function testAnUpdatedElementIsLeftEnabled(): void
    {
        $id = $this->tables->insert('site_plugins', [
            'name' => 'Ditto',
            'description' => '<strong>2.1</strong> document lister',
        ]);

        $written = $this->writer->write($this->descriptor('<strong>3.0</strong> document lister'));

        self::assertSame('update', $written['action']);
        self::assertSame($id, $written['id']);

        $row = $this->tables->row('site_plugins', $id);

        self::assertSame('<strong>3.0</strong> document lister', $row['description']);
        self::assertSame(0, (int) $row['disabled'], 'the row a version bump rewrote is not a conflict with itself');
    }

    public function testASecondRowUnderTheSameNameIsSwitchedOff(): void
    {
        $kept = $this->tables->insert('site_plugins', [
            'name' => 'Ditto',
            'description' => '<strong>2.1</strong> document lister',
        ]);
        $duplicate = $this->tables->insert('site_plugins', [
            'name' => 'Ditto',
            'description' => 'a copy somebody made',
        ]);

        $this->writer->write($this->descriptor('<strong>3.0</strong> document lister'));

        self::assertSame(0, (int) $this->tables->row('site_plugins', $kept)['disabled']);
        self::assertSame(1, (int) $this->tables->row('site_plugins', $duplicate)['disabled']);
    }

    public function testAWriteRemembersWhatTheRowSaidBeforeIt(): void
    {
        $id = $this->tables->insert('site_plugins', [
            'name' => 'Ditto',
            'description' => '<strong>2.1</strong> document lister',
            'plugincode' => '// the code that was there',
        ]);

        $written = $this->writer->write($this->descriptor('<strong>3.0</strong> document lister'));

        self::assertSame('// the code that was there', $written['previous_code']);
        self::assertSame('<strong>2.1</strong> document lister', $written['previous_description']);

        $this->writer->restore(
            ElementType::Plugin,
            $id,
            (string) $written['previous_code'],
            $written['previous_description']
        );

        $row = $this->tables->row('site_plugins', $id);

        self::assertSame('// the code that was there', $row['plugincode']);
        self::assertSame(
            '<strong>2.1</strong> document lister',
            $row['description'],
            'the description is where the legacy format keeps the version, so it goes back too'
        );
    }

    public function testAnUpdateKeepsThePriorityAPluginHadOnAnEvent(): void
    {
        $notFound = $this->tables->insert('system_eventnames', ['name' => 'OnPageNotFound']);
        $init = $this->tables->insert('system_eventnames', ['name' => 'OnWebPageInit']);
        $other = $this->tables->insert('site_plugins', ['name' => 'Other']);
        $id = $this->tables->insert('site_plugins', ['name' => 'Ditto', 'description' => '<strong>2.1</strong>']);
        $this->tables->insert('site_plugin_events', ['pluginid' => $id, 'evtid' => $notFound, 'priority' => 3]);
        $this->tables->insert('site_plugin_events', ['pluginid' => $other, 'evtid' => $init, 'priority' => 4]);

        $this->writer->write($this->descriptor('<strong>3.0</strong>', ['events' => 'OnPageNotFound,OnWebPageInit']));

        self::assertSame([$notFound => 3, $init => 5], $this->tables->priorities($id));
    }

    public function testRestorePutsBackWhatTheRowHadBesidesItsCode(): void
    {
        $category = $this->tables->insert('categories', ['category' => 'Mine']);
        $notFound = $this->tables->insert('system_eventnames', ['name' => 'OnPageNotFound']);
        $this->tables->insert('system_eventnames', ['name' => 'OnWebPageInit']);
        $id = $this->tables->insert('site_plugins', [
            'name' => 'Ditto',
            'description' => '<strong>2.1</strong>',
            'plugincode' => '// before',
            'properties' => '&mine=Mine;text;kept',
            'category' => $category,
        ]);
        $this->tables->insert('site_plugin_events', ['pluginid' => $id, 'evtid' => $notFound, 'priority' => 2]);

        $written = $this->writer->write($this->descriptor('<strong>3.0</strong>', [
            'events' => 'OnWebPageInit',
            'properties' => '&theirs=Theirs;text;new',
            'modx_category' => 'Theirs',
        ]));

        $this->writer->restore(
            ElementType::Plugin,
            $id,
            (string) $written['previous_code'],
            $written['previous_description'],
            $written['previous_properties'],
            $written['previous_category'],
            $written['previous_events']
        );

        $row = $this->tables->row('site_plugins', $id);

        self::assertSame('&mine=Mine;text;kept', $row['properties']);
        self::assertSame($category, (int) $row['category']);
        self::assertSame([$notFound => 2], $this->tables->priorities($id));
    }

    /** @param array<string,string> $tags */
    private function descriptor(string $description, array $tags = []): ElementDescriptor
    {
        return new ElementDescriptor(ElementType::Plugin, 'Ditto', $description, '// code', $tags);
    }
}
