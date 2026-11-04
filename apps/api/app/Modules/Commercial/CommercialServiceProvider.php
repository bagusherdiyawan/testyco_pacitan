<?php

namespace App\Modules\Commercial;

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Commercial\Application\ConsignmentAutomation;
use App\Modules\Commercial\Presentation\CommercialController;
use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Promotions\PromotionsServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CommercialServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([BillingServiceProvider::class, PromotionsServiceProvider::class] as $p) {
            if (! $this->app->getProvider($p)) {
                throw new \LogicException('Commercial dependencies missing.');
            }
        }
        Route::prefix('api/v1/organizations/{organization}/commercial-agreements')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            Route::get('', [CommercialController::class, 'index']);
            Route::post('', [CommercialController::class, 'propose']);
            Route::get('options', [CommercialController::class, 'options']);
            Route::get('{agreement}', [CommercialController::class, 'show']);
            Route::post('{agreement}/payments', [CommercialController::class, 'payment']);
            Route::post('{agreement}/{action}', [CommercialController::class, 'decide'])->where('action', 'accept|reject|withdraw|suspend');
        });
        Artisan::command('commercial:accrue {--limit=100}', function () {
            $result = app(ConsignmentAutomation::class)->accrue(max(1, min(100, (int) $this->option('limit'))));
            $this->info(json_encode($result));

            return $result['failed'] > 0 ? 1 : 0;
        });
    }
}
