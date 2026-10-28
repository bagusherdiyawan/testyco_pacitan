<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Identity\Presentation\AuthController;
use App\Modules\Identity\Presentation\IdentityController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()), Limit::perMinute(30)->by($r->ip())]);
        RateLimiter::for('business', fn (Request $r) => Limit::perMinute(180)->by($r->user()?->id ?? $r->ip()));
        Route::middleware(['api', 'throttle:login'])->post('/api/v1/auth/login', [AuthController::class, 'login']);
        Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            Route::post('auth/password', [AuthController::class, 'password']);
            Route::get('auth/me', [AuthController::class, 'me']);
            Route::post('auth/logout', [AuthController::class, 'logout']);
            Route::get('organizations', [IdentityController::class, 'organizations']);
            Route::post('organizations', [IdentityController::class, 'createOrganization']);
            Route::get('organizations/{organization}', [IdentityController::class, 'organization']);
            Route::patch('organizations/{organization}', [IdentityController::class, 'updateOrganization']);
            Route::get('stores', [IdentityController::class, 'stores']);
            Route::get('organizations/{organization}/stores', [IdentityController::class, 'organizationStores']);
            Route::post('organizations/{organization}/stores', [IdentityController::class, 'createStore']);
            Route::patch('stores/{store}', [IdentityController::class, 'updateStore']);
            Route::get('organizations/{organization}/users', [IdentityController::class, 'users']);
            Route::post('organizations/{organization}/users', [IdentityController::class, 'createUser']);
            Route::patch('organizations/{organization}/users/{user}/membership', [IdentityController::class, 'updateMembership']);
            Route::post('organizations/{organization}/users/{user}/deactivate', [IdentityController::class, 'deactivateUser']);
            Route::get('organizations/{organization}/audit', [IdentityController::class, 'audit']);
        });
    }
}
