<?php

namespace App\Modules\Inventory;

use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Inventory\Presentation\InventoryController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(IdentityServiceProvider::class)) {
            throw new \LogicException('Inventory requires Identity.');
        }
        if (! $this->app->getProvider(CatalogServiceProvider::class)) {
            throw new \LogicException('Inventory requires Catalog.');
        }
        Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            Route::get('stores/{store}/inventory/batches', [InventoryController::class, 'batches']);
            Route::get('stores/{store}/inventory/ledger', [InventoryController::class, 'ledger']);
            Route::post('stores/{store}/inventory/intakes', [InventoryController::class, 'intake']);
        });
    }
}
