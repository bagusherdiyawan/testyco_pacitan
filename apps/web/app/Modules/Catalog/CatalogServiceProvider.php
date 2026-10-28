<?php

namespace App\Modules\Catalog;

use App\Modules\Catalog\Presentation\CatalogController;
use App\Modules\Identity\Presentation\ApiSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', ApiSession::class])->group(function () {
            Route::get('/app/organizations/{organization}/catalog', [CatalogController::class, 'index']);
            Route::post('/app/organizations/{organization}/products', [CatalogController::class, 'save']);
            Route::post('/app/organizations/{organization}/products/{product}', [CatalogController::class, 'save']);
        });
    }
}
