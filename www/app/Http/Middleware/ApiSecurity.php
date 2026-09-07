<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ApiSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*')) {
            $request->headers->set('Accept', 'application/json');
            $limitKey = 'api-ip:'.hash('sha256', (string) $request->ip());
            if (RateLimiter::tooManyAttempts($limitKey, (int) config('accounts.requests_per_minute_per_ip'))) {
                throw new HttpException(429, 'Muitas requisições. Tente novamente mais tarde.', headers: [
                    'Retry-After' => (string) RateLimiter::availableIn($limitKey),
                ]);
            }
            RateLimiter::hit($limitKey, 60);
            abort_if(strlen($request->getContent()) > 16384, 413, 'Corpo da requisição excede 16 KiB.');

            if (! $request->isMethodSafe() && $request->getContent() !== '') {
                abort_unless($request->isJson(), 415, 'Envie o corpo da requisição como application/json.');

                try {
                    $body = json_decode($request->getContent(), false, 16, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    abort(400, 'JSON inválido.');
                }

                abort_unless($body instanceof \stdClass, 400, 'O corpo JSON deve ser um objeto.');
            }
        }

        $response = $next($request);
        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        return $response;
    }
}
