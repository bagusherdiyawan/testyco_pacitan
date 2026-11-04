<?php

namespace App\Modules\Reliability;

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Reliability\Application\Inbox;
use App\Modules\Reliability\Presentation\ReliabilityController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ReliabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([IdentityServiceProvider::class, InventoryServiceProvider::class] as $p) {
            if (! $this->app->getProvider($p)) {
                throw new \LogicException('Reliability dependency missing.');
            }
        }Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            Route::get('stores/{store}/food-safety/fefo', [ReliabilityController::class, 'fefo']);
            Route::get('inbox', [ReliabilityController::class, 'inbox']);
            Route::post('inbox/preferences', [ReliabilityController::class, 'preferences']);
            Route::post('inbox/{entry}/read', [ReliabilityController::class, 'mark']);
            Route::get('organizations/{organization}/food-safety', [ReliabilityController::class, 'policy']);
            Route::post('organizations/{organization}/food-safety', [ReliabilityController::class, 'save']);
            Route::post('organizations/{organization}/food-safety/recalls', [ReliabilityController::class, 'recall']);
            Route::post('organizations/{organization}/food-safety/recalls/{recall}/withdraw', [ReliabilityController::class, 'withdraw']);
            Route::post('organizations/{organization}/food-safety/ready', [ReliabilityController::class, 'ready']);
        });
        Artisan::command('reliability:deliver {--limit=100}', function () {
            $r = app(Inbox::class)->deliver(max(1, min(100, (int) $this->option('limit'))));
            $this->info(json_encode($r));
        });
    }
}
