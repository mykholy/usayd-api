<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Idempotency\DatabaseIdempotency;
use App\Platform\Idempotency\IdempotencyProblem;
use App\Platform\Idempotency\IdempotentOperation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnforceIdempotency
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private DatabaseIdempotency $idempotency,
        private IdempotencyProblem $problems,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), self::MUTATING_METHODS, true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') {
            return $next($request);
        }

        if (! is_string($key) || strlen($key) > 255 || ! preg_match('/^[\x21-\x7E]+$/', $key)) {
            return $this->problems->render($request, 'invalid-key');
        }

        $principal = (int) ($request->user()?->id ?? 0);
        $route = $request->path();
        $query = $request->getQueryString();
        $target = $route.($query ? '?'.$query : '');
        $hash = hash('sha256', $principal.'|'.$request->method().'|'.$target.'|'.$request->getContent());

        return $this->idempotency->execute(new IdempotentOperation($key, $principal, $route, $hash), $request, $next);
    }
}
