<?php

namespace App\Providers;

use App\Events\AuditLogCreated;
use App\Listeners\HandleAuditLogCreated;
use App\Models\User;
use App\Observers\UserObserver;
use App\Services\RecycleBinService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RecycleBinService::class, function ($app) {
            return new RecycleBinService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::observe(UserObserver::class);

        Event::listen(
            AuditLogCreated::class,
            HandleAuditLogCreated::class
        );
    }
}
