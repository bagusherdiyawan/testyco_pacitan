<?php

namespace App\Modules\Partnership;

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Partnership\Presentation\ContractController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PartnershipServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(IdentityServiceProvider::class)) {
            throw new \LogicException('Partnership requires Identity.');
        }Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            Route::get('organizations/{organization}/contracts', [ContractController::class, 'index']);
            Route::get('organizations/{organization}/contracts/{contract}/attachment', [ContractController::class, 'attachment']);
            Route::post('organizations/{organization}/contracts', [ContractController::class, 'create']);
            Route::post('organizations/{organization}/contracts/{contract}/{action}', [ContractController::class, 'decide'])->where('action', 'publish|withdraw|accept|reject');
        });
    }
}
