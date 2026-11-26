<?php

namespace App\Providers;

use App\Modules\Identity\Infrastructure\Providers\IdentityServiceProvider;
use App\Modules\Learning\Infrastructure\Providers\LearningServiceProvider;
use App\Platform\Observability\Application\HealthDependencies;
use App\Platform\Observability\Infrastructure\LaravelHealthDependencies;
use App\Platform\Storage\Application\Ports\MalwareScanner;
use App\Platform\Storage\Infrastructure\ClamAvScanner;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(HealthDependencies::class, LaravelHealthDependencies::class);
        $this->app->bind(MalwareScanner::class, ClamAvScanner::class);
        $this->app->register(IdentityServiceProvider::class);
        $this->app->register(LearningServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
