<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Catalog\SnapshotSource;
use hkyss\Extras\Domain\CompatibilityStatus;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\ExtraFormat;
use PHPUnit\Framework\TestCase;

class SnapshotSourceTest extends TestCase
{
    private function bundled(): SnapshotSource
    {
        return new SnapshotSource('Bundled snapshot');
    }

    public function testBundledSnapshotLoads(): void
    {
        $source = $this->bundled();

        self::assertNull($source->unavailableReason());
        self::assertNotEmpty($source->all());
    }

    public function testBundledSnapshotCarriesBothFormats(): void
    {
        $formats = [];

        foreach ($this->bundled()->all() as $extra) {
            $formats[$extra->format()->value] = true;
        }

        self::assertArrayHasKey(ExtraFormat::Composer->value, $formats);
        self::assertArrayHasKey(ExtraFormat::Legacy->value, $formats);
    }

    /** Legacy extras cannot claim verified without someone actually checking. */
    public function testLegacyEntriesAreNotClaimedVerified(): void
    {
        foreach ($this->bundled()->all() as $extra) {
            if ($extra->format() !== ExtraFormat::Legacy) {
                continue;
            }

            self::assertNotSame(
                CompatibilityStatus::Verified,
                $extra->compatibility(),
                (string) $extra->coordinate()
            );
        }
    }

    public function testFindsByCoordinateIgnoringCase(): void
    {
        $first = $this->bundled()->all()[0];
        $found = $this->bundled()->find(Coordinate::parse(strtoupper((string) $first->coordinate())));

        self::assertNotNull($found);
        self::assertTrue($found->coordinate()->equals($first->coordinate()));
    }

    public function testMissingFileIsReportedNotThrown(): void
    {
        $source = new SnapshotSource('missing', '/nonexistent/catalog.json');

        self::assertSame([], $source->all());
        self::assertStringContainsString('not found', (string) $source->unavailableReason());
    }

    public function testMalformedDocumentIsReportedNotThrown(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'snap');
        file_put_contents($path, '{"nope":true}');

        $source = new SnapshotSource('broken', $path);

        self::assertSame([], $source->all());
        self::assertStringContainsString('not a valid catalog', (string) $source->unavailableReason());

        @unlink($path);
    }

    public function testDocumentRoundTrips(): void
    {
        $extras = array_slice($this->bundled()->all(), 0, 3);
        $document = SnapshotSource::document($extras, '2026-01-01T00:00:00+00:00');

        self::assertSame(1, $document['schema']);
        self::assertCount(3, $document['extras']);
        self::assertArrayNotHasKey('source', $document['extras'][0]);

        $path = tempnam(sys_get_temp_dir(), 'snap');
        file_put_contents($path, (string) json_encode($document));

        self::assertCount(3, (new SnapshotSource('rt', $path))->all());

        @unlink($path);
    }
}
