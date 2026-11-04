<?php

namespace App\Modules\Billing;

use App\Modules\Billing\Presentation\BillingController;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ApiSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(IdentityServiceProvider::class)) {
            throw new \LogicException('Billing requires Identity.');
        }Route::middleware(['web', ApiSession::class])->group(function () {
            Route::get('/app/orders/{order}/billing', [BillingController::class, 'show']);
            Route::post('/app/orders/{order}/billing', [BillingController::class, 'submit']);
            Route::post('/app/orders/{order}/billing/retry', [BillingController::class, 'retry']);
        });
    }
}
