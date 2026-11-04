<?php

namespace App\Modules\Assets;

use App\Modules\Assets\Presentation\AssetController;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ApiSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AssetsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(IdentityServiceProvider::class)) {
            throw new \LogicException('Assets requires Identity.');
        }Route::middleware(['web', ApiSession::class])->group(function () {
            Route::get('/app/organizations/{organization}/assets', [AssetController::class, 'index']);
            Route::post('/app/organizations/{organization}/assets', [AssetController::class, 'create']);
            Route::post('/app/organizations/{organization}/assets/retry', [AssetController::class, 'retryCreate']);
            Route::get('/app/assets/{asset}', [AssetController::class, 'show']);
            Route::post('/app/assets/{asset}', [AssetController::class, 'submit']);
            Route::post('/app/assets/{asset}/retry', [AssetController::class, 'retry']);
        });
    }
}
