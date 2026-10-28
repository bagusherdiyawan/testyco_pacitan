<?php

namespace App\Modules\Pos;

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ApiSession;
use App\Modules\Pos\Presentation\PosController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PosServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->getProvider(IdentityServiceProvider::class)) {
            throw new \LogicException('POS web requires Identity.');
        }
        Route::middleware(['web', ApiSession::class])->group(function () {
            $c = PosController::class;
            Route::get('/app/stores/{store}/pos', [$c, 'index']);
            Route::post('/app/stores/{store}/pos/shifts', [$c, 'open']);
            Route::post('/app/stores/{store}/pos/intents', [$c, 'create']);
            Route::get('/app/stores/{store}/pos/sales', [$c, 'history']);
            Route::get('/app/stores/{store}/pos/report', [$c, 'report']);
            Route::get('/app/pos/intents/{intent}', [$c, 'intent']);
            Route::post('/app/pos/intents/{intent}/pay', [$c, 'pay']);
            Route::post('/app/pos/intents/{intent}/cancel', [$c, 'cancel']);
            Route::get('/app/pos/sales/{sale}', [$c, 'receipt']);
            Route::get('/app/pos/shifts/{shift}', [$c, 'shift']);
            Route::post('/app/pos/shifts/{shift}/close', [$c, 'close']);
            Route::post('/app/pos/shifts/{shift}/cash-movements', [$c, 'movement']);
        });
    }
}
