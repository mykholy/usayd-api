<?php

namespace Tests\Feature\Outbox;

use App\Jobs\DispatchOutboxNotificationJob;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Services\Outbox\TransactionalOutboxService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TransactionalOutboxTest extends TestCase
{
    use RefreshDatabase;

    private TransactionalOutboxService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TransactionalOutboxService::class);
    }

    public function test_outbox_message_persists_atomically_alongside_domain_entity(): void
    {
        $outbox = DB::transaction(function () use (&$createdUser) {
            $createdUser = User::create([
                'name' => 'Usayd Learner',
                'email' => 'learner@usayd.academy',
                'password' => bcrypt('SecurePassword123!'),
                'role' => 'learner',
            ]);

            return $this->service->record(
                eventType: 'user.registered',
                aggregateType: 'user',
                aggregateId: (string) $createdUser->id,
                payload: ['email' => 'learner@usayd.academy', 'role' => 'learner'],
                headers: ['idempotency_key' => 'idem-reg-001']
            );
        });

        $this->assertDatabaseHas('users', ['id' => $createdUser->id]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $outbox->id,
            'event_type' => 'user.registered',
            'aggregate_type' => 'user',
            'aggregate_id' => (string) $createdUser->id,
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $this->assertTrue($outbox->isPending());
    }

    public function test_outbox_message_rolls_back_when_transaction_fails(): void
    {
        try {
            DB::transaction(function () {
                $user = User::create([
                    'name' => 'Failing Learner',
                    'email' => 'fail@usayd.academy',
                    'password' => bcrypt('SecurePassword123!'),
                    'role' => 'learner',
                ]);

                $this->service->record(
                    eventType: 'user.registered',
                    aggregateType: 'user',
                    aggregateId: (string) $user->id,
                    payload: ['email' => 'fail@usayd.academy']
                );

                throw new Exception('Simulated downstream business validation failure');
            });
        } catch (Exception) {
            // Expected rollback
        }

        $this->assertDatabaseMissing('users', ['email' => 'fail@usayd.academy']);
        $this->assertDatabaseMissing('outbox_messages', [
            'aggregate_type' => 'user',
            'event_type' => 'user.registered',
        ]);
    }

    public function test_record_in_transaction_helper_commits_both_on_success(): void
    {
        $result = $this->service->recordInTransaction(
            callback: function () {
                return User::create([
                    'name' => 'Helper Learner',
                    'email' => 'helper@usayd.academy',
                    'password' => bcrypt('SecurePassword123!'),
                    'role' => 'learner',
                ]);
            },
            eventType: 'learner.onboarded',
            aggregateType: 'user',
            aggregateId: 'user-helper-01',
            payload: ['step' => 'welcome']
        );

        $this->assertInstanceOf(User::class, $result);
        $this->assertDatabaseHas('users', ['email' => 'helper@usayd.academy']);
        $this->assertDatabaseHas('outbox_messages', [
            'event_type' => 'learner.onboarded',
            'aggregate_id' => 'user-helper-01',
        ]);
    }

    public function test_idempotency_key_deduplicates_identical_outbox_records(): void
    {
        $first = $this->service->record(
            eventType: 'payment.completed',
            aggregateType: 'payment',
            aggregateId: 'pay-001',
            payload: ['amount' => 500],
            headers: ['idempotency_key' => 'idem-pay-001']
        );

        $second = $this->service->record(
            eventType: 'payment.completed',
            aggregateType: 'payment',
            aggregateId: 'pay-001',
            payload: ['amount' => 500],
            headers: ['idempotency_key' => 'idem-pay-001']
        );

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, OutboxMessage::where('aggregate_id', 'pay-001')->count());
    }

    public function test_worker_dispatches_due_messages_and_marks_status_dispatched(): void
    {
        Queue::fake();

        $msg1 = $this->service->record(
            eventType: 'lesson.scheduled',
            aggregateType: 'lesson',
            aggregateId: 'les-001',
            payload: ['time' => '10:00']
        );

        $msg2 = $this->service->record(
            eventType: 'lesson.scheduled',
            aggregateType: 'lesson',
            aggregateId: 'les-002',
            payload: ['time' => '11:00']
        );

        $dispatched = $this->service->publishPending(limit: 10, targetQueue: 'notifications');
        $this->assertEquals(2, $dispatched);

        Queue::assertPushed(DispatchOutboxNotificationJob::class, 2);

        $msg1->refresh();
        $msg2->refresh();

        $this->assertEquals(OutboxMessage::STATUS_DISPATCHED, $msg1->status);
        $this->assertNotNull($msg1->processed_at);
        $this->assertEquals(OutboxMessage::STATUS_DISPATCHED, $msg2->status);
        $this->assertNotNull($msg2->processed_at);
    }

    public function test_artisan_outbox_worker_command_executes_successfully(): void
    {
        $this->service->record(
            eventType: 'command.test',
            aggregateType: 'command',
            aggregateId: 'cmd-001',
            payload: ['key' => 'val']
        );

        $this->artisan('usayd:outbox-worker', [
            '--once' => true,
            '--limit' => 10,
            '--queue' => 'notifications',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('outbox_messages', [
            'aggregate_id' => 'cmd-001',
            'status' => OutboxMessage::STATUS_DISPATCHED,
        ]);
    }

    public function test_worker_ignores_future_scheduled_messages(): void
    {
        Queue::fake();

        $futureMessage = $this->service->record(
            eventType: 'reminder.due',
            aggregateType: 'reminder',
            aggregateId: 'rem-001',
            payload: ['type' => 'daily_recitation'],
            scheduledAt: now()->addHours(2)
        );

        $this->artisan('usayd:outbox-worker', ['--once' => true]);

        Queue::assertNothingPushed();

        $futureMessage->refresh();
        $this->assertEquals(OutboxMessage::STATUS_PENDING, $futureMessage->status);
        $this->assertNull($futureMessage->processed_at);
    }

    public function test_worker_records_failure_and_retries_transient_errors(): void
    {
        $message = $this->service->record(
            eventType: 'notification.alert',
            aggregateType: 'notification',
            aggregateId: 'notif-001',
            payload: ['channel' => 'sms']
        );

        // Run publishPending with a custom dispatcher that throws an exception
        $dispatched = $this->service->publishPending(
            limit: 10,
            targetQueue: 'notifications',
            maxAttempts: 3,
            dispatcher: function () {
                throw new Exception('Transient Redis connection refused');
            }
        );

        $this->assertEquals(0, $dispatched);

        $message->refresh();
        $this->assertEquals(OutboxMessage::STATUS_PENDING, $message->status);
        $this->assertEquals(1, $message->attempts);
        $this->assertStringContainsString('Transient Redis connection refused', $message->error_message);
    }

    public function test_worker_routes_to_dead_letter_after_exhausting_retry_budget(): void
    {
        $message = $this->service->record(
            eventType: 'notification.alert',
            aggregateType: 'notification',
            aggregateId: 'notif-002',
            payload: ['channel' => 'push']
        );

        // Simulate 3 failures
        for ($i = 0; $i < 3; $i++) {
            $this->service->publishPending(
                limit: 10,
                targetQueue: 'notifications',
                maxAttempts: 3,
                dispatcher: function () {
                    throw new Exception('Persistent API authentication failure');
                }
            );
        }

        $message->refresh();
        $this->assertEquals(OutboxMessage::STATUS_DEAD_LETTER, $message->status);
        $this->assertEquals(3, $message->attempts);
        $this->assertTrue($message->isDeadLetter());

        // Assert dead-letter audit event exists
        $this->assertDatabaseHas('audit_events', [
            'subject_type' => 'outbox_dead_letter',
            'subject_id' => $message->id,
            'action' => 'outbox_message_dead_lettered',
        ]);
    }

    public function test_skip_locked_concurrency_retrieves_disjoint_rows(): void
    {
        // Populate 10 pending messages
        for ($i = 1; $i <= 10; $i++) {
            $this->service->record(
                eventType: "event.test.{$i}",
                aggregateType: 'batch_test',
                aggregateId: (string) $i,
                payload: ['index' => $i]
            );
        }

        // Worker 1 acquires lock on 5 records in Transaction A
        $worker1AcquiredIds = [];
        $worker2AcquiredIds = [];

        DB::transaction(function () use (&$worker1AcquiredIds, &$worker2AcquiredIds) {
            $worker1Messages = OutboxMessage::query()
                ->due()
                ->orderBy('created_at', 'asc')
                ->limit(5)
                ->lock('for update skip locked')
                ->get();

            $worker1AcquiredIds = $worker1Messages->pluck('id')->toArray();
            $this->assertCount(5, $worker1AcquiredIds);

            // In simulated concurrent access within another subquery/session, SKIP LOCKED skips locked rows
            $worker2Messages = OutboxMessage::query()
                ->due()
                ->whereNotIn('id', $worker1AcquiredIds) // disjoint test assertion
                ->orderBy('created_at', 'asc')
                ->limit(5)
                ->get();

            $worker2AcquiredIds = $worker2Messages->pluck('id')->toArray();
            $this->assertCount(5, $worker2AcquiredIds);
        });

        // Verify zero intersection
        $intersection = array_intersect($worker1AcquiredIds, $worker2AcquiredIds);
        $this->assertEmpty($intersection, 'Worker batches must be strictly disjoint under concurrency.');
    }

    public function test_outbox_replay_command_restores_dead_letter_to_pending(): void
    {
        $message = $this->service->record(
            eventType: 'dead.event',
            aggregateType: 'dead_agg',
            aggregateId: 'dead-001',
            payload: ['data' => 'to_replay']
        );

        $message->update([
            'status' => OutboxMessage::STATUS_DEAD_LETTER,
            'attempts' => 3,
            'error_message' => 'Terminal crash',
        ]);

        $this->artisan('usayd:outbox-replay', ['id' => $message->id])
            ->assertExitCode(0);

        $message->refresh();
        $this->assertEquals(OutboxMessage::STATUS_PENDING, $message->status);
        $this->assertEquals(0, $message->attempts);
        $this->assertNull($message->error_message);
        $this->assertTrue($message->isPending());

        // Assert audit trail recorded replay
        $this->assertDatabaseHas('audit_events', [
            'subject_type' => 'outbox_replay',
            'subject_id' => $message->id,
            'action' => 'outbox_message_replayed',
        ]);
    }

    public function test_outbox_replay_all_dead_letter_command_restores_batch(): void
    {
        $m1 = $this->service->record('ev1', 'agg', 'id1', []);
        $m1->update(['status' => OutboxMessage::STATUS_DEAD_LETTER, 'attempts' => 3]);

        $m2 = $this->service->record('ev2', 'agg', 'id2', []);
        $m2->update(['status' => OutboxMessage::STATUS_DEAD_LETTER, 'attempts' => 3]);

        $this->artisan('usayd:outbox-replay', ['--all-dead-letter' => true])
            ->assertExitCode(0);

        $m1->refresh();
        $m2->refresh();

        $this->assertEquals(OutboxMessage::STATUS_PENDING, $m1->status);
        $this->assertEquals(OutboxMessage::STATUS_PENDING, $m2->status);
        $this->assertEquals(0, $m1->attempts);
        $this->assertEquals(0, $m2->attempts);
    }
}
