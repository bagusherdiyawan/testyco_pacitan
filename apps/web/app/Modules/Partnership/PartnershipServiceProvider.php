<?php

namespace App\Modules\Partnership;

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ApiSession;
use App\Modules\Partnership\Presentation\ContractController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PartnershipServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(IdentityServiceProvider::class)) {
            throw new \LogicException('Partnership requires Identity.');
        }
        Route::middleware(['web', ApiSession::class])->group(function () {
            Route::get('/app/organizations/{organization}/contracts', [ContractController::class, 'index']);
            Route::get('/app/organizations/{organization}/contracts/{contract}/attachment', [ContractController::class, 'attachment']);
            Route::post('/app/organizations/{organization}/contracts', [ContractController::class, 'create']);
            Route::post('/app/organizations/{organization}/contracts/retry', [ContractController::class, 'retry']);
            Route::post('/app/organizations/{organization}/contracts/{contract}/{action}', [ContractController::class, 'decide'])->where('action', 'publish|withdraw|accept|reject');
        });
    }
}
