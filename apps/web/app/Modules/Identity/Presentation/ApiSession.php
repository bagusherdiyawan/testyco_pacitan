<?php

namespace App\Modules\Identity\Presentation;

use Closure;
use Illuminate\Http\Request;

final class ApiSession
{
    public function handle(Request $r, Closure $next): mixed
    {
        if (! $r->session()->has('api_token') || $r->session()->get('api_expires_at', 0) <= time()) {
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return redirect('/login');
        }

        return $next($r);
    }
}
