<?php

declare(strict_types=1);

namespace App\Platform\Idempotency;

use App\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResponseSnapshot
{
    private const SENSITIVE_KEYS = [
        'token', 'access_token', 'refresh_token', 'password', 'secret', 'client_secret',
        'api_key', 'authorization', 'signature', 'signed_url', 'private_key', 'otp',
    ];

    public function __construct(
        public int $status,
        public array $body,
        public array $headers,
    ) {}

    public static function capture(Response $response, string $locale): self
    {
        if (! $response instanceof JsonResponse) {
            throw ApiException::serverError(__('api.idempotency_response_unsafe'));
        }

        $body = $response->getData(true);
        if (! is_array($body) || self::containsSensitiveData($body)) {
            throw ApiException::serverError(__('api.idempotency_response_unsafe'));
        }

        // Never store cookies, authorization, signed locations, or arbitrary headers.
        $headers = ['Content-Language' => $response->headers->get('Content-Language') ?? $locale];
        foreach (['Content-Type', 'Cache-Control', 'ETag', 'Vary'] as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = $response->headers->get($name);
            }
        }

        return new self($response->getStatusCode(), $body, $headers);
    }

    private static function containsSensitiveData(array $body): bool
    {
        foreach ($body as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)
                || (is_array($value) && self::containsSensitiveData($value))) {
                return true;
            }
        }

        return false;
    }
}
