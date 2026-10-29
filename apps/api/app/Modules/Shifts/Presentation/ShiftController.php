<?php

namespace App\Modules\Shifts\Presentation;

use App\Modules\Shifts\Application\PosAccess;
use App\Modules\Shifts\Application\ShiftBook;
use App\Modules\Shifts\Infrastructure\CashMovement;
use App\Modules\Shifts\Infrastructure\CashShift;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ShiftController
{
    public function __construct(private PosAccess $access, private ShiftBook $book) {}

    private function run(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
    }

    public function index(Request $r, string $store): mixed
    {
        $s = $this->access->store($r->user(), $store, false);
        $q = CashShift::where('store_id', $store);
        if (! $this->access->canManage($r->user(), $s->organization_id)) {
            $q->where('cashier_id', $r->user()->id);
        }

        return $q->orderByDesc('id')->paginate(50);
    }

    public function show(Request $r, string $shift): mixed
    {
        $s = CashShift::findOrFail($shift);
        $store = $this->access->store($r->user(), $s->store_id, false);
        abort_unless((int) $s->cashier_id === $r->user()->id || $this->access->canManage($r->user(), $store->organization_id), 403);

        $canManage = $this->access->canManage($r->user(), $store->organization_id);

        return response()->json(['data' => [...$s->toArray(), 'is_owner' => (int) $s->cashier_id === $r->user()->id, 'can_manage' => $canManage, 'can_record_movement' => $canManage && $store->active && $s->status === 'open', 'movements' => CashMovement::where('cash_shift_id', $shift)->orderBy('id')->get()]])->header('Cache-Control', 'private, no-store');
    }

    public function open(Request $r, string $store): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'device_name' => 'required|string|max:80', 'opening_cash' => 'required|integer|min:0|max:1000000000000']);

        return $this->run(fn () => response()->json(['data' => $this->book->open($r->user(), $store, $v)], 201));
    }

    public function movement(Request $r, string $shift): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'kind' => 'required|in:in,out', 'amount' => 'required|integer|min:1|max:1000000000000', 'category' => 'required|string|max:80', 'reason' => 'required|string|max:2000']);

        return $this->run(fn () => response()->json(['data' => $this->book->movement($r->user(), $shift, $v)], 201));
    }

    public function close(Request $r, string $shift): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'counted_cash' => 'required|integer|min:0|max:1000000000000', 'closing_note' => 'nullable|string|max:2000']);

        return $this->run(fn () => ['data' => $this->book->close($r->user(), $shift, $v)]);
    }
}
