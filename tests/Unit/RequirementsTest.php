<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Catalog\PackagistSource;
use hkyss\Extras\Catalog\SnapshotSource;
use hkyss\Extras\Domain\CompatibilityStatus;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use PHPUnit\Framework\TestCase;

class RequirementsTest extends TestCase
{
    public function testPlatformRequirementsAreKept(): void
    {
        $requirements = PackagistSource::requirements([
            'php' => '^8.3',
            'ext-zip' => '*',
            'evolution-cms/evolution' => '^3.5.2',
        ]);

        self::assertSame(
            ['php' => '^8.3', 'ext-zip' => '*', 'evolution-cms/evolution' => '^3.5.2'],
            $requirements
        );
    }

    public function testMalformedEntriesAreDropped(): void
    {
        $requirements = PackagistSource::requirements([
            'php' => '^8.3',
            '' => '^1.0',
            'vendor/array' => ['^1.0'],
            7 => '^2.0',
        ]);

        self::assertSame(['php' => '^8.3'], $requirements);
    }

    public function testEmptyRequire(): void
    {
        self::assertSame([], PackagistSource::requirements([]));
    }

    public function testRequirementsSurviveTheSnapshotRoundTrip(): void
    {
        $extra = new Extra(
            Coordinate::parse('evolution-cms/etinymce'),
            ExtraFormat::Composer,
            'etinymce',
            'TinyMCE 8 for Evolution CMS',
            '8.3.2',
            ['8.3.2'],
            '',
            CompatibilityStatus::Verified,
            'Packagist',
            '',
            'evolution-cms',
            ['php' => '^8.3', 'tinymce/tinymce' => '^8.3']
        );

        $document = SnapshotSource::document([$extra], '2026-08-18T00:00:00+00:00');
        $restored = Extra::fromArray($document['extras'][0]);

        self::assertSame(
            ['php' => '^8.3', 'tinymce/tinymce' => '^8.3'],
            $restored->requires(),
            'a rebuilt snapshot must carry the constraint that predicts an install failure'
        );
    }
}
