<?php

namespace App\Modules\Catalog;

use App\Modules\Catalog\Presentation\CatalogController;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ActiveUser;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(IdentityServiceProvider::class)) {
            throw new \LogicException('Catalog requires Identity.');
        }
        Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            Route::get('organizations/{organization}/products', [CatalogController::class, 'index']);
            Route::post('organizations/{organization}/products', [CatalogController::class, 'create']);
            Route::put('organizations/{organization}/products/{product}', [CatalogController::class, 'update']);
            Route::get('stores/{store}/catalog', [CatalogController::class, 'storeCatalog']);
        });
    }
}
