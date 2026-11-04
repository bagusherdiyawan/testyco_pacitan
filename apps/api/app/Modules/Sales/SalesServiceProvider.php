<?php

namespace App\Modules\Sales;

use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Sales\Application\PendingIntents;
use App\Modules\Sales\Presentation\OfflineController;
use App\Modules\Sales\Presentation\PosController;
use App\Modules\Sales\Presentation\RefundController;
use App\Modules\Shifts\Application\ShiftBlockers;
use App\Modules\Shifts\ShiftsServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShiftBlockers::class, PendingIntents::class);
    }

    public function boot(): void
    {
        foreach ([ShiftsServiceProvider::class, CatalogServiceProvider::class, InventoryServiceProvider::class] as $provider) {
            if (! $this->app->getProvider($provider)) {
                throw new \LogicException('Sales requires '.$provider);
            }
        }
        Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            $offline = OfflineController::class;
            Route::post('stores/{store}/sales/offline/permits', [$offline, 'permit']);
            Route::post('sales/offline/permits/{permit}/end', [$offline, 'end']);
            Route::get('stores/{store}/sales/offline', [$offline, 'context']);
            Route::post('stores/{store}/sales/offline', [$offline, 'sync']);
            Route::get('sales/offline/{offline}', [$offline, 'show']);
            Route::post('sales/offline/{offline}/{action}', [$offline, 'resolve'])->where('action', 'accept|void');
            $c = PosController::class;
            Route::get('stores/{store}/pos/context', [$c, 'context']);
            Route::post('stores/{store}/sales/intents', [$c, 'create']);
            Route::get('sales/intents/{intent}', [$c, 'intent']);
            Route::post('sales/intents/{intent}/pay', [$c, 'pay']);
            Route::post('sales/intents/{intent}/cancel', [$c, 'cancel']);
            Route::get('stores/{store}/sales', [$c, 'sales']);
            Route::get('sales/{sale}/refunds', [RefundController::class, 'context']);
            Route::post('sales/{sale}/refunds/preview', [RefundController::class, 'preview']);
            Route::post('sales/{sale}/refunds', [RefundController::class, 'refund']);
            Route::get('sales/{sale}', [$c, 'receipt']);
            Route::get('stores/{store}/sales/summary', [$c, 'summary']);
        });
    }
}
