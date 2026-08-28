<?php

namespace hkyss\Extras\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The module's endpoints answer to signed-in managers and nobody else. */
class ManagerMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!(int) evo()->getLoginUserID('mgr')) {
            return response()->json([
                'success' => false,
                'errors' => ['message' => 'Доступ только для администраторов.'],
            ], 403);
        }

        return $next($request);
    }
}
