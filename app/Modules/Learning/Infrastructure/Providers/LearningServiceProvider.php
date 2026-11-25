<?php

declare(strict_types=1);

namespace App\Modules\Learning\Infrastructure\Providers;

use App\Modules\Learning\Application\Ports\LearningPlanStore;
use App\Modules\Learning\Infrastructure\Persistence\EloquentLearningPlanStore;
use Illuminate\Support\ServiceProvider;

final class LearningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LearningPlanStore::class, EloquentLearningPlanStore::class);
    }
}
