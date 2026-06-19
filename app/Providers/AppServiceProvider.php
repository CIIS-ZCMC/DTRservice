<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Contracts\DeviceRepositoryInterface::class,
            \App\Repositories\DeviceRepository::class
        );

        $this->app->bind(
            \App\Contracts\ScheduleRepositoryInterface::class,
            \App\Repositories\ScheduleRepository::class
        );

        $this->app->bind(
            \App\Contracts\LogsRepositoryInterface::class,
            \App\Repositories\LogsRepository::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
