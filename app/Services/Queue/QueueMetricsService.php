<?php

namespace App\Services\Queue;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class QueueMetricsService
{
    /**
     * Canonical Usayd queue partitions.
     */
    public const PARTITIONS = ['high', 'default', 'notifications', 'reports'];

    /**
     * Gather queue depths, failed jobs count, and health status.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        $connectionName = config('queue.default');
        $queueDepths = [];

        foreach (self::PARTITIONS as $partition) {
            try {
                $queueDepths[$partition] = Queue::connection($connectionName)->size($partition);
            } catch (\Throwable) {
                // If connection is sync or redis unavailable during inspection, report 0
                $queueDepths[$partition] = 0;
            }
        }

        $failedCount = DB::table('failed_jobs')->count();
        $recentFailed = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(5)
            ->get(['id', 'uuid', 'queue', 'failed_at', 'exception']);

        return [
            'status' => $failedCount > 50 ? 'degraded' : 'healthy',
            'default_connection' => $connectionName,
            'partitions' => $queueDepths,
            'failed_jobs' => [
                'total' => $failedCount,
                'recent' => $recentFailed->map(fn ($job) => [
                    'id' => $job->id,
                    'uuid' => $job->uuid,
                    'queue' => $job->queue,
                    'failed_at' => $job->failed_at,
                    'exception_summary' => strtok((string) $job->exception, "\n"),
                ])->all(),
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
