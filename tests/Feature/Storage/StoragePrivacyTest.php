<?php

namespace Tests\Feature\Storage;

use App\Models\LearningPlan;
use App\Models\User;
use App\Services\Storage\SignedUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoragePrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_learner_can_generate_signed_url_for_own_recitation_file(): void
    {
        $learner = User::factory()->create(['role' => 'learner']);
        Sanctum::actingAs($learner, ['access-api']);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/v1/storage/signed-url', [
                'path' => "recitations/{$learner->id}/surah_001.mp3",
                'expires_in' => 900,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data' => [
                'path' => "recitations/{$learner->id}/surah_001.mp3",
                'expires_in' => 900,
                'disk' => 'private',
            ],
        ]);

        $url = $response->json('data.url');
        $this->assertNotEmpty($url);
        $this->assertStringContainsString('signature=', $url);

        // Verify audit event was logged
        $this->assertDatabaseHas('audit_events', [
            'action' => 'storage.signed_url_generated',
            'subject_type' => 'storage_signed_url',
            'subject_id' => "recitations/{$learner->id}/surah_001.mp3",
            'actor_id' => (string) $learner->id,
        ]);
    }

    public function test_learner_cannot_generate_signed_url_for_another_learner_file_returns_403(): void
    {
        $learnerA = User::factory()->create(['role' => 'learner']);
        $learnerB = User::factory()->create(['role' => 'learner']);
        Sanctum::actingAs($learnerA, ['access-api']);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/v1/storage/signed-url', [
                'path' => "recitations/{$learnerB->id}/private_recitation.mp3",
                'owner_id' => $learnerB->id,
            ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
        $response->assertJson([
            'status' => 403,
            'code' => 'FORBIDDEN',
        ]);
    }

    public function test_teacher_can_generate_signed_url_for_assigned_student_file(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'learner']);

        LearningPlan::create([
            'user_id' => $student->id,
            'teacher_id' => $teacher->id,
            'title' => 'Tajweed Coaching',
            'status' => 'active',
        ]);

        Sanctum::actingAs($teacher, ['access-api']);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/v1/storage/signed-url', [
                'path' => "recitations/{$student->id}/coached_audio.mp3",
                'owner_id' => $student->id,
            ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);
    }

    public function test_teacher_cannot_generate_signed_url_for_unassigned_student_returns_403(): void
    {
        $teacherA = User::factory()->create(['role' => 'teacher']);
        $teacherB = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'learner']);

        LearningPlan::create([
            'user_id' => $student->id,
            'teacher_id' => $teacherB->id, // Assigned to Teacher B only
            'title' => 'Assigned to B',
            'status' => 'active',
        ]);

        Sanctum::actingAs($teacherA, ['access-api']);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/v1/storage/signed-url', [
                'path' => "recitations/{$student->id}/unassigned_audio.mp3",
                'owner_id' => $student->id,
            ]);

        $response->assertStatus(403);
        $response->assertJson(['status' => 403, 'code' => 'FORBIDDEN']);
    }

    public function test_academic_supervisor_or_admin_can_generate_signed_url_for_any_student(): void
    {
        $supervisor = User::factory()->create(['role' => 'academic']);
        $student = User::factory()->create(['role' => 'learner']);

        Sanctum::actingAs($supervisor, ['access-api']);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/v1/storage/signed-url', [
                'path' => "recitations/{$student->id}/supervised_audio.mp3",
                'owner_id' => $student->id,
            ]);

        $response->assertStatus(200);
    }

    public function test_valid_signed_url_downloads_file_successfully(): void
    {
        $learner = User::factory()->create(['role' => 'learner']);
        Sanctum::actingAs($learner, ['access-api']);

        $filePath = "recitations/{$learner->id}/test_recitation.mp3";
        $fileContent = 'MOCK_AUDIO_BINARY_STREAM_CONTENT_QURAN_VERSES';
        Storage::disk('private')->put($filePath, $fileContent);

        // 1. Generate signed URL
        $genResponse = $this->postJson('/api/v1/storage/signed-url', [
            'path' => $filePath,
        ]);
        $genResponse->assertStatus(200);

        $url = $genResponse->json('data.url');
        $parsed = parse_url($url);
        parse_str($parsed['query'], $queryParams);

        // 2. Fetch download endpoint using generated query parameters
        $downloadResponse = $this->get("/api/v1/storage/download?{$parsed['query']}");
        $downloadResponse->assertStatus(200);
        $downloadResponse->assertStreamedContent($fileContent);

        // 3. Verify audit trail logged file download
        $this->assertDatabaseHas('audit_events', [
            'action' => 'storage.file_downloaded',
            'subject_type' => 'storage_file',
            'subject_id' => $filePath,
        ]);
    }

    public function test_expired_signed_url_returns_403_problem_details(): void
    {
        $service = app(SignedUrlService::class);
        $path = 'recitations/1/expired.mp3';
        $expiredTime = now()->subMinutes(10)->timestamp;
        $signature = $service->createSignature($path, $expiredTime, null, 'private');

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->getJson("/api/v1/storage/download?path={$path}&expires={$expiredTime}&signature={$signature}&disk=private");

        $response->assertStatus(403);
        $this->assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('expired', (string) $response->json('detail'));
    }

    public function test_tampered_signature_or_path_returns_403_problem_details(): void
    {
        $service = app(SignedUrlService::class);
        $path = 'recitations/1/audio.mp3';
        $expires = now()->addMinutes(15)->timestamp;
        $signature = $service->createSignature($path, $expires, null, 'private');

        // Tamper with signature
        $tamperedSignature = substr($signature, 0, -4).'AAAA';

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->getJson("/api/v1/storage/download?path={$path}&expires={$expires}&signature={$tamperedSignature}&disk=private");

        $response->assertStatus(403);
        $this->assertStringContainsString('invalid or parameters have been tampered with', (string) $response->json('detail'));
    }

    public function test_path_traversal_attempts_are_blocked_with_400_problem_details(): void
    {
        $learner = User::factory()->create(['role' => 'learner']);
        Sanctum::actingAs($learner, ['access-api']);

        $traversalPaths = [
            '../../etc/passwd',
            '..\\..\\windows\\system32\\cmd.exe',
            'recitations/../../../secret.env',
            "audio\0nullbyte.mp3",
        ];

        foreach ($traversalPaths as $badPath) {
            $response = $this->withHeaders(['Accept' => 'application/json'])
                ->postJson('/api/v1/storage/signed-url', [
                    'path' => $badPath,
                ]);

            $response->assertStatus(400);
            $this->assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
        }
    }

    public function test_missing_file_on_disk_returns_404_problem_details(): void
    {
        $service = app(SignedUrlService::class);
        $path = 'recitations/1/ghost_file.mp3';
        $expires = now()->addMinutes(15)->timestamp;
        $signature = $service->createSignature($path, $expires, null, 'private');

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->getJson("/api/v1/storage/download?path={$path}&expires={$expires}&signature={$signature}&disk=private");

        $response->assertStatus(404);
        $this->assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('not found', (string) $response->json('detail'));
    }
}
