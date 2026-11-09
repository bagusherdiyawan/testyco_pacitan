<?php

namespace App\Modules\Manufacturing;

use App\Modules\Identity\Presentation\ApiSession;
use App\Modules\Manufacturing\Presentation\ErpController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ManufacturingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', ApiSession::class])->prefix('app/organizations/{organization}/erp')->group(function () {
            Route::get('', [ErpController::class, 'index']);
            Route::post('review', [ErpController::class, 'review']);
            Route::post('confirm', [ErpController::class, 'confirm']);
            Route::post('retry', [ErpController::class, 'retry']);
            Route::post('edit', [ErpController::class, 'edit']);
        });
    }
}
