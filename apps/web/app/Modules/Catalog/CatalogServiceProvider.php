<?php

namespace App\Modules\Catalog;

use App\Modules\Catalog\Presentation\CatalogController;
use App\Modules\Catalog\Presentation\CatalogExtensionController;
use App\Modules\Identity\Presentation\ApiSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', ApiSession::class])->group(function () {
            Route::get('/app/organizations/{organization}/products/{product}/configuration', [CatalogExtensionController::class, 'show']);
            Route::get('/app/organizations/{organization}/products/{product}/image', [CatalogExtensionController::class, 'image']);
            Route::post('/app/organizations/{organization}/products/{product}/configuration/retry', [CatalogExtensionController::class, 'retry']);
            Route::post('/app/organizations/{organization}/products/{product}/configuration/{action}', [CatalogExtensionController::class, 'save'])->where('action', 'prices|recipe|image');
            Route::post('/app/organizations/{organization}/products/{product}/prices/{price}/cancel', [CatalogExtensionController::class, 'cancel']);
            Route::get('/app/organizations/{organization}/catalog', [CatalogController::class, 'index']);
            Route::post('/app/organizations/{organization}/products', [CatalogController::class, 'save']);
            Route::post('/app/organizations/{organization}/products/{product}', [CatalogController::class, 'save']);
        });
    }
}
