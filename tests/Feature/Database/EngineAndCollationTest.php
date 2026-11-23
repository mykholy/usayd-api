<?php

namespace Tests\Feature\Database;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EngineAndCollationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_tables_use_innodb_engine(): void
    {
        $databaseName = DB::connection()->getDatabaseName();

        $tables = DB::select('
            SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
              AND TABLE_TYPE = "BASE TABLE"
        ', [$databaseName]);

        $this->assertNotEmpty($tables, 'Database must contain migrated tables.');

        foreach ($tables as $table) {
            $this->assertSame(
                'InnoDB',
                $table->ENGINE,
                "Table {$table->TABLE_NAME} must use the InnoDB storage engine."
            );

            $this->assertMatchesRegularExpression(
                '/^utf8mb4_/i',
                $table->TABLE_COLLATION,
                "Table {$table->TABLE_NAME} must use a utf8mb4 collation."
            );
        }
    }

    public function test_utf8mb4_stores_and_retrieves_arabic_quranic_text_with_tashkeel(): void
    {
        $arabicText = 'بِسْمِ ٱللَّهِ ٱلرَّحْمَٰنِ ٱلرَّحِيمِ - الحَمْدُ لِلَّهِ رَبِّ العَالَمِينَ';

        $event = AuditEvent::create([
            'subject_type' => 'quran_reference',
            'subject_id' => '1:1',
            'action' => 'verified_ayah_text',
            'purpose' => $arabicText,
            'metadata' => ['verse' => $arabicText, 'riwaya' => 'Hafs'],
        ]);

        $retrieved = AuditEvent::findOrFail($event->id);

        $this->assertSame($arabicText, $retrieved->purpose);
        $this->assertSame($arabicText, $retrieved->metadata['verse']);
    }

    public function test_utf8mb4_stores_and_retrieves_4_byte_emojis_without_corruption(): void
    {
        $emojiString = '📖 🕌 🌟 🎓';

        $user = User::create([
            'name' => 'Usayd Student '.$emojiString,
            'email' => 'student.emoji@example.com',
            'password' => 'secret123',
            'locale' => 'ar',
            'country' => 'SA',
            'timezone' => 'Asia/Riyadh',
            'status' => 'active',
        ]);

        $retrieved = User::findOrFail($user->id);

        $this->assertSame('Usayd Student '.$emojiString, $retrieved->name);
    }

    public function test_idempotency_keys_compound_unique_scoping(): void
    {
        // User 1 with key 'order-123' on 'api/v1/checkout'
        DB::table('idempotency_keys')->insert([
            'key' => 'order-123',
            'user_id' => 1,
            'route' => 'api/v1/checkout',
            'request_hash' => hash('sha256', 'payload-1'),
            'status' => 'in_flight',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // User 2 with identical key 'order-123' on 'api/v1/checkout' SUCCEEDS (no collision across users)
        DB::table('idempotency_keys')->insert([
            'key' => 'order-123',
            'user_id' => 2,
            'route' => 'api/v1/checkout',
            'request_hash' => hash('sha256', 'payload-2'),
            'status' => 'in_flight',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // User 1 on different route with identical key 'order-123' SUCCEEDS
        DB::table('idempotency_keys')->insert([
            'key' => 'order-123',
            'user_id' => 1,
            'route' => 'api/v1/refunds',
            'request_hash' => hash('sha256', 'payload-3'),
            'status' => 'in_flight',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Duplicate for User 1 on same route FAILS
        $this->expectException(QueryException::class);
        DB::table('idempotency_keys')->insert([
            'key' => 'order-123',
            'user_id' => 1,
            'route' => 'api/v1/checkout',
            'request_hash' => hash('sha256', 'payload-dup'),
            'status' => 'in_flight',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
