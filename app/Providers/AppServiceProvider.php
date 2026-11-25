<?php

namespace App\Providers;

use App\Modules\Identity\Infrastructure\Providers\IdentityServiceProvider;
use App\Modules\Learning\Infrastructure\Providers\LearningServiceProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
