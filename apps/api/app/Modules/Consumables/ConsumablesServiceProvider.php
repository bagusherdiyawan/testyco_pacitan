<?php

namespace App\Modules\Consumables;

use App\Modules\Consumables\Presentation\ConsumableController;
use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Shifts\ShiftsServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ConsumablesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(ShiftsServiceProvider::class)) {
            throw new \LogicException('Consumables requires '.ShiftsServiceProvider::class);
        }
        Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            $c = ConsumableController::class;
            Route::get('organizations/{organization}/consumables', [$c, 'catalogue']);
            Route::post('organizations/{organization}/consumables/items', [$c, 'createItem']);
            Route::post('organizations/{organization}/consumables/items/{item}/status', [$c, 'itemStatus']);
            Route::post('organizations/{organization}/consumables/rules', [$c, 'rule']);
            Route::get('stores/{store}/consumables', [$c, 'stock']);
            Route::post('stores/{store}/consumables/purchases', [$c, 'purchase']);
            Route::post('stores/{store}/consumables/counts', [$c, 'count']);
            Route::get('stores/{store}/consumable-shipments', [$c, 'shipments']);
            Route::post('stores/{store}/consumable-shipments', [$c, 'dispatchShipment']);
            Route::post('consumable-shipments/{shipment}/receive', [$c, 'receiveShipment']);
        });
    }
}
