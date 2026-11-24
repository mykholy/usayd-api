<?php

namespace App\Exceptions;

use Exception;

class ApiException extends Exception
{
    protected int $status;

    protected string $errorCode;

    protected ?string $title;

    protected ?string $detail;

    /** @var array<string, mixed> */
    protected array $errors;

    /** @var array<string, mixed> */
    protected array $extensions;

    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        int $status = 400,
        string $errorCode = 'BAD_REQUEST',
        ?string $detail = null,
        ?string $title = null,
        array $errors = [],
        array $extensions = []
    ) {
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->detail = $detail;
        $this->title = $title;
        $this->errors = $errors;
        $this->extensions = $extensions;

        parent::__construct($detail ?? $errorCode, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    /**
     * @return array<string, mixed>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    public function getRetryAfter(): ?int
    {
        return isset($this->extensions['retryAfter']) ? (int) $this->extensions['retryAfter'] : null;
    }

    public static function unauthorized(?string $detail = null): self
    {
        return new self(401, 'UNAUTHORIZED', $detail);
    }

    public static function forbidden(?string $detail = null): self
    {
        return new self(403, 'FORBIDDEN', $detail);
    }

    public static function notFound(?string $detail = null): self
    {
        return new self(404, 'RESOURCE_NOT_FOUND', $detail);
    }

    public static function conflict(?string $detail = null): self
    {
        return new self(409, 'CONFLICT', $detail);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function validation(array $errors = [], ?string $detail = null): self
    {
        return new self(422, 'VALIDATION_FAILED', $detail, null, $errors);
    }

    public static function rateLimited(?string $detail = null, int $retryAfter = 60): self
    {
        return new self(429, 'RATE_LIMITED', $detail, null, [], ['retryAfter' => $retryAfter]);
    }

    public static function serverError(?string $detail = null): self
    {
        return new self(500, 'INTERNAL_ERROR', $detail);
    }

    public static function serviceUnavailable(?string $detail = null, string $errorCode = 'SERVICE_UNAVAILABLE', array $extensions = []): self
    {
        return new self(503, $errorCode, $detail, null, [], $extensions);
    }
}
