<?php

namespace App\Modules\Shifts;

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Shifts\Application\NoShiftBlockers;
use App\Modules\Shifts\Application\ShiftBlockers;
use App\Modules\Shifts\Presentation\ShiftController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ShiftsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShiftBlockers::class, NoShiftBlockers::class);
    }

    public function boot(): void
    {
        if (! $this->app->getProvider(IdentityServiceProvider::class)) {
            throw new \LogicException('Shifts requires Identity.');
        }
        Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            $c = ShiftController::class;
            Route::get('stores/{store}/shifts', [$c, 'index']);
            Route::post('stores/{store}/shifts', [$c, 'open']);
            Route::post('stores/{store}/pos/policy', [$c, 'updatePolicy']);
            Route::get('shifts/{shift}', [$c, 'show']);
            Route::post('shifts/{shift}/cash-movements', [$c, 'movement']);
            Route::post('shifts/{shift}/cash-movement-requests', [$c, 'requestMovement']);
            Route::post('shift-cash-requests/{requestRecord}/approve', [$c, 'approveMovementRequest']);
            Route::post('shift-cash-requests/{requestRecord}/reject', [$c, 'rejectMovementRequest']);
            Route::post('shift-cash-requests/{requestRecord}/cancel', [$c, 'cancelMovementRequest']);
            Route::post('shifts/{shift}/close', [$c, 'close']);
            Route::post('shifts/{shift}/force-close', [$c, 'forceClose']);
        });
    }
}
