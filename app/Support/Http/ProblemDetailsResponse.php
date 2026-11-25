<?php

namespace App\Support\Http;

use App\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ProblemDetailsResponse
{
    /**
     * Format any exception into an RFC 7807 problem+json response.
     */
    public static function fromThrowable(Throwable $exception, Request $request): JsonResponse
    {
        $status = self::resolveStatus($exception);
        $errorCode = self::resolveErrorCode($exception, $status);
        $traceId = (string) ($request->attributes->get('traceId') ?? Str::uuid());

        $locale = self::resolveLocale($request);

        $defaultTitle = __("errors.{$errorCode}.title", [], $locale);
        $defaultDetail = __("errors.{$errorCode}.detail", [], $locale);

        // Fallbacks if localization key missing
        if ($defaultTitle === "errors.{$errorCode}.title") {
            $defaultTitle = __('errors.INTERNAL_ERROR.title', [], $locale);
        }
        if ($defaultDetail === "errors.{$errorCode}.detail") {
            $defaultDetail = __('errors.INTERNAL_ERROR.detail', [], $locale);
        }

        $title = $defaultTitle;
        $detail = $defaultDetail;
        $invalidParams = [];
        $extensions = [];
        $responseHeaders = [
            'Content-Type' => 'application/problem+json',
            'Content-Language' => $locale,
            'Vary' => 'Accept-Language',
            'X-Request-ID' => $traceId,
            'X-Trace-ID' => $traceId,
        ];

        if ($exception instanceof ApiException) {
            if ($exception->getTitle()) {
                $title = $exception->getTitle();
            }
            if ($exception->getDetail()) {
                $detail = $exception->getDetail();
            }
            $invalidParams = $exception->getErrors();
            $extensions = $exception->getExtensions();

            if ($exception->getRetryAfter() !== null) {
                $responseHeaders['Retry-After'] = (string) $exception->getRetryAfter();
            }
        } elseif ($exception instanceof ValidationException) {
            $invalidParams = $exception->errors();
            $detail = $defaultDetail;
        } elseif (config('app.debug') && $status === 500) {
            // Only leak raw exception message in local debug mode
            $detail = $exception->getMessage() ?: $defaultDetail;
        }

        if ($exception instanceof HttpExceptionInterface) {
            foreach ($exception->getHeaders() as $hName => $hVal) {
                if (in_array(strtolower($hName), ['retry-after', 'www-authenticate'], true)) {
                    $responseHeaders[$hName] = is_array($hVal) ? implode(', ', $hVal) : (string) $hVal;
                }
            }
        }

        $sanitizedInstance = self::sanitizeInstanceUri($request);

        $payload = array_merge([
            'type' => "https://api.usayd.academy/errors/{$errorCode}",
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $sanitizedInstance,
            'code' => $errorCode,
            'errorCode' => $errorCode,
            'traceId' => $traceId,
        ], $extensions);

        if (! empty($invalidParams)) {
            $payload['invalidParams'] = $invalidParams;
            $payload['errors'] = $invalidParams;
        }

        return response()->json($payload, $status, $responseHeaders);
    }

    /**
     * Sanitize query-string secrets from instance URI.
     */
    protected static function sanitizeInstanceUri(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');
        $query = $request->query();

        if (empty($query)) {
            return $path;
        }

        $sensitiveParams = ['token', 'secret', 'password', 'key', 'auth', 'signature', 'api_key', 'access_token'];
        $cleanQuery = [];

        foreach ($query as $k => $v) {
            if (in_array(strtolower((string) $k), $sensitiveParams, true)) {
                $cleanQuery[$k] = '[REDACTED]';
            } else {
                $cleanQuery[$k] = is_string($v) ? substr($v, 0, 100) : $v;
            }
        }

        return $path.'?'.http_build_query($cleanQuery);
    }

    protected static function resolveStatus(Throwable $e): int
    {
        if ($e instanceof ApiException) {
            return $e->getStatus();
        }
        if ($e instanceof ValidationException) {
            return 422;
        }
        if ($e instanceof AuthenticationException) {
            return 401;
        }
        if ($e instanceof AuthorizationException) {
            return 403;
        }
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        return 500;
    }

    protected static function resolveErrorCode(Throwable $e, int $status): string
    {
        if ($e instanceof ApiException) {
            return $e->getErrorCode();
        }

        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'RESOURCE_NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'VALIDATION_FAILED',
            429 => 'RATE_LIMITED',
            503 => 'SERVICE_UNAVAILABLE',
            default => 'INTERNAL_ERROR',
        };
    }

    protected static function resolveLocale(Request $request): string
    {
        return $request->attributes->get('api_locale') ?? $request->getPreferredLanguage(['ar', 'en']) ?? 'ar';
    }
}
