<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Identity\Application\Ports\TokenManager;
use App\Services\Auth\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_registration_returns_auth_token_envelope(): void
    {
        $payload = [
            'name' => 'Usayd Student',
            'email' => 'student@usayd.academy',
            'password' => 'SecurePass123!',
            'phone' => '+201012345678',
            'locale' => 'ar',
            'device_name' => 'iphone-15-pro',
        ];

        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'access_token',
                'expires_in',
                'refresh_token',
                'token_type',
            ],
        ]);

        $this->assertEquals(3600, $response->json('data.expires_in'));
        $this->assertEquals('Bearer', $response->json('data.token_type'));

        // Database assertions
        $this->assertDatabaseHas('users', [
            'email' => 'student@usayd.academy',
            'locale' => 'ar',
            'country' => 'EG',
            'status' => 'active',
        ]);

        $user = User::where('email', 'student@usayd.academy')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('SecurePass123!', $user->password));

        // Audit trail assertion
        $this->assertDatabaseHas('audit_events', [
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'auth.register.success',
        ]);
    }

    public function test_registration_validation_failure_returns_rfc7807_problem_details_422(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->postJson('/api/v1/auth/register', [
            'name' => 'Short',
            'email' => 'invalid-email-address',
            'password' => 'short', // less than 8 chars
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
        $response->assertJson([
            'status' => 422,
            'code' => 'VALIDATION_FAILED',
        ]);
        $response->assertJsonStructure([
            'invalidParams' => ['email', 'password'],
        ]);
    }

    public function test_mobile_login_returns_token_pair(): void
    {
        $user = User::factory()->create([
            'email' => 'verified@usayd.academy',
            'password' => Hash::make('CorrectPassword999'),
        ]);

        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->postJson('/api/v1/auth/login', [
            'email' => 'verified@usayd.academy',
            'password' => 'CorrectPassword999',
            'device_name' => 'android-pixel-8',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'access_token',
                'expires_in',
                'refresh_token',
                'token_type',
            ],
        ]);

        $this->assertDatabaseHas('audit_events', [
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'auth.login.token',
        ]);
    }

    public function test_login_invalid_password_returns_401(): void
    {
        User::factory()->create([
            'email' => 'user@usayd.academy',
            'password' => Hash::make('CorrectPassword'),
        ]);

        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->postJson('/api/v1/auth/login', [
            'email' => 'user@usayd.academy',
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 401,
            'code' => 'UNAUTHORIZED',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'subject_type' => 'auth',
            'subject_id' => 'user@usayd.academy',
            'action' => 'auth.login.failed',
        ]);
    }

    public function test_login_rate_limiting_triggers_429_after_consecutive_failures(): void
    {
        User::factory()->create([
            'email' => 'bruteforce@usayd.academy',
            'password' => Hash::make('CorrectPassword'),
        ]);

        // Attempt 5 failed logins
        for ($i = 0; $i < 5; $i++) {
            $response = $this->withHeaders([
                'Accept' => 'application/json',
            ])->postJson('/api/v1/auth/login', [
                'email' => 'bruteforce@usayd.academy',
                'password' => 'BadPass'.$i,
            ]);
            $response->assertStatus(401);
        }

        // 6th attempt must be throttled with RFC 7807 429
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->postJson('/api/v1/auth/login', [
            'email' => 'bruteforce@usayd.academy',
            'password' => 'BadPass6',
        ]);

        $response->assertStatus(429);
        $response->assertJson([
            'status' => 429,
            'code' => 'RATE_LIMITED',
        ]);
        $this->assertNotNull($response->json('retryAfter'));

        $this->assertDatabaseHas('audit_events', [
            'subject_type' => 'auth',
            'subject_id' => 'bruteforce@usayd.academy',
            'action' => 'auth.login.throttled',
        ]);
    }

    public function test_token_refresh_rotates_tokens_and_invalidates_consumed_token(): void
    {
        $user = User::factory()->create();
        $tokenService = app(TokenService::class);
        $initialTokens = $tokenService->issueTokens($user, 'test-phone');

        // First rotation with valid refresh token
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $initialTokens['refresh_token'],
            'device_name' => 'test-phone',
        ]);

        $response->assertStatus(200);
        $newTokens = $response->json('data');
        $this->assertNotEmpty($newTokens['access_token']);
        $this->assertNotEmpty($newTokens['refresh_token']);
        $this->assertNotEquals($initialTokens['refresh_token'], $newTokens['refresh_token']);

        // Single-use token rotation: Replaying the old refresh token must be rejected with 401
        $replayResponse = $this->withHeaders([
            'Accept' => 'application/json',
        ])->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $initialTokens['refresh_token'],
        ]);

        $replayResponse->assertStatus(401);
        $replayResponse->assertJson([
            'status' => 401,
            'code' => 'UNAUTHORIZED',
        ]);
    }

    public function test_refresh_with_access_token_is_rejected_with_403(): void
    {
        $user = User::factory()->create();
        $tokenService = app(TokenService::class);
        $tokens = $tokenService->issueTokens($user);

        // Attempting to refresh using an access_token (which only has 'access-api' ability)
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $tokens['access_token'],
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'status' => 403,
            'code' => 'FORBIDDEN',
        ]);
    }

    public function test_refresh_only_token_cannot_access_api_endpoints_and_returns_403(): void
    {
        $user = User::factory()->create();
        $tokenService = app(TokenService::class);
        $tokens = $tokenService->issueTokens($user);

        // Attempting to access protected profile endpoint using refresh_token (which lacks 'access-api')
        $responseAuthMe = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$tokens['refresh_token'],
        ])->getJson('/api/v1/auth/me');

        $responseAuthMe->assertStatus(403);
        $responseAuthMe->assertJson([
            'status' => 403,
            'code' => 'FORBIDDEN',
        ]);

        $responseMe = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$tokens['refresh_token'],
        ])->getJson('/api/v1/me');

        $responseMe->assertStatus(403);
        $responseMe->assertJson([
            'status' => 403,
            'code' => 'FORBIDDEN',
        ]);
    }

    public function test_authenticated_user_can_access_me_profile_conforming_to_openapi_contract(): void
    {
        $user = User::factory()->create([
            'name' => 'Ahmad Usayd',
            'email' => 'ahmad@usayd.academy',
            'locale' => 'ar',
            'timezone' => 'Africa/Cairo',
            'status' => 'active',
        ]);
        $tokenService = app(TokenService::class);
        $tokens = $tokenService->issueTokens($user);

        // Test /api/v1/me
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$tokens['access_token'],
        ])->getJson('/api/v1/me');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Verify exact contract structure matching openapi.yaml UserProfile schema
        $this->assertEquals((string) $user->id, $data['id']);
        $this->assertEquals('Ahmad Usayd', $data['name']);
        $this->assertEquals('ahmad@usayd.academy', $data['email']);
        $this->assertEquals('ar', $data['locale']);
        $this->assertEquals('Africa/Cairo', $data['timezone']);
        $this->assertIsArray($data['roles']);
        $this->assertEquals('verified', $data['verification_status']);

        // Test /api/v1/auth/me alias
        $aliasResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$tokens['access_token'],
        ])->getJson('/api/v1/auth/me');

        $aliasResponse->assertStatus(200);
        $this->assertEquals($data, $aliasResponse->json('data'));
    }

    public function test_unauthenticated_request_to_me_returns_401_problem_details(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 401,
            'code' => 'UNAUTHORIZED',
        ]);
    }

    public function test_logout_revokes_entire_device_token_family(): void
    {
        $user = User::factory()->create();
        $tokenService = app(TokenService::class);
        $tokens = $tokenService->issueTokens($user, 'iphone-15');

        // Confirm both access and refresh tokens exist for iphone-15
        $this->assertEquals(2, $user->tokens()->where('name', 'like', 'iphone-15:%')->count());

        $logoutResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$tokens['access_token'],
        ])->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200);
        $logoutResponse->assertJson(['status' => 'success']);

        // Both access token and refresh token of the device family must be revoked
        $this->assertNull(PersonalAccessToken::findToken($tokens['access_token']));
        $this->assertNull(PersonalAccessToken::findToken($tokens['refresh_token']));
        $this->assertEquals(0, $user->fresh()->tokens()->where('name', 'like', 'iphone-15:%')->count());

        // Reset auth guard cache between simulated requests in same test
        $this->app['auth']->forgetGuards();

        // Subsequent access with the revoked token must fail with 401
        $meResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$tokens['access_token'],
        ])->getJson('/api/v1/auth/me');

        $meResponse->assertStatus(401);
    }

    public function test_logout_all_devices_revokes_all_tokens(): void
    {
        $user = User::factory()->create();
        $tokenService = app(TokenService::class);
        $device1 = $tokenService->issueTokens($user, 'device-1');
        $device2 = $tokenService->issueTokens($user, 'device-2');

        $this->assertEquals(4, $user->tokens()->count()); // 2 access + 2 refresh

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$device1['access_token'],
        ])->postJson('/api/v1/auth/logout', [
            'all_devices' => true,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(0, $user->fresh()->tokens()->count());
    }

    public function test_inactive_account_cannot_log_in_or_refresh(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CorrectPassword')]);
        $tokens = app(TokenService::class)->issueTokens($user);
        $user->update(['status' => 'suspended']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword',
        ], ['Accept-Language' => 'ar'])->assertStatus(401)
            ->assertJsonPath('detail', 'البريد الإلكتروني أو كلمة المرور غير صحيحة.');

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ], ['Accept-Language' => 'en'])->assertStatus(401);
    }

    public function test_same_device_names_have_independent_token_families(): void
    {
        $user = User::factory()->create();
        $service = app(TokenService::class);
        $first = $service->issueTokens($user, 'mobile');
        $second = $service->issueTokens($user, 'mobile');

        $rotated = $service->rotateRefreshToken($first['refresh_token']);

        $this->assertNull(PersonalAccessToken::findToken($first['access_token']));
        $this->assertNotNull(PersonalAccessToken::findToken($second['access_token']));
        $this->assertNotNull(PersonalAccessToken::findToken($second['refresh_token']));
        $service->revokeDeviceToken($user, PersonalAccessToken::findToken($rotated['access_token']));
        $this->assertNull(PersonalAccessToken::findToken($rotated['refresh_token']));
        $this->assertNotNull(PersonalAccessToken::findToken($second['refresh_token']));
    }

    public function test_expired_refresh_token_cleanup_is_committed(): void
    {
        $user = User::factory()->create();
        $tokens = app(TokenService::class)->issueTokens($user);
        PersonalAccessToken::findToken($tokens['refresh_token'])->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertStatus(401);

        $this->assertNull(PersonalAccessToken::findToken($tokens['refresh_token']));
    }

    public function test_suspended_user_cannot_use_previously_issued_access_token(): void
    {
        $user = User::factory()->create();
        $tokens = app(TokenService::class)->issueTokens($user);
        $user->update(['status' => 'suspended']);

        $this->withToken($tokens['access_token'])->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    public function test_mobile_registration_rolls_back_when_token_issuance_fails(): void
    {
        $this->mock(TokenManager::class, function ($mock): void {
            $mock->shouldReceive('issue')->once()->andThrow(new \RuntimeException('Simulated token persistence failure'));
        });

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Rollback Test',
            'email' => 'rollback@example.test',
            'password' => 'ExamplePassword123',
        ])->assertStatus(500);

        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'auth.register.success']);
    }

    public function test_csrf_cookie_endpoint_is_accessible(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');
        $response->assertSuccessful();
    }
}
