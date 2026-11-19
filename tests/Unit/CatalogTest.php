<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Catalog\Catalog;
use hkyss\Extras\Catalog\CatalogSource;
use hkyss\Extras\Catalog\SnapshotSource;
use hkyss\Extras\Domain\CompatibilityStatus;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use PHPUnit\Framework\TestCase;

class CatalogTest extends TestCase
{
    private function extra(string $coordinate, ?CompatibilityStatus $status = null, string $description = ''): Extra
    {
        return new Extra(
            Coordinate::parse($coordinate),
            ExtraFormat::Legacy,
            '',
            $description,
            '',
            [],
            'master',
            $status
        );
    }

    /** @param list<Extra> $extras */
    private function source(string $name, array $extras, ?string $reason = null): CatalogSource
    {
        return new class ($name, $extras, $reason) implements CatalogSource {
            /** @param list<Extra> $extras */
            public function __construct(
                private string $name,
                private array $extras,
                private ?string $reason
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function all(): array
            {
                return $this->extras;
            }

            public function find(Coordinate $coordinate): ?Extra
            {
                foreach ($this->extras as $extra) {
                    if ($extra->coordinate()->equals($coordinate)) {
                        return $extra;
                    }
                }

                return null;
            }

            public function unavailableReason(): ?string
            {
                return $this->reason;
            }
        };
    }

    public function testEarlierSourceWins(): void
    {
        $catalog = new Catalog([
            $this->source('snapshot', [$this->extra('org/thing', CompatibilityStatus::Verified)]),
            $this->source('github', [$this->extra('org/thing', CompatibilityStatus::Unknown)]),
        ]);

        $found = $catalog->find(Coordinate::parse('org/thing'));

        self::assertNotNull($found);
        self::assertSame(CompatibilityStatus::Verified, $found->compatibility());
        self::assertCount(1, $catalog->all());
    }

    public function testCuratedListsOnlyWhatACuratedSourceHolds(): void
    {
        $catalog = new Catalog([
            $this->source('packagist', [$this->extra('anyone/thing'), $this->extra('org/shared', CompatibilityStatus::Unknown)]),
            new SnapshotSource('snapshot', $this->snapshot(['org/shared', 'org/listed'])),
        ]);

        $curated = $catalog->curated();

        self::assertSame(['org/listed', 'org/shared'], array_map(static fn (Extra $extra) => $extra->coordinate()->key(), $curated));
        self::assertSame(CompatibilityStatus::Verified, $curated[1]->compatibility());
    }

    /** @param list<string> $coordinates */
    private function snapshot(array $coordinates): string
    {
        $path = tempnam(sys_get_temp_dir(), 'extras-snapshot');
        $extras = array_map(static fn (string $coordinate) => [
            'coordinate' => $coordinate,
            'format' => 'legacy',
            'compatibility' => 'verified',
        ], $coordinates);
        file_put_contents((string) $path, (string) json_encode(['schema' => 1, 'extras' => $extras]));

        return (string) $path;
    }

    /** A source may list nothing yet still answer a direct lookup, as GitHub does. */
    public function testFallsBackToPerSourceLookupWhenNotListed(): void
    {
        $lazy = new class () implements CatalogSource {
            public function name(): string
            {
                return 'lazy';
            }

            public function all(): array
            {
                return [];
            }

            public function find(Coordinate $coordinate): Extra
            {
                return new Extra($coordinate, ExtraFormat::Legacy);
            }

            public function unavailableReason(): ?string
            {
                return null;
            }
        };

        $found = (new Catalog([$lazy]))->find(Coordinate::parse('org/late'));

        self::assertNotNull($found);
        self::assertSame('lazy', $found->sourceName());
    }

    public function testSearchMatchesCoordinateAndDescription(): void
    {
        $catalog = new Catalog([
            $this->source('s', [
                $this->extra('org/pagebuilder', null, 'Creates form for content sections'),
                $this->extra('org/other', null, 'Something else'),
            ]),
        ]);

        self::assertCount(1, $catalog->search('pagebuilder'));
        self::assertCount(1, $catalog->search('content sections'));
        self::assertCount(2, $catalog->search(''));
    }

    public function testFiltersByFormat(): void
    {
        $catalog = new Catalog([
            $this->source('s', [
                new Extra(Coordinate::parse('a/composer'), ExtraFormat::Composer),
                new Extra(Coordinate::parse('b/legacy'), ExtraFormat::Legacy),
            ]),
        ]);

        self::assertCount(1, $catalog->search('', ExtraFormat::Composer));
        self::assertCount(1, $catalog->search('', ExtraFormat::Legacy));
    }

    public function testUnavailableSourceIsReportedNotThrown(): void
    {
        $catalog = new Catalog([
            $this->source('github', [], 'rate limit exceeded'),
            $this->source('snapshot', [$this->extra('org/thing')]),
        ]);

        self::assertCount(1, $catalog->all());
        self::assertSame(['github' => 'rate limit exceeded'], $catalog->problems());
    }

    public function testFindReturnsNullWhenNobodyKnows(): void
    {
        self::assertNull((new Catalog([$this->source('s', [])]))->find(Coordinate::parse('nobody/knows')));
    }
}
