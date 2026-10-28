<?php

namespace App\Modules\Workspace;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class WorkspaceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(function () {
            Route::get('/modules', fn () => response()->json(['data' => config('workspace.modules')]));
        });
    }
}
