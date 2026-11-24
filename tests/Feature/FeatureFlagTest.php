<?php

namespace Tests\Feature;

use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\FeatureFlagService;
use Database\Seeders\FeatureFlagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear cached flags
        foreach (array_keys(FeatureFlagService::DEFAULT_FLAGS) as $key) {
            FeatureFlagService::clearCache($key);
        }
    }

    public function test_feature_flag_defaults_match_owner_decisions(): void
    {
        $this->assertTrue(FeatureFlagService::isEnabled('feature_waitlist_only'));
        $this->assertFalse(FeatureFlagService::isEnabled('feature_paid_learning_minors'));
        $this->assertFalse(FeatureFlagService::isEnabled('feature_in_app_video'));
        $this->assertFalse(FeatureFlagService::isEnabled('feature_ai_analysis'));
        $this->assertFalse(FeatureFlagService::isEnabled('feature_recording'));
        $this->assertFalse(FeatureFlagService::isEnabled('feature_public_pricing'));
        $this->assertFalse(FeatureFlagService::isEnabled('feature_safeguarding_break_glass'));
    }

    public function test_middleware_blocks_disabled_feature_with_503_rfc7807(): void
    {
        FeatureFlag::query()->updateOrCreate(
            ['key' => 'feature_in_app_video'],
            ['name' => 'Video', 'is_enabled' => false]
        );
        FeatureFlagService::clearCache('feature_in_app_video');

        $response = $this->getJson('/api/v1/test/errors/feature-protected');

        $response->assertStatus(503)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'FEATURE_DISABLED')
            ->assertJsonPath('invalidParams.feature', 'feature_in_app_video');

        $this->assertNotEmpty($response->json('traceId'));
    }

    public function test_middleware_blocks_disabled_feature_with_404_when_hidden_mode(): void
    {
        FeatureFlag::query()->updateOrCreate(
            ['key' => 'feature_in_app_video'],
            ['name' => 'Video', 'is_enabled' => false]
        );
        FeatureFlagService::clearCache('feature_in_app_video');

        $response = $this->getJson('/api/v1/test/errors/feature-hidden');

        $response->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_middleware_allows_request_when_feature_flag_is_enabled(): void
    {
        FeatureFlag::query()->updateOrCreate(
            ['key' => 'feature_in_app_video'],
            ['name' => 'Video', 'is_enabled' => true]
        );
        FeatureFlagService::clearCache('feature_in_app_video');

        $response = $this->getJson('/api/v1/test/errors/feature-protected');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'feature' => 'feature_in_app_video',
            ]);
    }

    public function test_dynamic_rules_filter_by_user_role(): void
    {
        FeatureFlag::query()->updateOrCreate(
            ['key' => 'feature_in_app_video'],
            [
                'name' => 'Video',
                'is_enabled' => true,
                'rules' => ['roles' => ['admin', 'teacher']],
            ]
        );
        FeatureFlagService::clearCache('feature_in_app_video');

        $learner = User::factory()->create(['role' => 'learner']);
        $admin = User::factory()->create(['role' => 'admin']);

        // Learner blocked with 503
        Sanctum::actingAs($learner, ['access-api']);
        $this->getJson('/api/v1/test/errors/feature-protected')
            ->assertStatus(503);

        // Admin allowed
        Sanctum::actingAs($admin, ['access-api']);
        $this->getJson('/api/v1/test/errors/feature-protected')
            ->assertStatus(200);
    }

    public function test_dynamic_rules_filter_by_user_country(): void
    {
        FeatureFlag::query()->updateOrCreate(
            ['key' => 'feature_in_app_video'],
            [
                'name' => 'Video',
                'is_enabled' => true,
                'rules' => ['countries' => ['SA', 'EG']],
            ]
        );
        FeatureFlagService::clearCache('feature_in_app_video');

        $usUser = User::factory()->create(['country' => 'US']);
        $saUser = User::factory()->create(['country' => 'SA']);

        Sanctum::actingAs($usUser, ['access-api']);
        $this->getJson('/api/v1/test/errors/feature-protected')
            ->assertStatus(503);

        Sanctum::actingAs($saUser, ['access-api']);
        $this->getJson('/api/v1/test/errors/feature-protected')
            ->assertStatus(200);
    }

    public function test_admin_can_list_feature_flags(): void
    {
        $this->seed(FeatureFlagSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin, ['access-api']);
        $response = $this->getJson('/api/v1/admin/feature-flags');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'key', 'name', 'is_enabled'],
                ],
            ]);

        $this->assertGreaterThanOrEqual(7, count($response->json('data')));
    }

    public function test_admin_index_is_strictly_read_only_and_does_not_mutate_database(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Database starts empty of flags
        $this->assertEquals(0, FeatureFlag::count());

        Sanctum::actingAs($admin, ['access-api']);
        $response = $this->getJson('/api/v1/admin/feature-flags');

        $response->assertStatus(200);
        $response->assertJson(['data' => []]);

        // Database must remain strictly unchanged (0 records)
        $this->assertEquals(0, FeatureFlag::count(), 'Admin GET must be strictly read-only and never write seed data');
    }

    public function test_admin_can_toggle_flag_with_audit_logging(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin, ['access-api']);
        $response = $this->putJson('/api/v1/admin/feature-flags/feature_recording', [
            'is_enabled' => true,
            'rules' => ['roles' => ['academic']],
        ], [
            'X-Audit-Reason' => 'Activating session recording trial for academic review',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.rules.roles.0', 'academic');

        $this->assertDatabaseHas('feature_flags', [
            'key' => 'feature_recording',
            'is_enabled' => 1,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'feature_flag.updated',
            'actor_id' => $admin->id,
            'purpose' => 'Activating session recording trial for academic review',
        ]);
    }

    public function test_admin_cannot_toggle_flag_without_audit_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin, ['access-api']);
        $response = $this->putJson('/api/v1/admin/feature-flags/feature_recording', [
            'is_enabled' => true,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_strict_rule_validation_rejects_invalid_schemas(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Invalid percentage (>100) and invalid country code (>2 chars)
        Sanctum::actingAs($admin, ['access-api']);
        $response = $this->putJson('/api/v1/admin/feature-flags/feature_recording', [
            'is_enabled' => true,
            'rules' => [
                'percentage' => 150,
                'countries' => ['INVALID_COUNTRY'],
                'roles' => ['invalid_role'],
            ],
        ], [
            'X-Audit-Reason' => 'Testing strict rule schema validation',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_non_admin_cannot_access_feature_flag_admin_endpoints(): void
    {
        $learner = User::factory()->create(['role' => 'learner']);

        Sanctum::actingAs($learner, ['access-api']);
        $this->getJson('/api/v1/admin/feature-flags')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->putJson('/api/v1/admin/feature-flags/feature_recording', [
            'is_enabled' => true,
        ], ['X-Audit-Reason' => 'Unauthorized attempt'])
            ->assertStatus(403);
    }

    public function test_cache_outage_fails_safe_without_server_error(): void
    {
        Cache::shouldReceive('get')->andThrow(new \Exception('Redis connection failure'));
        Cache::shouldReceive('lock')->andThrow(new \Exception('Redis lock failure'));

        // Non-critical flag falls back to default safely
        $enabled = FeatureFlagService::isEnabled('feature_waitlist_only');
        $this->assertTrue($enabled);

        // Critical flag fails closed safely
        $criticalEnabled = FeatureFlagService::isEnabled('feature_safeguarding_break_glass');
        $this->assertFalse($criticalEnabled);
    }
}
