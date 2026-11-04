<?php

namespace App\Modules\Billing;

use App\Modules\Billing\Presentation\BillingController;
use App\Modules\Fulfillment\FulfillmentServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Ordering\OrderingServiceProvider;
use App\Modules\Partnership\PartnershipServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([IdentityServiceProvider::class, OrderingServiceProvider::class, FulfillmentServiceProvider::class, PartnershipServiceProvider::class] as $p) {
            if (! $this->app->getProvider($p)) {
                throw new \LogicException('Billing dependency missing.');
            }
        }
        Route::prefix('api/v1/orders/{order}/billing')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            Route::get('/', [BillingController::class, 'show']);
            Route::post('invoices', [BillingController::class, 'invoice']);
            Route::post('invoices/{invoice}/{action}', [BillingController::class, 'decideInvoice'])->where('action', 'accept|reject|withdraw');
            Route::post('invoices/{invoice}/payments', [BillingController::class, 'payment']);
            Route::post('invoices/{invoice}/payments/{payment}/reverse', [BillingController::class, 'reverse']);
            Route::post('invoices/{invoice}/credits', [BillingController::class, 'credit']);
            Route::post('invoices/{invoice}/credits/{credit}/{action}', [BillingController::class, 'decideCredit'])->where('action', 'accept|reject|withdraw');
            Route::post('reports', [BillingController::class, 'report']);
            Route::post('reports/{report}/{action}', [BillingController::class, 'decideReport'])->where('action', 'accept|reject|withdraw');
        });
    }
}
