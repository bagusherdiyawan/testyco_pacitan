<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Presentation\ApiSession;
use App\Modules\Identity\Presentation\AuditController;
use App\Modules\Identity\Presentation\AuthController;
use App\Modules\Identity\Presentation\OrganizationController;
use App\Modules\Identity\Presentation\UserReactivationController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/login', [AuthController::class, 'form'])->name('login');
            Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
            Route::middleware(ApiSession::class)->group(function () {
                Route::get('/app/account', [AuthController::class, 'passwordForm']);
                Route::post('/app/account/password', [AuthController::class, 'password']);
                Route::post('/logout', [AuthController::class, 'logout']);
                Route::get('/app', [OrganizationController::class, 'index']);
                Route::post('/app/organizations', [OrganizationController::class, 'create']);
                Route::get('/app/organizations/{organization}/audit', [AuditController::class, 'index']);
                Route::get('/app/organizations/{organization}', [OrganizationController::class, 'show']);
                Route::post('/app/organizations/{organization}/status', [OrganizationController::class, 'organizationStatus']);
                Route::post('/app/organizations/{organization}/stores', [OrganizationController::class, 'createStore']);
                Route::post('/app/organizations/{organization}/users', [OrganizationController::class, 'createUser']);
                Route::post('/app/organizations/{organization}/users/{user}/membership', [OrganizationController::class, 'membership']);
                Route::post('/app/organizations/{organization}/users/{user}/deactivate', [OrganizationController::class, 'deactivate']);
                Route::get('/app/organizations/{organization}/users/{user}/reactivation', [UserReactivationController::class, 'show'])->whereNumber('user');
                Route::post('/app/organizations/{organization}/users/{user}/reactivate', [UserReactivationController::class, 'store'])->whereNumber('user');
                Route::post('/app/organizations/{organization}/users/{user}/reactivation/retry', [UserReactivationController::class, 'retry'])->whereNumber('user');
                Route::post('/app/stores/{store}/status', [OrganizationController::class, 'storeStatus']);
            });
        });
    }
}
