<?php

namespace App\Modules\Assets\Presentation;

use App\Modules\Assets\Application\AssetWorkflow;
use Illuminate\Http\Request;

final class AssetController
{
    public function __construct(private AssetWorkflow $workflow) {}

    private function key(): array
    {
        return ['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1'];
    }

    public function index(Request $r, string $organization): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json($this->workflow->index($r->user(), $organization))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, string $asset): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1', 'incidents_page' => 'sometimes|integer|min:1']);

        return response()->json($this->workflow->context($r->user(), $asset))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r, string $organization): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'number' => 'required|string|max:80', 'kind' => 'required|in:freezer,pos_device,other', 'brand' => 'required|string|max:120', 'serial_number' => 'required|string|max:160', 'condition_note' => 'required|string|max:2000', 'ownership_reference' => 'required|string|max:2000']);

        return response()->json(['data' => $this->workflow->create($r->user(), $organization, $v)], 201);
    }

    public function propose(Request $r, string $asset): mixed
    {
        $v = $r->validate([...$this->key(), 'store_id' => 'required|ulid', 'contract_id' => 'nullable|ulid', 'responsible_name' => 'required|string|max:120', 'handed_over_on' => 'required|date_format:Y-m-d', 'condition_note' => 'required|string|max:2000', 'agreement_reference' => 'required|string|max:2000', 'evidence_reference' => 'required|string|max:2000']);

        return response()->json(['data' => $this->workflow->propose($r->user(), $asset, $v)], 201);
    }

    public function decide(Request $r, string $asset, string $placement, string $action): mixed
    {
        $v = $r->validate([...$this->key(), 'reason' => 'required|string|max:2000', 'consent' => 'sometimes|boolean', ...($action === 'request_return' ? ['returned_on' => 'required|date_format:Y-m-d', 'return_condition' => 'required|string|max:2000', 'return_reference' => 'required|string|max:2000'] : []), ...($action === 'confirm_return' ? ['disposition' => 'required|in:available,maintenance', 'inspection_note' => 'required|string|max:2000'] : [])]);

        return ['data' => $this->workflow->decide($r->user(), $asset, $placement, $action, $v)];
    }

    public function incident(Request $r, string $asset): mixed
    {
        $v = $r->validate([...$this->key(), 'description' => 'required|string|max:2000', 'evidence_reference' => 'required|string|max:2000', 'reported_on' => 'required|date_format:Y-m-d']);

        return response()->json(['data' => $this->workflow->incident($r->user(), $asset, $v)], 201);
    }

    public function resolve(Request $r, string $asset, string $incident): mixed
    {
        $v = $r->validate([...$this->key(), 'resolution' => 'required|string|max:2000', 'cost_basis_reference' => 'required|string|max:2000', 'owner_cost' => 'required|integer|min:0|max:1000000000000', 'recipient_cost' => 'required|integer|min:0|max:1000000000000', 'completed_on' => 'required|date_format:Y-m-d']);

        return ['data' => $this->workflow->resolve($r->user(), $asset, $incident, $v)];
    }
}
