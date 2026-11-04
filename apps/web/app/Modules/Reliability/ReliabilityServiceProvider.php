<?php

namespace App\Modules\Reliability;

use App\Modules\Identity\Presentation\ApiSession;
use App\Modules\Reliability\Presentation\ReliabilityController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ReliabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', ApiSession::class])->group(function () {
            Route::get('/app/stores/{store}/food-safety/fefo', [ReliabilityController::class, 'fefo']);
            Route::get('/app/inbox', [ReliabilityController::class, 'inbox']);
            Route::post('/app/inbox/preferences', [ReliabilityController::class, 'preferences']);
            Route::post('/app/inbox/{entry}/read', [ReliabilityController::class, 'mark']);
            Route::get('/app/organizations/{organization}/food-safety', [ReliabilityController::class, 'food']);
            Route::post('/app/organizations/{organization}/food-safety', [ReliabilityController::class, 'submit']);
            Route::post('/app/organizations/{organization}/food-safety/retry', [ReliabilityController::class, 'retry']);
        });
    }
}
