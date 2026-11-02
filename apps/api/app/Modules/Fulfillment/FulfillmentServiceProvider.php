<?php

namespace App\Modules\Fulfillment;

use App\Modules\Fulfillment\Presentation\FulfillmentController;
use App\Modules\Fulfillment\Presentation\ShipmentDiscrepancyController;
use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Ordering\OrderingServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class FulfillmentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(OrderingServiceProvider::class)) {
            throw new \LogicException('Fulfillment requires Ordering.');
        }
        if (! $this->app->getProvider(InventoryServiceProvider::class)) {
            throw new \LogicException('Fulfillment requires Inventory.');
        }
        Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            $c = FulfillmentController::class;
            Route::get('stores/{store}/fulfillment/orders', [$c, 'warehouseInbox']);
            Route::get('stores/{store}/fulfillment/orders/{order}', [$c, 'warehouseContext']);
            Route::get('orders/{order}/shipments', [$c, 'index']);
            Route::post('orders/{order}/shipments', [$c, 'dispatch']);
            Route::get('orders/{order}/shipments/{shipment}/discrepancy', [ShipmentDiscrepancyController::class, 'show']);
            Route::post('orders/{order}/shipments/{shipment}/discrepancy', [ShipmentDiscrepancyController::class, 'report']);
            Route::post('orders/{order}/shipments/{shipment}/discrepancy/notes', [ShipmentDiscrepancyController::class, 'note']);
            Route::post('orders/{order}/shipments/{shipment}/discrepancy/resolutions', [ShipmentDiscrepancyController::class, 'proposeResolution']);
            Route::post('orders/{order}/shipments/{shipment}/discrepancy/resolutions/{resolution}/{action}', [ShipmentDiscrepancyController::class, 'decideResolution'])->where('action', 'confirm|reject|withdraw');
            Route::post('shipments/{shipment}/receive', [$c, 'receive']);
        });
    }
}
