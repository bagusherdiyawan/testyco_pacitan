<?php

namespace App\Modules\Partnership\Presentation;

use App\Modules\Partnership\Application\ContractWorkflow;
use Illuminate\Http\Request;

final class ContractController
{
    public function __construct(private ContractWorkflow $work) {}

    public function index(Request $r, string $organization): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json($this->work->index($r->user(), $organization))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r, string $organization): mixed
    {
        $v = $r->validate(['attachment_base64' => 'sometimes|nullable|string|max:7000000', 'operation_id' => 'required|uuid', 'partner_id' => 'required|ulid', 'title' => 'required|string|max:160', 'mode' => 'required|in:buyout,consignment', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'terms' => 'required|string|max:30000', 'brand_terms' => 'required|string|max:10000', 'data_scope' => 'required|string|max:10000', 'facilities' => 'required|string|max:10000']);

        return response()->json(['data' => $this->work->create($r->user(), $organization, $v)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function attachment(Request $r, string $organization, string $contract): mixed
    {
        $file = $this->work->attachment($r->user(), $organization, $contract);

        return response($file['bytes'])->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="contract.pdf"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    public function decide(Request $r, string $organization, string $contract, string $action): mixed
    {
        abort_unless(in_array($action, ['publish', 'withdraw', 'accept', 'reject'], true), 404);
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => in_array($action, ['withdraw', 'reject'], true) ? 'required|string|max:2000' : 'nullable|string|max:2000', 'consent' => $action === 'accept' ? 'required|accepted' : 'prohibited', 'consent_name' => $action === 'accept' ? 'required|string|max:160' : 'prohibited']);
        if ($action === 'accept') {
            $v['consent'] = true;
        }

        return response()->json(['data' => $this->work->decide($r->user(), $organization, $contract, $action, $v)])->header('Cache-Control', 'private, no-store');
    }
}
