<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Exceptions\ExtrasException;
use PHPUnit\Framework\TestCase;

class CoordinateTest extends TestCase
{
    public function testParsesVendorPackage(): void
    {
        $c = Coordinate::parse('evolution-cms-extras/tinymce5');

        self::assertSame('evolution-cms-extras', $c->namespace());
        self::assertSame('tinymce5', $c->name());
        self::assertSame('evolution-cms-extras/tinymce5', (string) $c);
    }

    public function testKeepsCaseButComparesWithout(): void
    {
        $c = Coordinate::parse('extras-evolution/ManagerManager');

        self::assertSame('ManagerManager', $c->name());
        self::assertSame('extras-evolution/managermanager', $c->key());
    }

    public function testEqualityIgnoresCase(): void
    {
        self::assertTrue(
            Coordinate::parse('extras-evolution/Ditto')->equals(Coordinate::parse('EXTRAS-EVOLUTION/ditto'))
        );
    }

    /** @dataProvider invalidCoordinates */
    public function testRejectsMalformed(string $input): void
    {
        $this->expectException(ExtrasException::class);
        Coordinate::parse($input);
    }

    /** @return array<string,array{0:string}> */
    public static function invalidCoordinates(): array
    {
        return [
            'no slash' => ['tinymce5'],
            'too many parts' => ['a/b/c'],
            'empty vendor' => ['/package'],
            'empty package' => ['vendor/'],
            'whitespace' => ['vendor/pack age'],
            'empty' => [''],
            'leading dash' => ['-vendor/package'],
        ];
    }

    public function testTryParseReturnsNullInsteadOfThrowing(): void
    {
        self::assertNull(Coordinate::tryParse('nonsense'));
        self::assertNotNull(Coordinate::tryParse('a/b'));
    }

    public function testAcceptsDotsAndUnderscores(): void
    {
        self::assertSame('ace.modx', Coordinate::parse('extras-evolution/ace.modx')->name());
    }
}
