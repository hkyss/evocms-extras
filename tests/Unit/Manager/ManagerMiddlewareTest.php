<?php

namespace hkyss\Extras\Tests\Unit\Manager;

use hkyss\Extras\Http\Middlewares\ManagerMiddleware;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class ManagerMiddlewareTest extends TestCase
{
    public function testAVisitorIsTurnedAwayBeforeTheHeaderIsAskedAbout(): void
    {
        $answer = $this->knock(false, 'POST', ['X-Requested-With' => 'XMLHttpRequest']);

        self::assertSame(401, $answer->getStatusCode());
        self::assertSame(
            ['success' => false, 'message' => 'Доступ только для администраторов.', 'errors' => []],
            json_decode((string) $answer->getContent(), true)
        );
    }

    public function testAManagerReadsWithoutTheHeader(): void
    {
        self::assertSame('passed through', $this->knock(true, 'GET')->getContent());
    }

    #[DataProvider('writes')]
    public function testAWriteAnotherSiteSentIsRefused(string $method): void
    {
        $answer = $this->knock(true, $method);

        self::assertSame(403, $answer->getStatusCode());
        self::assertSame(
            ['success' => false, 'message' => 'Запрос отклонён: он пришёл не со страницы сайта.', 'errors' => []],
            json_decode((string) $answer->getContent(), true)
        );
    }

    #[DataProvider('writes')]
    public function testAWriteTheModuleSentGoesThrough(string $method): void
    {
        self::assertSame(
            'passed through',
            $this->knock(true, $method, ['X-Requested-With' => 'XMLHttpRequest'])->getContent()
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writes(): array
    {
        return ['install' => ['POST'], 'remove' => ['DELETE']];
    }

    /**
     * @param array<string, string> $headers
     */
    private function knock(bool $signedIn, string $method, array $headers = []): Response
    {
        $request = Request::create('/api/v1/extras/admin/extras/vendor/package', $method);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $guard = new class ($signedIn) extends ManagerMiddleware {
            public function __construct(private readonly bool $manager)
            {
            }

            protected function signedIn(): bool
            {
                return $this->manager;
            }
        };

        return $guard->handle($request, static fn (): Response => new Response('passed through'));
    }
}
