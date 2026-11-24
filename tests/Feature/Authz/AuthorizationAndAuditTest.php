<?php

namespace Tests\Feature\Authz;

use App\Models\AuditEvent;
use App\Models\LearningPlan;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AuthorizationAndAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_learner_can_view_own_learning_plan(): void
    {
        $learner = User::factory()->create(['role' => 'learner']);
        $plan = LearningPlan::create([
            'user_id' => $learner->id,
            'title' => 'Tahfeez Level 1',
            'status' => 'active',
            'curriculum_level' => 'foundation',
        ]);

        Sanctum::actingAs($learner, ['access-api']);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->getJson("/api/v1/learning-plans/{$plan->id}");

        $response->assertStatus(200);
        $response->assertJson(['data' => ['id' => $plan->id, 'title' => 'Tahfeez Level 1']]);
    }

    public function test_learner_cannot_view_other_learner_plan_returns_403_problem_details(): void
    {
        $learnerA = User::factory()->create(['role' => 'learner']);
        $learnerB = User::factory()->create(['role' => 'learner']);
        $plan = LearningPlan::create([
            'user_id' => $learnerB->id,
            'title' => 'Private Plan',
            'status' => 'active',
        ]);

        Sanctum::actingAs($learnerA, ['access-api']);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->getJson("/api/v1/learning-plans/{$plan->id}");

        $response->assertStatus(403);
        $this->assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
        $response->assertJson([
            'status' => 403,
            'code' => 'FORBIDDEN',
        ]);
    }

    public function test_teacher_can_view_assigned_student_plan(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'learner']);
        $plan = LearningPlan::create([
            'user_id' => $student->id,
            'teacher_id' => $teacher->id,
            'title' => 'Assigned Tajweed Plan',
            'status' => 'active',
        ]);

        Sanctum::actingAs($teacher, ['access-api']);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->getJson("/api/v1/learning-plans/{$plan->id}");

        $response->assertStatus(200);
        $response->assertJson(['data' => ['id' => $plan->id]]);
    }

    public function test_teacher_cannot_view_unassigned_student_plan_returns_403(): void
    {
        $teacherA = User::factory()->create(['role' => 'teacher']);
        $teacherB = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'learner']);
        $plan = LearningPlan::create([
            'user_id' => $student->id,
            'teacher_id' => $teacherB->id, // Assigned to Teacher B
            'title' => 'Teacher B Plan',
            'status' => 'active',
        ]);

        Sanctum::actingAs($teacherA, ['access-api']); // Teacher A tries to view

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->getJson("/api/v1/learning-plans/{$plan->id}");

        $response->assertStatus(403);
        $response->assertJson(['status' => 403, 'code' => 'FORBIDDEN']);
    }

    public function test_academic_supervisor_can_view_any_plan(): void
    {
        $academic = User::factory()->create(['role' => 'academic']);
        $student = User::factory()->create(['role' => 'learner']);
        $plan = LearningPlan::create([
            'user_id' => $student->id,
            'title' => 'Supervised Plan',
            'status' => 'active',
        ]);

        Sanctum::actingAs($academic, ['access-api']);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->getJson("/api/v1/learning-plans/{$plan->id}");

        $response->assertStatus(200);
    }

    public function test_support_or_finance_role_denied_access_returns_403(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $student = User::factory()->create(['role' => 'learner']);
        $plan = LearningPlan::create([
            'user_id' => $student->id,
            'title' => 'Academic Plan',
            'status' => 'active',
        ]);

        Sanctum::actingAs($support, ['access-api']);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->getJson("/api/v1/learning-plans/{$plan->id}");

        $response->assertStatus(403);
    }

    public function test_privileged_override_without_reason_metadata_returns_403(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'learner']);
        $plan = LearningPlan::create([
            'user_id' => $student->id,
            'title' => 'Plan for override',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin, ['access-api']);

        // Attempt override without X-Audit-Reason or reason payload
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson("/api/v1/learning-plans/{$plan->id}/override");

        $response->assertStatus(403);
        $this->assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
        $response->assertJson([
            'status' => 403,
            'code' => 'FORBIDDEN',
        ]);
        $this->assertStringContainsString('reason metadata is required', (string) $response->json('detail'));
    }

    public function test_privileged_override_with_reason_succeeds_and_creates_audit_event(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'learner']);
        $plan = LearningPlan::create([
            'user_id' => $student->id,
            'title' => 'Plan for override',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin, ['access-api']);

        $reason = 'Academic council approved accelerated completion exception';

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Audit-Reason' => $reason,
        ])->postJson("/api/v1/learning-plans/{$plan->id}/override");

        $response->assertStatus(200);
        $this->assertEquals('completed', $plan->fresh()->status);

        // Verify immutable audit event
        $this->assertDatabaseHas('audit_events', [
            'action' => 'learning_plan.administrative_override',
            'subject_type' => 'learning_plan',
            'subject_id' => (string) $plan->id,
            'purpose' => $reason,
            'actor_id' => (string) $admin->id,
        ]);
    }

    public function test_audit_event_is_append_only_and_eloquent_update_and_delete_are_blocked(): void
    {
        $event = AuditLogger::log(
            action: 'test.immutable_record',
            subjectType: 'account',
            subjectId: 999,
            purpose: 'Immutability guarantee verification'
        );

        $this->assertNotNull($event->id);

        // 1. Eloquent update must throw RuntimeException
        $updateThrew = false;
        try {
            $event->update(['action' => 'tampered.action']);
        } catch (RuntimeException $e) {
            $updateThrew = true;
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->assertTrue($updateThrew, 'Expected RuntimeException when updating AuditEvent via Eloquent');

        // 2. Eloquent delete must throw RuntimeException
        $deleteThrew = false;
        try {
            $event->delete();
        } catch (RuntimeException $e) {
            $deleteThrew = true;
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->assertTrue($deleteThrew, 'Expected RuntimeException when deleting AuditEvent via Eloquent');

        // Record must remain intact
        $fresh = AuditEvent::find($event->id);
        $this->assertNotNull($fresh);
        $this->assertEquals('test.immutable_record', $fresh->action);
    }

    public function test_audit_event_query_builder_and_raw_sql_limitations_are_documented_and_verified(): void
    {
        $event = AuditLogger::log(
            action: 'test.limitation_check',
            subjectType: 'account',
            subjectId: 1001,
            purpose: 'Original Purpose'
        );

        // Documented Limitation Verification:
        // Eloquent model lifecycle hooks intercept Eloquent calls only;
        // Query builder and raw SQL bypass model hooks unless an infrastructure least-privilege DB role is used.
        DB::table('audit_events')
            ->where('id', $event->id)
            ->update(['purpose' => 'QueryBuilderModified']);

        $updatedRecord = DB::table('audit_events')->where('id', $event->id)->first();
        $this->assertEquals(
            'QueryBuilderModified',
            $updatedRecord->purpose,
            'Confirms documented limitation: query builder bypasses Eloquent lifecycle hooks, necessitating least-privilege DB user roles in production.'
        );

        // Raw SQL bypasses Eloquent lifecycle hooks as well
        DB::statement('UPDATE audit_events SET purpose = ? WHERE id = ?', ['RawSqlModified', $event->id]);
        $rawUpdatedRecord = DB::table('audit_events')->where('id', $event->id)->first();
        $this->assertEquals('RawSqlModified', $rawUpdatedRecord->purpose);
    }
}
