<?php

namespace App\Modules\Workspace;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class WorkspaceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->get('/', WorkspaceController::class);
    }
}
