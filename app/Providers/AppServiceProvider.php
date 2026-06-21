<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\TransaccionIngreso;
use App\Observers\TransaccionIngresoObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TransaccionIngreso::observe(TransaccionIngresoObserver::class);
    }
}
