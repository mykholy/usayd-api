<?php

namespace Tests\Feature\Concurrency;

use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdempotencyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_mutating_request_executes_and_saves_idempotency_record(): void
    {
        $payload = ['action' => 'wallet_credit', 'amount' => 500];

        $response = $this->withHeaders([
            'Idempotency-Key' => 'idem-test-key-001',
        ])->postJson('/api/v1/test/idempotent-mutation', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'status' => 'success',
            'action' => 'wallet_credit',
            'amount' => 500,
        ]);

        $this->assertFalse($response->headers->has('Idempotent-Replayed'));

        // Verify mutation record created
        $this->assertEquals(1, DB::table('audit_events')->where('subject_type', 'test_mutation')->count());

        // Verify idempotency record saved as completed
        $this->assertDatabaseHas('idempotency_keys', [
            'key' => 'idem-test-key-001',
            'status' => 'completed',
            'response_code' => 201,
        ]);
    }

    public function test_duplicate_request_returns_cached_response_with_idempotent_replayed_header(): void
    {
        $payload = ['action' => 'seat_reservation', 'amount' => 1];

        // 1. Initial request
        $firstResponse = $this->withHeaders([
            'Idempotency-Key' => 'idem-test-key-002',
        ])->postJson('/api/v1/test/idempotent-mutation', $payload);

        $firstResponse->assertStatus(201);
        $firstMutationId = $firstResponse->json('mutation_id');
        $this->assertNotNull($firstMutationId);
        $this->assertEquals(1, DB::table('audit_events')->where('subject_type', 'test_mutation')->count());

        // 2. Duplicate request with identical key and payload
        $secondResponse = $this->withHeaders([
            'Idempotency-Key' => 'idem-test-key-002',
        ])->postJson('/api/v1/test/idempotent-mutation', $payload);

        $secondResponse->assertStatus(201);
        $secondResponse->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($firstMutationId, $secondResponse->json('mutation_id'));

        // Ensure no duplicate mutation was recorded
        $this->assertEquals(1, DB::table('audit_events')->where('subject_type', 'test_mutation')->count());
    }

    public function test_cross_account_idempotency_isolation_prevents_cache_leakage(): void
    {
        $user1 = User::create([
            'name' => 'User One',
            'email' => 'user1@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $user2 = User::create([
            'name' => 'User Two',
            'email' => 'user2@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $sharedKey = 'shared-idempotency-key-001';

        // 1. User 1 submits mutation
        $responseUser1 = $this->actingAs($user1)->withHeaders([
            'Idempotency-Key' => $sharedKey,
        ])->postJson('/api/v1/test/idempotent-mutation', [
            'action' => 'user1_action',
            'amount' => 100,
        ]);
        $responseUser1->assertStatus(201);
        $mut1 = $responseUser1->json('mutation_id');

        // 2. User 2 submits with identical key: MUST NOT return User 1's cached response
        $responseUser2 = $this->actingAs($user2)->withHeaders([
            'Idempotency-Key' => $sharedKey,
        ])->postJson('/api/v1/test/idempotent-mutation', [
            'action' => 'user2_action',
            'amount' => 200,
        ]);
        $responseUser2->assertStatus(201);
        $mut2 = $responseUser2->json('mutation_id');

        // Verify mutations are distinct and isolated
        $this->assertNotSame($mut1, $mut2);
        $this->assertFalse($responseUser2->headers->has('Idempotent-Replayed'));
        $this->assertEquals(2, DB::table('audit_events')->where('subject_type', 'test_mutation')->count());
    }

    public function test_failed_idempotency_record_allows_safe_retry(): void
    {
        $payload = ['action' => 'retry_action', 'amount' => 50];
        $key = 'retry-key-001';

        // Seed a failed idempotency record
        IdempotencyKey::create([
            'key' => $key,
            'user_id' => 0,
            'route' => 'api/v1/test/idempotent-mutation',
            'request_hash' => hash('sha256', '0|POST|api/v1/test/idempotent-mutation|'.json_encode($payload)),
            'status' => 'failed',
            'expires_at' => now()->addHours(24),
        ]);

        // Retrying with same key should succeed and record completed
        $response = $this->withHeaders([
            'Idempotency-Key' => $key,
        ])->postJson('/api/v1/test/idempotent-mutation', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('idempotency_keys', [
            'key' => $key,
            'status' => 'completed',
        ]);
    }

    public function test_negative_payload_mismatch_returns_422_problem_details(): void
    {
        // 1. Initial request
        $this->withHeaders([
            'Idempotency-Key' => 'idem-test-key-003',
        ])->postJson('/api/v1/test/idempotent-mutation', [
            'action' => 'original_action',
            'amount' => 10,
        ])->assertStatus(201);

        // 2. Subsequent request with same key but different payload
        $mismatchResponse = $this->withHeaders([
            'Idempotency-Key' => 'idem-test-key-003',
        ])->postJson('/api/v1/test/idempotent-mutation', [
            'action' => 'tampered_action',
            'amount' => 9999,
        ]);

        $mismatchResponse->assertStatus(422);
        $mismatchResponse->assertHeader('Content-Type', 'application/problem+json');
        $mismatchResponse->assertJson([
            'type' => 'https://errors.usayd.academy/idempotency/payload-mismatch',
            'title' => 'Idempotency Payload Mismatch',
            'status' => 422,
        ]);

        // Still only 1 mutation
        $this->assertEquals(1, DB::table('audit_events')->where('subject_type', 'test_mutation')->count());
    }

    public function test_negative_in_flight_concurrent_request_returns_409_conflict(): void
    {
        $payload = ['action' => 'slow_process', 'amount' => 200];
        $requestHash = hash('sha256', '0|POST|api/v1/test/idempotent-mutation|'.json_encode($payload));

        // Seed an in-flight key
        IdempotencyKey::create([
            'key' => 'idem-in-flight-key',
            'user_id' => 0,
            'route' => 'api/v1/test/idempotent-mutation',
            'request_hash' => $requestHash,
            'status' => 'in_flight',
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->withHeaders([
            'Idempotency-Key' => 'idem-in-flight-key',
        ])->postJson('/api/v1/test/idempotent-mutation', $payload);

        $response->assertStatus(409);
        $response->assertHeader('Content-Type', 'application/problem+json');
        $response->assertJson([
            'type' => 'https://errors.usayd.academy/idempotency/request-in-flight',
            'title' => 'Concurrent Request In Flight',
            'status' => 409,
        ]);
    }

    public function test_negative_invalid_key_format_returns_400(): void
    {
        // Key with spaces / invalid characters
        $response = $this->withHeaders([
            'Idempotency-Key' => 'invalid key with spaces',
        ])->postJson('/api/v1/test/idempotent-mutation', [
            'action' => 'test',
            'amount' => 1,
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'type' => 'https://errors.usayd.academy/idempotency/invalid-key',
            'title' => 'Invalid Idempotency Key',
            'status' => 400,
        ]);
    }

    public function test_get_request_ignores_idempotency_key(): void
    {
        $response = $this->withHeaders([
            'Idempotency-Key' => 'get-request-key',
        ])->getJson('/api/v1/health');

        $response->assertStatus(200);
        $this->assertDatabaseMissing('idempotency_keys', [
            'key' => 'get-request-key',
        ]);
    }
}
