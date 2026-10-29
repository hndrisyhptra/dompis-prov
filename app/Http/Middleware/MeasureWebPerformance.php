<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class MeasureWebPerformance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('performance.enabled') || ! $request->is('login', 'dashboard', 'branch/dashboard', 'imports/*', 'sync', 'sync/*')) {
            return $next($request);
        }
        $started = hrtime(true);
        $before = $this->durations();
        $response = null;
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (Throwable $exception) {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : ($exception instanceof ValidationException ? 422 : 500);
            throw $exception;
        } finally {
            $milliseconds = round((hrtime(true) - $started) / 1e6, 2);
            $database = 0;
            foreach ($this->durations() as $connection => $duration) {
                $database += max(0, $duration - ($before[$connection] ?? 0));
            }
            $database = round($database, 2);
            $response?->headers->set('Server-Timing', 'app;dur='.$milliseconds.', db;dur='.$database);
            if ($milliseconds >= config('performance.slow_ms')) {
                // No URL/query string, SQL/bindings, identity, password or workbook contents.
                Log::info('web.performance', ['route' => $request->route()?->getName() ?? $request->route()?->uri(), 'method' => $request->method(),
                    'status' => $status, 'app_ms' => $milliseconds, 'database_ms' => $database]);
            }
        }
    }

    private function durations(): array
    {
        return array_map(fn ($connection) => $connection->totalQueryDuration(), DB::getConnections());
    }
}
