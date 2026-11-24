<?php

namespace App\Modules\Shifts\Presentation;

use App\Modules\Shifts\Application\PosAccess;
use App\Modules\Shifts\Application\ShiftBlockers;
use App\Modules\Shifts\Application\ShiftBook;
use App\Modules\Shifts\Infrastructure\CashMovement;
use App\Modules\Shifts\Infrastructure\CashMovementRequest;
use App\Modules\Shifts\Infrastructure\CashShift;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ShiftController
{
    public function __construct(private PosAccess $access, private ShiftBook $book, private ShiftBlockers $blockers) {}

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
        $canManage = $this->access->canManage($r->user(), $s->organization_id);
        $q = CashShift::where('store_id', $store);
        if (! $canManage) {
            $q->where('cashier_id', $r->user()->id);
        }
        $page = $q->orderByDesc('id')->paginate(50);
        $page->setCollection(
            $page->getCollection()->map(fn (CashShift $row) => $this->book->presentForViewer($row->toArray(), $canManage))
        );

        return $page;
    }

    public function show(Request $r, string $shift): mixed
    {
        $s = CashShift::findOrFail($shift);
        $store = $this->access->store($r->user(), $s->store_id, false);
        $isOwner = (int) $s->cashier_id === $r->user()->id;
        $canManage = $this->access->canManage($r->user(), $store->organization_id);
        abort_unless($isOwner || $canManage, 403);

        $presented = $this->book->presentForViewer($s->toArray(), $canManage);
        $requests = CashMovementRequest::where('cash_shift_id', $shift)->orderByDesc('id')->get()->toArray();

        return response()->json([
            'data' => [
                ...$presented,
                'is_owner' => $isOwner,
                'can_manage' => $canManage,
                'can_record_movement' => $canManage && $store->active && $s->status === 'open',
                'can_request_movement' => ($isOwner || $canManage) && $store->active && $s->status === 'open',
                'can_force_close' => $canManage && $s->status === 'open',
                'movement_categories' => ShiftBook::MOVEMENT_CATEGORIES,
                'movements' => CashMovement::where('cash_shift_id', $shift)->orderBy('id')->get(),
                'cash_movement_requests' => $requests,
                'noncash_summary' => $this->blockers->noncashSummary($shift),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function open(Request $r, string $store): mixed
    {
        $v = $r->validate([
            'operation_id' => 'required|uuid',
            'device_name' => 'required|string|max:80',
            'opening_cash' => 'required|integer|min:0|max:1000000000000',
            'opening_note' => 'nullable|string|max:2000',
        ]);

        return $this->run(fn () => response()->json(['data' => $this->book->open($r->user(), $store, $v)], 201));
    }

    public function movement(Request $r, string $shift): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'kind' => 'required|in:in,out', 'amount' => 'required|integer|min:1|max:1000000000000', 'category' => 'required|string|max:80', 'reason' => 'required|string|max:2000']);

        return $this->run(fn () => response()->json(['data' => $this->book->movement($r->user(), $shift, $v)], 201));
    }

    public function requestMovement(Request $r, string $shift): mixed
    {
        $v = $r->validate([
            'operation_id' => 'required|uuid',
            'kind' => 'required|in:in,out',
            'amount' => 'required|integer|min:1|max:1000000000000',
            'category' => 'required|string|max:80',
            'reason' => 'required|string|max:2000',
            'evidence_reference' => 'nullable|string|max:160',
        ]);

        return $this->run(fn () => response()->json(['data' => $this->book->requestMovement($r->user(), $shift, $v)], 201));
    }

    public function approveMovementRequest(Request $r, string $requestRecord): mixed
    {
        $v = $r->validate([
            'operation_id' => 'required|uuid',
            'version' => 'required|integer|min:1',
            'decision_note' => 'nullable|string|max:2000',
        ]);

        return $this->run(fn () => ['data' => $this->book->approveMovementRequest($r->user(), $requestRecord, $v)]);
    }

    public function rejectMovementRequest(Request $r, string $requestRecord): mixed
    {
        $v = $r->validate([
            'operation_id' => 'required|uuid',
            'version' => 'required|integer|min:1',
            'decision_note' => 'required|string|max:2000',
        ]);

        return $this->run(fn () => ['data' => $this->book->rejectMovementRequest($r->user(), $requestRecord, $v)]);
    }

    public function cancelMovementRequest(Request $r, string $requestRecord): mixed
    {
        $v = $r->validate([
            'operation_id' => 'required|uuid',
            'version' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:2000',
        ]);

        return $this->run(fn () => ['data' => $this->book->cancelMovementRequest($r->user(), $requestRecord, $v)]);
    }

    public function close(Request $r, string $shift): mixed
    {
        $v = $r->validate([
            'operation_id' => 'required|uuid',
            'version' => 'required|integer|min:1',
            'counted_cash' => 'required|integer|min:0|max:1000000000000',
            'retained_cash' => 'nullable|integer|min:0|max:1000000000000',
            'closing_note' => 'nullable|string|max:2000',
        ]);

        return $this->run(fn () => ['data' => $this->book->close($r->user(), $shift, $v)]);
    }

    public function forceClose(Request $r, string $shift): mixed
    {
        $v = $r->validate([
            'operation_id' => 'required|uuid',
            'version' => 'required|integer|min:1',
            'counted_cash' => 'required|integer|min:0|max:1000000000000',
            'retained_cash' => 'nullable|integer|min:0|max:1000000000000',
            'reason' => 'required|string|min:3|max:2000',
            'closing_note' => 'nullable|string|max:2000',
        ]);

        return $this->run(fn () => ['data' => $this->book->forceClose($r->user(), $shift, $v)]);
    }

    public function updatePolicy(Request $r, string $store): mixed
    {
        $v = $r->validate([
            'operation_id' => 'required|uuid',
            'single_active_shift' => 'required|boolean',
        ]);

        return $this->run(fn () => ['data' => $this->book->updateStorePolicy($r->user(), $store, $v)]);
    }
}
