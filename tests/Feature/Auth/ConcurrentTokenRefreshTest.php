<?php

namespace Tests\Feature\Auth;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentTokenRefreshTest extends TestCase
{
    protected ?int $userId = null;

    protected function tearDown(): void
    {
        if ($this->userId) {
            PersonalAccessToken::where('tokenable_id', $this->userId)->delete();
            DB::statement('SET @allow_audit_modification = 1;');
            AuditEvent::where('subject_type', 'user')->where('subject_id', (string) $this->userId)->delete();
            DB::statement('SET @allow_audit_modification = 0;');
            User::where('id', $this->userId)->delete();
        }

        parent::tearDown();
    }

    public function test_concurrent_refresh_requests_allow_only_single_rotation_winner(): void
    {
        $user = User::create([
            'name' => 'Concurrent Tester',
            'email' => 'concurrent_'.uniqid().'@usayd.academy',
            'password' => bcrypt('password123'),
            'locale' => 'ar',
            'status' => 'active',
        ]);
        $this->userId = $user->id;

        $tokenService = app(TokenService::class);
        $initialTokens = $tokenService->issueTokens($user, 'concurrency-device');
        $plainRefreshToken = $initialTokens['refresh_token'];

        $artisanPath = base_path('artisan');
        $numWorkers = 2;
        $processes = [];

        for ($i = 0; $i < $numWorkers; $i++) {
            $p = new Process([
                'php',
                $artisanPath,
                'usayd:token-refresh-worker',
                "--token={$plainRefreshToken}",
                "--device=concurrency-device-{$i}",
                '--env=testing',
            ]);
            $p->start();
            $processes[] = $p;
        }

        $exitCodes = [];
        $outputs = [];
        foreach ($processes as $p) {
            $p->wait();
            $exitCodes[] = $p->getExitCode();
            $outputs[] = $p->getOutput();
        }

        $successCount = count(array_filter($exitCodes, fn ($code) => $code === 0));
        $failedCount = count(array_filter($exitCodes, fn ($code) => $code === 1));

        $this->assertSame(
            1,
            $successCount,
            'Exactly one worker must succeed in rotating the single-use refresh token. Outputs: '.json_encode($outputs)
        );
        $this->assertSame(
            1,
            $failedCount,
            'The concurrent conflicting refresh request must fail due to row-level atomic token consumption. Outputs: '.json_encode($outputs)
        );
    }
}
