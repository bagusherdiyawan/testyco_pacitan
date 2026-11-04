<?php

namespace App\Modules\Promotions;

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ApiSession;
use App\Modules\Promotions\Presentation\VoucherController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PromotionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(IdentityServiceProvider::class)) {
            throw new \LogicException('Promotions requires Identity.');
        }Route::middleware(['web', ApiSession::class])->group(function () {
            Route::get('/app/organizations/{organization}/vouchers', [VoucherController::class, 'show']);
            Route::post('/app/organizations/{organization}/vouchers', [VoucherController::class, 'create']);
            Route::post('/app/organizations/{organization}/vouchers/retry', [VoucherController::class, 'retry']);
            Route::post('/app/organizations/{organization}/vouchers/{voucher}/status', [VoucherController::class, 'status']);
        });
    }
}
