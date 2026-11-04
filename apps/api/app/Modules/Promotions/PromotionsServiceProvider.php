<?php

namespace App\Modules\Promotions;

use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Promotions\Presentation\VoucherController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PromotionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(IdentityServiceProvider::class) || ! $this->app->getProvider(CatalogServiceProvider::class)) {
            throw new \LogicException('Promotions requires Identity/Catalog.');
        }Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            Route::get('organizations/{organization}/vouchers', [VoucherController::class, 'index']);
            Route::post('organizations/{organization}/vouchers', [VoucherController::class, 'create']);
            Route::post('organizations/{organization}/vouchers/{voucher}/status', [VoucherController::class, 'status']);
        });
    }
}
