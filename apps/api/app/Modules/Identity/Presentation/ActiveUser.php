<?php

namespace App\Modules\Identity\Presentation;

use Closure;
use Illuminate\Http\Request;

final class ActiveUser
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! $request->user()?->active) {
            $request->user()?->tokens()->delete();
            abort(401, 'Akun tidak aktif.');
        }

        return $next($request);
    }
}
