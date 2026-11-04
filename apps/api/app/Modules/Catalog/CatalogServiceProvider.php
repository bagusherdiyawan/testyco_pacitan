<?php

namespace App\Modules\Catalog;

use App\Modules\Catalog\Presentation\CatalogController;
use App\Modules\Catalog\Presentation\CatalogExtensionController;
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
            Route::get('organizations/{organization}/products/{product}/configuration', [CatalogExtensionController::class, 'show']);
            Route::post('organizations/{organization}/products/{product}/prices', [CatalogExtensionController::class, 'price']);
            Route::post('organizations/{organization}/products/{product}/prices/{price}/cancel', [CatalogExtensionController::class, 'cancelPrice']);
            Route::post('organizations/{organization}/products/{product}/recipe', [CatalogExtensionController::class, 'saveRecipe']);
            Route::post('organizations/{organization}/products/{product}/image', [CatalogExtensionController::class, 'image']);
            Route::get('organizations/{organization}/products/{product}/image', [CatalogExtensionController::class, 'readImage']);
            Route::get('stores/{store}/catalog/{product}/image', [CatalogExtensionController::class, 'storeImage']);
            Route::get('stores/{store}/catalog/{product}/image-data', [CatalogExtensionController::class, 'imageData']);
            Route::get('stores/{store}/catalog/{product}/bundle', [CatalogExtensionController::class, 'bundle']);
            Route::get('stores/{store}/catalog', [CatalogController::class, 'storeCatalog']);
        });
    }
}
