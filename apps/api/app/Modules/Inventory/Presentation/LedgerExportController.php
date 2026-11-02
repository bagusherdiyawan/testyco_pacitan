<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Inventory\Application\LedgerHistory;
use Illuminate\Http\Request;

final class LedgerExportController
{
    public function __construct(private LedgerHistory $history) {}

    public function __invoke(Request $r, string $store): mixed
    {
        return response()->json($this->history->export($r->user(), $store, LedgerQuery::validate($r, true)))->header('Cache-Control', 'private, no-store');
    }
}
