<?php

namespace App\Providers;

use App\Support\ShortIndexNameBlueprint;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

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
        $this->app->bind(Blueprint::class, ShortIndexNameBlueprint::class);
    }
}
