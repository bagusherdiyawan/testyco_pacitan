<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Inventory\Application\StockAccess;
use App\Modules\Inventory\Application\StockControl;
use App\Modules\Inventory\Infrastructure\InventoryCount;
use App\Modules\Inventory\Infrastructure\InventoryProcess;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class StockControlController
{
    public function __construct(private StockAccess $access, private StockControl $control) {}

    public function context(Request $r, string $store): mixed
    {
        $s = $this->access->staff($r->user(), $store);

        return ['data' => ['store_id' => $s->id, 'store_name' => $s->name, 'active' => $s->active, 'can_process' => $s->active, 'can_propose' => $s->active, 'can_review' => $s->active && $this->access->canReview($r->user(), $s)]];
    }

    public function processes(Request $r, string $store): mixed
    {
        $this->access->staff($r->user(), $store);

        return InventoryProcess::where('store_id', $store)->with('batch')->orderByDesc('id')->paginate(50);
    }

    public function counts(Request $r, string $store): mixed
    {
        $s = $this->access->staff($r->user(), $store);
        $reviewer = $this->access->canReview($r->user(), $s);

        return InventoryCount::where('store_id', $store)->with('batch')->orderByDesc('id')->paginate(50)->through(function ($count) use ($r, $s, $reviewer) {
            $stale = $count->status === 'pending' && $count->snapshot_revision !== $count->batch->stock_revision;

            return [...$count->toArray(), 'is_stale' => $stale, 'can_review' => $s->active && $reviewer && (int) $count->created_by !== $r->user()->id && $count->status === 'pending', 'can_cancel' => $s->active && (int) $count->created_by === $r->user()->id && $count->status === 'pending'];
        });
    }

    public function process(Request $r, string $store): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'batch_id' => 'required|ulid', 'expected_revision' => 'required|integer|min:0', 'kind' => 'required|in:open,cook', 'quantity_packs' => 'required_if:kind,open|prohibited_unless:kind,open|integer|min:1|max:10000', 'input_pieces' => 'required_if:kind,cook|prohibited_unless:kind,cook|integer|min:1|max:100000000', 'output_pieces' => 'required_if:kind,cook|prohibited_unless:kind,cook|integer|min:0|max:100000000', 'reason' => 'required|string|max:2000']);
        try {
            $result = $this->control->process($r->user(), $store, $v);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        return response()->json(['data' => $result], 201);
    }

    public function propose(Request $r, string $store): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'batch_id' => 'required|ulid', 'expected_revision' => 'required|integer|min:0', 'purpose' => 'required|in:stocktake,waste', 'bucket' => 'required|in:usable,opened,ready,quarantined', 'counted_pieces' => 'required|integer|min:0|max:1000000000', 'reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->control->propose($r->user(), $store, $v)], 201);
    }

    public function review(Request $r, string $count, string $action): mixed
    {
        abort_unless(in_array($action, ['approve', 'reject', 'cancel'], true), 404);
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'review_note' => 'required|string|max:2000']);

        return ['data' => $this->control->review($r->user(), $count, $action, $v)];
    }
}
