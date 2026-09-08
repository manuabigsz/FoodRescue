<?php

use App\Http\Middleware\ApiSecurity;
use App\Http\Middleware\EnsureActiveAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ApiSecurity::class);
        $middleware->alias([
            'active' => EnsureActiveAccount::class,
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (Response $response, Throwable $exception) {
            if (! request()->is('api/*')) {
                return $response;
            }

            $status = $response->getStatusCode();
            if ($status >= 500) {
                /**
                 * Um abort() explícito carrega uma mensagem escrita para o cliente —
                 * "serviço indisponível", "protocolo não inicializado". Só as exceções
                 * não tratadas são mascaradas, porque o texto delas pode vazar detalhes.
                 */
                $deliberate = $exception instanceof HttpExceptionInterface && $exception->getMessage() !== '';
                $response = response()->json(
                    ['message' => $deliberate ? $exception->getMessage() : 'Erro interno do servidor.'],
                    $status,
                );
            } elseif ($status === 404) {
                $response = response()->json(['message' => 'Recurso não encontrado.'], 404);
            } elseif ($status >= 400 && $response instanceof JsonResponse) {
                $data = $response->getData(true);
                $response->setData(array_intersect_key($data, array_flip(['message', 'errors'])));
            }

            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return $response;
        });
        $exceptions->render(function (UniqueConstraintViolationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Um registro com esses dados já existe.'], 409);
            }
        });
    })->create();
