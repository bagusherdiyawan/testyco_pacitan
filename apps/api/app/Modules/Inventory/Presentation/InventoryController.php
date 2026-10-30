<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Identity\Application\Access;
use App\Modules\Inventory\Application\WarehouseIntake;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InventoryController
{
    public function __construct(private Access $access, private WarehouseIntake $intake) {}

    public function batches(Request $r, string $store): mixed
    {
        $this->access->store($r->user(), $store);

        return InventoryBatch::where('store_id', $store)->orderBy('expires_on')->orderBy('id')->paginate(100);
    }

    public function ledger(Request $r, string $store): mixed
    {
        $this->access->store($r->user(), $store, ['owner', 'admin', 'warehouse', 'partner_lead', 'store_manager']);

        return DB::table('stock_entries')->where('store_id', $store)->orderByDesc('id')->paginate(100);
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
