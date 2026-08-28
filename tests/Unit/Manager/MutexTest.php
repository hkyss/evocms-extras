<?php

namespace hkyss\Extras\Tests\Unit\Manager;

use hkyss\Extras\Manager\Mutex;
use hkyss\Extras\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

class MutexTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::make('extras-mutex');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testASecondWriterIsTurnedAwayUntilTheFirstIsDone(): void
    {
        $first = new Mutex($this->tree->path('writer.lock'));
        $second = new Mutex($this->tree->path('writer.lock'));

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire());

        $first->release();

        self::assertTrue($second->acquire());
        $second->release();
    }

    public function testReleasingWhatWasNeverTakenIsHarmless(): void
    {
        $mutex = new Mutex($this->tree->path('writer.lock'));

        $mutex->release();

        self::assertTrue($mutex->acquire());
        $mutex->release();
    }

    public function testAnUnwritableLockDoesNotStopTheWork(): void
    {
        self::assertTrue((new Mutex($this->tree->path('nowhere/writer.lock')))->acquire());
    }
}
