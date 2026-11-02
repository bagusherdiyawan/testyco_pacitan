<?php

namespace App\Modules\Operations;

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ApiSession;
use App\Modules\Operations\Presentation\LedgerExportController;
use App\Modules\Operations\Presentation\OperationsController;
use App\Modules\Operations\Presentation\OrderChangeController;
use App\Modules\Operations\Presentation\BatchExpiryController;
use App\Modules\Operations\Presentation\StockControlController;
use App\Modules\Operations\Presentation\StockMinimumController;
use App\Modules\Operations\Presentation\StockLedgerController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class OperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(IdentityServiceProvider::class)) {
            throw new \LogicException('Operations requires Identity.');
        }
        Route::middleware(['web', ApiSession::class])->group(function () {
            Route::get('/app/stores/{store}/inventory/batch-expiry', [BatchExpiryController::class, 'index']);
            Route::get('/app/stores/{store}/inventory/ledger/export', LedgerExportController::class);
            Route::get('/app/stores/{store}/inventory/ledger', [StockLedgerController::class, 'index']);
            Route::get('/app/stores/{store}/stock-minimums', [StockMinimumController::class, 'index']);
            Route::post('/app/stores/{store}/stock-minimums/{product}', [StockMinimumController::class, 'configure']);
            $stock = StockControlController::class;
            Route::get('/app/stores/{store}/stock-control', [$stock, 'index']);
            Route::post('/app/stores/{store}/stock-control/processes', [$stock, 'process']);
            Route::post('/app/stores/{store}/stock-control/counts', [$stock, 'propose']);
            Route::post('/app/stores/{store}/stock-control/counts/{count}/{action}', [$stock, 'review'])->where('action', 'approve|reject|cancel');
            $c = OperationsController::class;
            Route::get('/app/stores/{store}/operations', [$c, 'store']);
            Route::get('/app/stores/{store}/orders/new', [$c, 'createForm']);
            Route::post('/app/stores/{store}/orders', [$c, 'create']);
            Route::post('/app/stores/{store}/inventory/intakes', [$c, 'intake']);
            Route::get('/app/supply/orders', [$c, 'inbox']);
            Route::get('/app/orders/{order}', [$c, 'show']);
            Route::get('/app/orders/{order}/changes', [OrderChangeController::class, 'index']);
            Route::post('/app/orders/{order}/changes', [OrderChangeController::class, 'propose']);
            Route::post('/app/orders/{order}/changes/retry', [OrderChangeController::class, 'retry']);
            Route::post('/app/orders/{order}/changes/{change}/{action}', [OrderChangeController::class, 'decide'])->where('action', 'approve|reject|withdraw');
            Route::get('/app/orders/{order}/edit', [$c, 'editForm']);
            Route::post('/app/orders/{order}/amend', [$c, 'amend']);
            Route::post('/app/orders/{order}/shipments', [$c, 'dispatch']);
            Route::post('/app/orders/{order}/shipments/{shipment}/receive', [$c, 'receive']);
            Route::post('/app/orders/{order}/{action}', [$c, 'transition'])->where('action', 'approve|reject|cancel|process');
        });
    }
}
