<?php

use App\Support\WorkOrderUpload;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['api/telegram/material/webhook']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if (! $request->is('imports/excel', 'work-orders/*/boq', 'work-orders/*/kml')) {
                return null;
            }
            $kmlUpload = $request->is('work-orders/*/kml');
            $limit = $kmlUpload ? min(5, WorkOrderUpload::limitBytes() / 1024 / 1024).' MB' : WorkOrderUpload::limitLabel();
            $message = 'Ukuran kiriman melebihi batas server. Batas file saat ini '.$limit.'. Pilih file yang lebih kecil dan upload ulang.';

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'errors' => ['file' => [$message]]], 413)
                : response()->view('errors.upload-too-large', ['message' => $message, 'errors' => new ViewErrorBag, 'kmlUpload' => $kmlUpload, 'boqUpload' => $request->is('work-orders/*/boq', 'work-orders/*/kml')], 413);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
