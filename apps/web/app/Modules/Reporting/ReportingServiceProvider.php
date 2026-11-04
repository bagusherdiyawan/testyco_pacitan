<?php

namespace App\Modules\Reporting;

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ApiSession;
use App\Modules\Reporting\Presentation\CashReportController;
use App\Modules\Reporting\Presentation\CommercialReportController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(IdentityServiceProvider::class)) {
            throw new \LogicException('Reporting requires Identity.');
        }
        Route::middleware(['web', ApiSession::class])->group(function () {
            Route::get('/app/organizations/{organization}/reports/commercial', [CommercialReportController::class, 'index']);
            Route::get('/app/organizations/{organization}/reports/commercial/export', [CommercialReportController::class, 'export']);
            Route::post('/app/organizations/{organization}/reports/commercial/costs', [CommercialReportController::class, 'cost']);
            Route::post('/app/organizations/{organization}/reports/commercial/retry', [CommercialReportController::class, 'retry']);
            Route::get('/app/organizations/{organization}/reports/cash-sales', [CashReportController::class, 'index']);
            Route::get('/app/organizations/{organization}/reports/cash-sales/export', [CashReportController::class, 'export']);
        });
    }
}
