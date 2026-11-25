<?php

namespace App\Providers;

use App\Modules\Identity\Infrastructure\Providers\IdentityServiceProvider;
use App\Modules\Learning\Infrastructure\Providers\LearningServiceProvider;
use App\Platform\Observability\Application\HealthDependencies;
use App\Platform\Observability\Infrastructure\LaravelHealthDependencies;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(HealthDependencies::class, LaravelHealthDependencies::class);
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
