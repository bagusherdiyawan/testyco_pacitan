<?php

namespace App\Modules\Reporting\Presentation;

use App\Modules\Reporting\Application\CommercialReport;
use Illuminate\Http\Request;

final class CommercialReportController
{
    public function __construct(private CommercialReport $report) {}

    public function read(Request $r, string $organization): mixed
    {
        $v = $r->validate(['from' => ['required', 'string', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'], 'to' => ['required', 'string', 'date', 'after:from', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/']]);

        return response()->json($this->report->read($r->user(), $organization, $v))->header('Cache-Control', 'private, no-store');
    }

    public function cost(Request $r, string $organization): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'source_type' => 'required|in:pos_sale,wholesale_invoice', 'source_id' => 'required|ulid', 'version' => 'required|integer|min:0', 'goods_cost' => 'required|integer|min:0|max:1000000000000', 'operating_cost' => 'required|integer|min:0|max:1000000000000', 'evidence_reference' => 'required|string|max:2000', 'reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->report->declare($r->user(), $organization, $v)], 201);
    }
}
