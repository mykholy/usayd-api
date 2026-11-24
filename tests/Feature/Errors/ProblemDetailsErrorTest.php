<?php

namespace Tests\Feature\Errors;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProblemDetailsErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_returns_rfc7807_problem_details_with_arabic_locale(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'ar',
        ])->getJson('/api/v1/test/errors/unauthorized');

        $response->assertStatus(401);
        $this->assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
        $this->assertNotEmpty($response->headers->get('X-Request-ID'));
        $this->assertNotEmpty($response->headers->get('X-Trace-ID'));

        $response->assertJsonStructure([
            'type',
            'title',
            'status',
            'detail',
            'instance',
            'code',
            'traceId',
        ]);

        $response->assertJson([
            'status' => 401,
            'code' => 'UNAUTHORIZED',
            'title' => 'غير مصرح',
            'detail' => 'يجب تسجيل الدخول للوصول إلى هذا المورد.',
            'instance' => '/api/v1/test/errors/unauthorized',
        ]);
    }

    public function test_unauthorized_returns_english_locale_when_requested(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'en',
        ])->getJson('/api/v1/test/errors/unauthorized');

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 401,
            'code' => 'UNAUTHORIZED',
            'title' => 'Unauthorized',
            'detail' => 'Authentication is required to access this resource.',
        ]);
    }

    public function test_forbidden_returns_403_problem_details(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'en',
        ])->getJson('/api/v1/test/errors/forbidden');

        $response->assertStatus(403);
        $response->assertJson([
            'status' => 403,
            'code' => 'FORBIDDEN',
            'title' => 'Forbidden',
            'detail' => 'You do not have sufficient permissions to perform this action.',
        ]);
    }

    public function test_not_found_returns_404_problem_details(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'en',
        ])->getJson('/api/v1/test/errors/not-found');

        $response->assertStatus(404);
        $response->assertJson([
            'status' => 404,
            'code' => 'RESOURCE_NOT_FOUND',
            'title' => 'Resource Not Found',
        ]);
    }

    public function test_conflict_returns_409_problem_details(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'en',
        ])->getJson('/api/v1/test/errors/conflict');

        $response->assertStatus(409);
        $response->assertJson([
            'status' => 409,
            'code' => 'CONFLICT',
            'title' => 'Conflict',
        ]);
    }

    public function test_validation_error_returns_422_with_invalid_params(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'en',
        ])->postJson('/api/v1/test/errors/validation', []);

        $response->assertStatus(422);
        $this->assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));

        $response->assertJsonStructure([
            'type',
            'title',
            'status',
            'detail',
            'instance',
            'code',
            'traceId',
            'invalidParams' => [
                'email',
                'name',
            ],
        ]);

        $response->assertJson([
            'status' => 422,
            'code' => 'VALIDATION_FAILED',
        ]);
    }

    public function test_rate_limited_returns_429_with_retry_after_extension(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'en',
        ])->getJson('/api/v1/test/errors/rate-limited');

        $response->assertStatus(429);
        $response->assertJson([
            'status' => 429,
            'code' => 'RATE_LIMITED',
            'retryAfter' => 120,
        ]);
    }

    public function test_production_server_error_prevents_sensitive_leakage(): void
    {
        config(['app.debug' => false]);

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'en',
        ])->getJson('/api/v1/test/errors/server-error');

        $response->assertStatus(500);
        $response->assertJson([
            'status' => 500,
            'code' => 'INTERNAL_ERROR',
            'title' => 'Internal Server Error',
        ]);

        // Assert strictly that internal SQL and sensitive message never leaked to client
        $responseContent = $response->getContent();
        $this->assertStringNotContainsString('Database query timeout', (string) $responseContent);
        $this->assertStringNotContainsString('SELECT * FROM users', (string) $responseContent);
    }

    public function test_custom_api_exception_renders_extensions(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'en',
        ])->getJson('/api/v1/test/errors/custom-api-exception');

        $response->assertStatus(400);
        $response->assertJson([
            'status' => 400,
            'code' => 'INVALID_RESERVATION_STATE',
            'title' => 'Invalid Slot State',
            'detail' => 'The slot is no longer available.',
            'availableUntil' => '2026-11-24T12:00:00Z',
            'invalidParams' => [
                'slot_id' => ['Slot #42 has already expired'],
            ],
        ]);
    }

    public function test_incoming_request_id_header_is_preserved_as_trace_id(): void
    {
        $customId = 'trace-fixed-correlation-9988-uuid';

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Request-ID' => $customId,
        ])->getJson('/api/v1/test/errors/unauthorized');

        $this->assertEquals($customId, $response->headers->get('X-Request-ID'));
        $this->assertEquals($customId, $response->headers->get('X-Trace-ID'));
        $this->assertEquals($customId, $response->json('traceId'));
    }

    public function test_w3c_traceparent_header_is_extracted_correctly(): void
    {
        $traceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'traceparent' => $traceparent,
        ])->getJson('/api/v1/test/errors/unauthorized');

        $expectedTraceId = '4bf92f3577b34da6a3ce929d0e0e4736';
        $this->assertEquals($expectedTraceId, $response->headers->get('X-Request-ID'));
        $this->assertEquals($expectedTraceId, $response->json('traceId'));
    }

    public function test_malicious_or_oversized_trace_id_is_rejected_and_replaced_with_uuid(): void
    {
        $oversizedId = str_repeat('a', 200);

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Request-ID' => $oversizedId,
        ])->getJson('/api/v1/test/errors/unauthorized');

        $returnedId = $response->headers->get('X-Request-ID');
        $this->assertNotEquals($oversizedId, $returnedId);
        $this->assertLessThanOrEqual(64, strlen((string) $returnedId));
    }

    public function test_query_string_secrets_are_redacted_from_problem_instance_uri(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/v1/test/errors/unauthorized?page=2&token=super-secret-123&api_key=456&search=quran');

        $instance = $response->json('instance');
        $this->assertStringNotContainsString('super-secret-123', $instance);
        $this->assertStringNotContainsString('456', $instance);
        $this->assertStringContainsString('token=%5BREDACTED%5D', $instance);
        $this->assertStringContainsString('search=quran', $instance);
    }

    public function test_retry_after_header_is_preserved_on_rate_limit_response(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/v1/test/errors/rate-limited');

        $response->assertStatus(429);
        $this->assertEquals('120', $response->headers->get('Retry-After'));
    }
}
