<?php

namespace App\Http;

use App\Exceptions\RevisionConflict;
use App\Exceptions\SyncProtocolConflict;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ApiErrors
{
    public static function respond(Response $response, Throwable $exception, Request $request): Response
    {
        if (! $request->is('api/*') && ! ($request->is('login', 'logout', 'sanctum/*') && $request->expectsJson())) {
            return $response;
        }

        $status = $response->getStatusCode();
        [$code, $message] = match ($status) {
            400 => ['bad_request', 'The request could not be understood.'],
            401 => ['unauthenticated', 'Your session has expired. Please sign in again.'],
            403 => ['forbidden', 'You do not have access to this information.'],
            404 => ['not_found', 'The requested record was not found.'],
            405 => ['method_not_allowed', 'This request method is not supported.'],
            409 => $exception instanceof RevisionConflict
                ? ['revision_conflict', $exception->getMessage()]
                : ['conflict', $request->is('login') ? 'Sign out before signing in to another account.' : 'The request conflicts with the current state.'],
            419 => ['csrf_expired', 'Your security token has expired. Please try again.'],
            422 => ['validation_failed', 'Check your details and try again.'],
            429 => ['rate_limited', 'Too many attempts. Please wait and try again.'],
            503 => ['service_unavailable', 'The server is temporarily unavailable. Please try again later.'],
            default => ['server_error', 'The server could not complete your request.'],
        };

        if ($exception instanceof SyncProtocolConflict) {
            [$code, $message] = [$exception->errorCode, $exception->getMessage()];
        }

        // Keep Laravel's status/headers (including Retry-After), never debug traces.
        $response->setContent(json_encode([
            'code' => $code,
            'message' => $message,
            'errors' => (object) ($exception instanceof ValidationException ? $exception->errors() : []),
        ], JSON_THROW_ON_ERROR));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
