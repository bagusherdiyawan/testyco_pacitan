<?php

namespace App\Modules\Workspace;

use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

final class WorkspaceController
{
    public function __invoke(): View
    {
        try {
            $response = Http::timeout(3)->acceptJson()->get(rtrim(config('backend.url'), '/').'/api/v1/modules');
            $response->throw();
            $modules = $response->json('data');
            if (! is_array($modules)) {
                throw new \RuntimeException('Invalid manifest');
            }

            return view('pages.workspace', ['modules' => $modules, 'unavailable' => false]);
        } catch (\Throwable $error) {
            report($error);

            return view('pages.workspace', ['modules' => [], 'unavailable' => true]);
        }
    }
}
