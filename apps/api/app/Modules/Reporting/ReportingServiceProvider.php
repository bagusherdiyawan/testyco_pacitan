<?php

namespace App\Modules\Reporting;

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Reporting\Presentation\CashReportController;
use App\Modules\Reporting\Presentation\CommercialReportController;
use App\Modules\Sales\SalesServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([IdentityServiceProvider::class, SalesServiceProvider::class] as $provider) {
            if (! $this->app->getProvider($provider)) {
                throw new \LogicException('Reporting requires '.$provider);
            }
        }
        Route::prefix('api/v1/organizations/{organization}/reports/commercial')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            Route::get('', [CommercialReportController::class, 'read']);
            Route::post('costs', [CommercialReportController::class, 'cost']);
        });
        Route::prefix('api/v1/organizations/{organization}/reports/sales-trend')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            Route::get('', [CashReportController::class, 'trend']);
        });
        Route::prefix('api/v1/organizations/{organization}/reports/cash-sales')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            Route::get('', [CashReportController::class, 'summary']);
            Route::get('products', [CashReportController::class, 'products']);
            Route::get('export', [CashReportController::class, 'export']);
        });
    }
}
