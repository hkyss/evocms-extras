<?php

namespace hkyss\Extras\Http\Middlewares;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The module's endpoints answer to signed-in managers and nobody else, and take a write only with
 * the X-Requested-With a form on another site cannot set.
 */
class ManagerMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->signedIn()) {
            return $this->refuse('Доступ только для администраторов.', 401);
        }

        if (!$request->isMethodSafe() && !$request->headers->has('X-Requested-With')) {
            return $this->refuse('Запрос отклонён: он пришёл не со страницы сайта.', 403);
        }

        return $next($request);
    }

    protected function signedIn(): bool
    {
        return (int) evo()->getLoginUserID('mgr') > 0;
    }

    private function refuse(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'errors' => ['message' => $message]], $status);
    }
}
