<?php

namespace App\Console\Commands;

use App\Services\Auth\TokenService;
use Illuminate\Console\Command;
use Throwable;

class TokenRefreshWorkerCommand extends Command
{
    protected $signature = 'usayd:token-refresh-worker {--token=} {--device=worker}';

    protected $description = 'Worker to execute single token rotation under parallel concurrency testing';

    public function handle(TokenService $tokenService): int
    {
        $token = (string) $this->option('token');
        $device = (string) $this->option('device');

        try {
            $tokens = $tokenService->rotateRefreshToken($token, $device);
            $this->line(json_encode(['status' => 'success', 'tokens' => $tokens]));

            return 0;
        } catch (Throwable $e) {
            $this->line(json_encode(['status' => 'failed', 'message' => $e->getMessage()]));

            return 1;
        }
    }
}
