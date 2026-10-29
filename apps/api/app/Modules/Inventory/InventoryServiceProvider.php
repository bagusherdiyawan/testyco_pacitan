<?php

namespace App\Modules\Inventory;

use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Inventory\Presentation\InventoryController;
use App\Modules\Inventory\Presentation\StockControlController;
use App\Modules\Inventory\Presentation\StockMinimumController;
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
            Route::get('stores/{store}/inventory/minimums', [StockMinimumController::class, 'index']);
            Route::get('stores/{store}/inventory/minimums/{product}', [StockMinimumController::class, 'show']);
            Route::post('stores/{store}/inventory/minimums/{product}', [StockMinimumController::class, 'configure']);
            $control = StockControlController::class;
            Route::get('stores/{store}/inventory/control-context', [$control, 'context']);
            Route::get('stores/{store}/inventory/processes', [$control, 'processes']);
            Route::post('stores/{store}/inventory/processes', [$control, 'process']);
            Route::get('stores/{store}/inventory/counts', [$control, 'counts']);
            Route::post('stores/{store}/inventory/counts', [$control, 'propose']);
            Route::post('inventory/counts/{count}/{action}', [$control, 'review'])->where('action', 'approve|reject|cancel');
            Route::get('stores/{store}/inventory/batches', [InventoryController::class, 'batches']);
            Route::get('stores/{store}/inventory/ledger', [InventoryController::class, 'ledger']);
            Route::post('stores/{store}/inventory/intakes', [InventoryController::class, 'intake']);
        });
    }
}
