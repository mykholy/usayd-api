<?php

namespace Tests\Unit;

use App\Platform\Observability\Application\DependencyStatus;
use App\Platform\Observability\Application\HealthReport;
use PHPUnit\Framework\TestCase;

class HealthReportTest extends TestCase
{
    public function test_database_failure_is_unavailable_regardless_of_redis(): void
    {
        $report = new HealthReport(new DependencyStatus('unreachable'), new DependencyStatus('connected'), true);

        $this->assertSame('unavailable', $report->status());
    }

    public function test_required_redis_failure_is_degraded_and_optional_failure_is_healthy(): void
    {
        $database = new DependencyStatus('connected');
        $redis = new DependencyStatus('unreachable');

        $this->assertSame('degraded', (new HealthReport($database, $redis, true))->status());
        $this->assertSame('healthy', (new HealthReport($database, $redis, false))->status());
    }
}
