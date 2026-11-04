<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Presentation\ApiSession;
use App\Modules\Identity\Presentation\AuditController;
use App\Modules\Identity\Presentation\AuditExportController;
use App\Modules\Identity\Presentation\AuthController;
use App\Modules\Identity\Presentation\OrganizationController;
use App\Modules\Identity\Presentation\SecurityController;
use App\Modules\Identity\Presentation\UserReactivationController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->group(function () {
            $security = SecurityController::class;
            Route::get('/login/mfa', [$security, 'mfaForm']);
            Route::post('/login/mfa', [$security, 'mfa'])->middleware('throttle:5,1');
            Route::get('/forgot-password', [$security, 'forgotForm']);
            Route::post('/forgot-password', [$security, 'forgot'])->middleware('throttle:5,1');
            Route::get('/reset-password', [$security, 'resetForm']);
            Route::post('/reset-password', [$security, 'reset'])->middleware('throttle:5,1');
            Route::get('/login', [AuthController::class, 'form'])->name('login');
            Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
            Route::middleware(ApiSession::class)->group(function () {
                $security = SecurityController::class;
                Route::get('/app/account/security', [$security, 'account']);
                Route::post('/app/account/security/mfa/enroll', [$security, 'begin']);
                Route::post('/app/account/security/mfa/enable', [$security, 'enable']);
                Route::post('/app/account/security/mfa/disable', [$security, 'disable']);
                Route::post('/app/account/security/sessions/{token}/revoke', [$security, 'revoke'])->whereNumber('token');
                Route::get('/app/account', [AuthController::class, 'passwordForm']);
                Route::post('/app/account/password', [AuthController::class, 'password']);
                Route::post('/logout', [AuthController::class, 'logout']);
                Route::get('/app', [OrganizationController::class, 'index']);
                Route::post('/app/organizations', [OrganizationController::class, 'create']);
                Route::get('/app/organizations/{organization}/audit/export', AuditExportController::class);
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
