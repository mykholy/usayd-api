<?php

namespace Tests\Feature\Database;

use App\Models\AuditEvent;
use App\Models\FeatureFlag;
use App\Models\OutboxMessage;
use App\Models\SettingsVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JsonSchemaAndValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_columns_store_and_query_nested_payloads(): void
    {
        $message = OutboxMessage::create([
            'aggregate_type' => 'enrollment',
            'aggregate_id' => 'enr-100',
            'event_type' => 'learner.enrolled',
            'payload' => [
                'learner_id' => 'lrn-42',
                'program' => [
                    'slug' => 'quran-memorization',
                    'level' => 'intermediate',
                ],
                'pricing' => [
                    'amount_minor' => 15000,
                    'currency' => 'SAR',
                ],
            ],
            'headers' => [
                'correlation_id' => 'corr-xyz-99',
            ],
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('outbox_messages', [
            'id' => $message->id,
            'aggregate_type' => 'enrollment',
        ]);

        // Query using MySQL 8.4 JSON path extract operator (->>)
        $retrieved = DB::table('outbox_messages')
            ->where('payload->program->slug', 'quran-memorization')
            ->first();

        $this->assertNotNull($retrieved);
        $this->assertSame($message->id, $retrieved->id);

        // Test JSON array / scalar extraction
        $currency = DB::selectOne("
            SELECT payload->>'$.pricing.currency' as currency
            FROM outbox_messages
            WHERE id = ?
        ", [$message->id])->currency;

        $this->assertSame('SAR', $currency);
    }

    public function test_settings_versions_json_schema_integrity(): void
    {
        $settingsData = [
            'classroom' => [
                'max_participants' => 1,
                'recording_enabled' => false,
                'provider' => 'agora',
            ],
            'notifications' => [
                'channels' => ['email', 'in_app'],
                'quiet_hours' => ['start' => '22:00', 'end' => '06:00'],
            ],
        ];

        $version = SettingsVersion::create([
            'namespace' => 'academic_policy',
            'scope' => 'global',
            'schema_version' => '1.0',
            'settings' => $settingsData,
            'status' => 'active',
            'checksum' => hash('sha256', json_encode($settingsData)),
        ]);

        $retrieved = SettingsVersion::findOrFail($version->id);
        $this->assertSame(1, $retrieved->settings['classroom']['max_participants']);
        $this->assertSame(['email', 'in_app'], $retrieved->settings['notifications']['channels']);
    }

    public function test_feature_flags_json_rules(): void
    {
        $flag = FeatureFlag::create([
            'key' => 'guardian_consent_v1',
            'name' => 'Guardian Verification Flow v1',
            'description' => 'Gates minor guardian verification before onboarding',
            'is_enabled' => true,
            'rules' => [
                'eligible_countries' => ['SA', 'AE', 'KW'],
                'min_age' => 7,
                'max_age' => 17,
            ],
        ]);

        $this->assertDatabaseHas('feature_flags', [
            'key' => 'guardian_consent_v1',
            'is_enabled' => true,
        ]);

        $found = FeatureFlag::where('key', 'guardian_consent_v1')
            ->whereJsonContains('rules->eligible_countries', 'SA')
            ->first();

        $this->assertNotNull($found);
    }

    public function test_negative_invalid_json_string_is_rejected_by_mysql(): void
    {
        $event = AuditEvent::create([
            'subject_type' => 'test',
            'subject_id' => '1',
            'action' => 'init',
            'metadata' => ['valid' => true],
        ]);

        $this->expectException(QueryException::class);

        // Directly updating JSON column with malformed non-JSON data must be rejected by MySQL 8.4
        DB::statement('UPDATE audit_events SET metadata = "NOT_A_VALID_JSON_OBJECT" WHERE id = ?', [$event->id]);
    }
}
