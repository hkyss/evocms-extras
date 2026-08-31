<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Domain\WebUrl;
use PHPUnit\Framework\TestCase;

class WebUrlTest extends TestCase
{
    public function testTakesTheGitSuffixOffACloneUrl(): void
    {
        self::assertSame('https://github.com/acme/thing', WebUrl::from('https://github.com/acme/thing.git'));
    }

    public function testKeepsWhatABrowserCanFollow(): void
    {
        self::assertSame('https://acme.test/extra', WebUrl::from('  https://acme.test/extra  '));
        self::assertSame('http://acme.test', WebUrl::from('http://acme.test'));
    }

    public function testRefusesWhatABrowserCannotOpen(): void
    {
        self::assertSame('', WebUrl::from('git@github.com:acme/thing.git'));
        self::assertSame('', WebUrl::from('acme.test'));
        self::assertSame('', WebUrl::from(''));
        self::assertSame('', WebUrl::from(null));
        self::assertSame('', WebUrl::from(['https://acme.test']));
    }
}
