<?php

namespace App\Modules\Commercial;

use App\Modules\Commercial\Presentation\CommercialController;
use App\Modules\Identity\Presentation\ApiSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CommercialServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', ApiSession::class])->prefix('/app/organizations/{organization}/commercial-agreements')->group(function () {
            Route::get('', [CommercialController::class, 'index']);
            Route::post('', [CommercialController::class, 'propose']);
            Route::post('retry', [CommercialController::class, 'retry']);
            Route::get('{agreement}', [CommercialController::class, 'show']);
            Route::post('{agreement}', [CommercialController::class, 'submit']);
        });
    }
}
