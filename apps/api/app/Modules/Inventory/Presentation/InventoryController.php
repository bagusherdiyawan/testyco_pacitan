<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Identity\Application\Access;
use App\Modules\Inventory\Application\BatchCatalogue;
use App\Modules\Inventory\Application\LedgerHistory;
use App\Modules\Inventory\Application\WarehouseIntake;
use Illuminate\Http\Request;

final class InventoryController
{
    public function __construct(private Access $access, private WarehouseIntake $intake) {}

    public function batches(Request $r, string $store, BatchCatalogue $catalogue): mixed
    {
        $v = $r->validate([
            'page' => 'sometimes|integer|min:1', 'q' => 'nullable|string|max:100',
            'expires_from' => 'nullable|string|date_format:Y-m-d', 'expires_to' => 'nullable|string|date_format:Y-m-d',
            'with_balance' => 'sometimes|boolean',
        ]);

        return response()->json($catalogue->read($r->user(), $store, $v))->header('Cache-Control', 'private, no-store');
    }

    public function ledger(Request $r, string $store, LedgerHistory $history): mixed
    {
        $v = LedgerQuery::validate($r);

        return response()->json($history->read($r->user(), $store, $v))->header('Cache-Control', 'private, no-store');
    }

    private function intakeRules(): array
    {
        return ['product_id' => 'required|ulid', 'batch_code' => 'required|string|max:80', 'expires_on' => 'required|date_format:Y-m-d', 'quantity_packs' => 'required|integer|min:1|max:10000', 'note' => 'required|string|max:2000'];
    }

    public function intakeProducts(Request $r, string $store): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json($this->intake->products($r->user(), $store))->header('Cache-Control', 'private, no-store');
    }

    public function previewIntake(Request $r, string $store): mixed
    {
        $v = $r->validate($this->intakeRules());

        return response()->json(['data' => $this->intake->preview($r->user(), $store, $v)])->header('Cache-Control', 'private, no-store');
    }

    public function intake(Request $r, string $store): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'review_hash' => 'sometimes|required|string|size:64|regex:/^[a-f0-9]{64}$/', ...$this->intakeRules()]);

        return response()->json(['data' => $this->intake->record($r->user(), $store, $v)], 201);
    }
}
