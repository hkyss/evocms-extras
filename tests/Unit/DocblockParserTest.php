<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Legacy\DocblockParser;
use hkyss\Extras\Legacy\ElementType;
use PHPUnit\Framework\TestCase;

/** Fixtures come from real repositories; the format has no complete written spec. */
class DocblockParserTest extends TestCase
{
    private DocblockParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DocblockParser();
    }

    private function fixture(string $relative): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/legacy/' . $relative);
    }

    public function testParsesPluginWithPhpPrefix(): void
    {
        $d = $this->parser->parse(ElementType::Plugin, $this->fixture('plugins/PageBuilder.tpl'), 'PageBuilder');

        self::assertSame('PageBuilder', $d->name());
        self::assertSame('Creates form for manage content sections', $d->description());
        self::assertSame('1.3.16', $d->version());
        self::assertSame('Manager and Admin', $d->category());
        self::assertStringStartsWith('include_once MODX_BASE_PATH', $d->code());
        self::assertStringNotContainsString('@internal', $d->code());
    }

    public function testParsesPluginEvents(): void
    {
        $d = $this->parser->parse(ElementType::Plugin, $this->fixture('plugins/PageBuilder.tpl'), 'PageBuilder');

        self::assertSame([
            'OnWebPageInit',
            'OnManagerPageInit',
            'OnDocFormRender',
            'OnDocFormSave',
            'OnBeforeEmptyTrash',
            'OnDocDuplicate',
        ], $d->events());
    }

    public function testParsesTemplateVariableWithoutPhpPrefix(): void
    {
        $d = $this->parser->parse(ElementType::Tv, $this->fixture('tvs/price.tpl'), 'price');

        self::assertSame('price', $d->name());
        self::assertSame('Price', $d->tag('caption'));
        self::assertSame('text', $d->tag('input_type'));
        self::assertSame('1', $d->tag('lock_tv'));
        self::assertSame('Shop', $d->category());
        self::assertSame('', $d->code());
    }

    public function testParsesChunkWithByteOrderMark(): void
    {
        $raw = $this->fixture('chunks/mm_rules.tpl');

        self::assertStringStartsWith("\xEF\xBB\xBF", $raw, 'the fixture must keep its BOM');

        $d = $this->parser->parse(ElementType::Chunk, $raw, 'mm_rules');

        self::assertSame('mm_rules', $d->name());
        self::assertSame('Default ManagerManager rules.', $d->description());
        self::assertSame('Js', $d->category());
    }

    public function testHonoursOverwriteFalse(): void
    {
        $chunk = $this->parser->parse(ElementType::Chunk, $this->fixture('chunks/mm_rules.tpl'), 'mm_rules');
        $plugin = $this->parser->parse(ElementType::Plugin, $this->fixture('plugins/PageBuilder.tpl'), 'PageBuilder');

        self::assertFalse($chunk->mayOverwrite());
        self::assertTrue($plugin->mayOverwrite());
    }

    public function testParsesInstallSets(): void
    {
        $d = $this->parser->parse(ElementType::Chunk, $this->fixture('chunks/mm_rules.tpl'), 'mm_rules');

        self::assertSame(['base', 'sample'], $d->installSets());
        self::assertTrue($d->belongsToInstallSet('base'));
        self::assertFalse($d->belongsToInstallSet('demo'));
    }

    public function testElementWithoutInstallSetBelongsToEvery(): void
    {
        $d = $this->parser->parse(ElementType::Snippet, "/**\n * Bare\n */\nreturn 1;", 'Bare');

        self::assertSame([], $d->installSets());
        self::assertTrue($d->belongsToInstallSet('base'));
        self::assertTrue($d->belongsToInstallSet('anything'));
    }

    public function testFallsBackToFilenameWhenDocblockHasNoName(): void
    {
        $d = $this->parser->parse(ElementType::Snippet, "<?php\nreturn 1;", 'FromFilename');

        self::assertSame('FromFilename', $d->name());
    }

    public function testMultilinePropertiesAreJoined(): void
    {
        $source = "//<?php\n/**\n * Widget\n *\n * @internal @properties &a=A;text;1\n *   &b=B;text;2\n */\nreturn 1;";

        $d = $this->parser->parse(ElementType::Snippet, $source, 'Widget');

        self::assertStringContainsString('&a=A;text;1', $d->properties());
        self::assertStringContainsString('&b=B;text;2', $d->properties());
    }
}
