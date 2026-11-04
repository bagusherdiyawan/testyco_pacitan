<?php

namespace App\Modules\Assets;

use App\Modules\Assets\Presentation\AssetController;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Partnership\PartnershipServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AssetsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([IdentityServiceProvider::class, PartnershipServiceProvider::class] as $p) {
            if (! $this->app->getProvider($p)) {
                throw new \LogicException('Assets dependency missing.');
            }
        }Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            Route::get('organizations/{organization}/assets', [AssetController::class, 'index']);
            Route::post('organizations/{organization}/assets', [AssetController::class, 'create']);
            Route::get('assets/{asset}', [AssetController::class, 'show']);
            Route::post('assets/{asset}/placements', [AssetController::class, 'propose']);
            Route::post('assets/{asset}/placements/{placement}/{action}', [AssetController::class, 'decide'])->where('action', 'accept|reject|withdraw|request_return|confirm_return');
            Route::post('assets/{asset}/incidents', [AssetController::class, 'incident']);
            Route::post('assets/{asset}/incidents/{incident}/resolve', [AssetController::class, 'resolve']);
        });
    }
}
