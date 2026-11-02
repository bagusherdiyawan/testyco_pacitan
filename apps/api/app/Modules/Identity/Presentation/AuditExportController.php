<?php

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\AuditHistory;
use Illuminate\Http\Request;

final class AuditExportController
{
    public function __construct(private AuditHistory $audit) {}

    public function __invoke(Request $r, string $organization): mixed
    {
        return response()->json($this->audit->export($r->user(), $organization, AuditQuery::validate($r, true)))
            ->header('Cache-Control', 'private, no-store');
    }
}
