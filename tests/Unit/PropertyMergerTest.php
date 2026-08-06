<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Legacy\PropertyMerger;
use PHPUnit\Framework\TestCase;

class PropertyMergerTest extends TestCase
{
    private PropertyMerger $merger;

    protected function setUp(): void
    {
        $this->merger = new PropertyMerger();
    }

    public function testKeepsConfiguredValues(): void
    {
        $merged = $this->merger->merge(
            '&tabName=Tab name;text;Page Builder',
            '&tabName=Tab name;text;My blocks'
        );

        self::assertStringContainsString('My blocks', $merged);
    }

    public function testBringsInNewProperties(): void
    {
        $merged = $this->merger->merge('&a=A;text;1 &b=B;text;2', '&a=A;text;custom');

        self::assertStringContainsString('&b=', $merged);
        self::assertStringContainsString('custom', $merged);
    }

    public function testDropsPropertiesRemovedByAuthor(): void
    {
        $merged = $this->merger->merge('&a=A;text;1', '&a=A;text;custom &gone=Gone;text;x');

        self::assertStringNotContainsString('&gone=', $merged);
    }

    public function testEmptyExistingReturnsIncoming(): void
    {
        self::assertSame('&a=A;text;1', $this->merger->merge('&a=A;text;1', ''));
    }

    public function testEmptyIncomingKeepsExisting(): void
    {
        self::assertSame('&a=A;text;custom', $this->merger->merge('', '&a=A;text;custom'));
    }

    public function testDoesNotSplitOnHtmlEntities(): void
    {
        $entries = $this->merger->entries('&label=Tom &amp; Jerry;text;x &other=B;text;y');

        self::assertArrayHasKey('label', $entries);
        self::assertArrayHasKey('other', $entries);
        self::assertCount(2, $entries);
    }

    /** Menus carry four segments and text fields three, so the value is the last one. */
    public function testValueIsTheLastSegmentWhateverTheType(): void
    {
        $values = $this->merger->values('&text=Caption;text;value &menu=Caption;menu;a,b,c;chosen');

        self::assertSame('value', $values['text']);
        self::assertSame('chosen', $values['menu']);
    }

    public function testMergesJsonProperties(): void
    {
        $merged = $this->merger->merge('{"tab":"Default","added":"new"}', '{"tab":"Custom"}');
        $decoded = json_decode($merged, true);

        self::assertSame('Custom', $decoded['tab']);
        self::assertSame('new', $decoded['added']);
    }

    public function testMergesNestedJsonProperties(): void
    {
        $merged = $this->merger->merge('{"opts":{"a":1,"b":2}}', '{"opts":{"a":99}}');
        $decoded = json_decode($merged, true);

        self::assertSame(99, $decoded['opts']['a']);
        self::assertSame(2, $decoded['opts']['b']);
    }
}
