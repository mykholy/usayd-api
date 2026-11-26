<?php

namespace Tests\Feature\Queue;

use App\Jobs\SampleProcessingJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealRedisIntegrationTest extends TestCase
{
    public function test_redis_cache_and_queue_round_trip(): void
    {
        if (getenv('USAYD_REQUIRE_REDIS_TESTS') !== '1') {
            $this->markTestSkipped('Set USAYD_REQUIRE_REDIS_TESTS=1 against an isolated test Redis instance.');
        }

        $name = 'ci-probe-'.Str::uuid();
        $cache = Cache::store('redis');
        $connection = Queue::connection('redis');
        $this->assertInstanceOf(RedisQueue::class, $connection);

        try {
            $this->assertTrue($cache->put($name, 'probe', 60));
            $this->assertSame('probe', $cache->get($name));

            $connection->push(new SampleProcessingJob(taskName: 'ci_redis_probe'), '', $name);
            $this->assertSame(1, $connection->size($name));

            $job = $connection->pop($name);
            $this->assertNotNull($job);
            $this->assertSame(SampleProcessingJob::class, $job->resolveName());
            $job->delete();

            $this->assertSame(0, $connection->size($name));
        } finally {
            $cache->forget($name);
            $connection->clear($name);
        }
    }
}
