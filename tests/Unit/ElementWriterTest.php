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

    private function descriptor(string $description): ElementDescriptor
    {
        return new ElementDescriptor(ElementType::Plugin, 'Ditto', $description, '// code');
    }
}
