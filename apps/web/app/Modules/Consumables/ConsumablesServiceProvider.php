<?php

namespace App\Modules\Consumables;

use App\Modules\Consumables\Presentation\ConsumableController;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ApiSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ConsumablesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(IdentityServiceProvider::class)) {
            throw new \LogicException('Consumables web requires Identity.');
        }
        Route::middleware(['web', ApiSession::class])->group(function () {
            $c = ConsumableController::class;
            Route::get('/app/organizations/{organization}/consumables', [$c, 'catalogue']);
            Route::post('/app/organizations/{organization}/consumables/items', [$c, 'createItem']);
            Route::post('/app/organizations/{organization}/consumables/items/{item}/status', [$c, 'itemStatus']);
            Route::post('/app/organizations/{organization}/consumables/rules', [$c, 'rule']);
            Route::get('/app/stores/{store}/consumables', [$c, 'stock']);
            Route::post('/app/stores/{store}/consumables/purchases', [$c, 'purchase']);
            Route::post('/app/stores/{store}/consumables/counts', [$c, 'count']);
            Route::get('/app/stores/{store}/consumable-shipments', [$c, 'shipments']);
            Route::post('/app/stores/{store}/consumable-shipments', [$c, 'dispatchShipment']);
            Route::post('/app/consumable-shipments/{shipment}/receive', [$c, 'receiveShipment']);
        });
    }
}
