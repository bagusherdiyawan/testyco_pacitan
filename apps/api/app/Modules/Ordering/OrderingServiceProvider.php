<?php

namespace App\Modules\Ordering;

use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Ordering\Presentation\OrderController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class OrderingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(IdentityServiceProvider::class)) {
            throw new \LogicException('Ordering requires Identity.');
        }
        if (! $this->app->getProvider(CatalogServiceProvider::class)) {
            throw new \LogicException('Ordering requires Catalog.');
        }
        Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            $c = OrderController::class;
            Route::get('stores/{store}/operations-context', [$c, 'context']);
            Route::get('stores/{store}/supply-catalog', [$c, 'catalog']);
            Route::get('stores/{store}/orders', [$c, 'index']);
            Route::post('stores/{store}/orders/preview', [$c, 'preview']);
            Route::post('stores/{store}/orders', [$c, 'create']);
            Route::get('supply/orders', [$c, 'inbox']);
            Route::get('orders/{order}', [$c, 'show']);
            Route::put('orders/{order}', [$c, 'amend']);
            Route::post('orders/{order}/{action}', [$c, 'transition'])->where('action', 'approve|reject|cancel|process');
        });
    }
}
