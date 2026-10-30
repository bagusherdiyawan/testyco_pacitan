<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Sales\Application\CashCheckout;
use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Sales\Infrastructure\SaleIntent;
use App\Modules\Shifts\Application\PosAccess;
use App\Modules\Shifts\Application\ShiftBook;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PosController
{
    public function __construct(private PosAccess $access, private ShiftBook $shifts, private CashCheckout $checkout) {}

    private function run(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
    }

    public function context(Request $r, string $store): mixed
    {
        $s = $this->access->store($r->user(), $store, false);
        $shift = $this->shifts->active($r->user(), $store);

        return ['data' => ['store' => $s, 'can_manage' => $this->access->canManage($r->user(), $s->organization_id), 'shift' => $shift, 'pending_intents' => $shift ? SaleIntent::where('cash_shift_id', $shift->id)->where('status', 'pending')->orderByDesc('id')->get(['id', 'status', 'total_amount', 'created_at']) : [], 'recent_sales' => Sale::where('store_id', $store)->where('cashier_id', $r->user()->id)->orderByDesc('id')->limit(20)->get(['id', 'number', 'total_amount', 'sold_at'])]];
    }

    private function lineRules(): array
    {
        return ['lines' => 'required|array|min:1|max:100', 'lines.*.product_id' => 'required|ulid|distinct', 'lines.*.unit' => 'required|in:piece,portion,pack', 'lines.*.quantity' => 'required|integer|min:1|max:10000', 'lines.*.allocations' => 'required|array|min:1|max:100', 'lines.*.allocations.*.batch_id' => 'required|ulid', 'lines.*.allocations.*.bucket' => 'required|in:usable,opened,ready', 'lines.*.allocations.*.quantity' => 'required|integer|min:1|max:10000'];
    }

    public function create(Request $r, string $store): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'shift_id' => 'required|ulid', ...$this->lineRules()]);

        return $this->run(fn () => response()->json(['data' => $this->checkout->create($r->user(), $store, $v)], 201));
    }

    public function intent(Request $r, string $intent): mixed
    {
        $record = SaleIntent::findOrFail($intent);
        $s = $this->access->store($r->user(), $record->store_id, false);
        abort_unless((int) $record->cashier_id === $r->user()->id || $this->access->canManage($r->user(), $s->organization_id), 403);
        $sale = Sale::where('sale_intent_id', $intent)->first();

        return ['data' => ['intent' => $record, 'sale' => $sale ? $this->checkout->receipt($sale->id) : null, 'is_owner' => (int) $record->cashier_id === $r->user()->id]];
    }

    public function pay(Request $r, string $intent): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'payment_method' => 'required|in:cash', 'cash_received' => 'required|integer|min:0|max:1000000000000']);

        return $this->run(fn () => ['data' => $this->checkout->pay($r->user(), $intent, $v)]);
    }

    public function cancel(Request $r, string $intent): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'reason' => 'required|string|max:2000']);

        return ['data' => $this->checkout->cancel($r->user(), $intent, $v)];
    }

    public function sales(Request $r, string $store): mixed
    {
        $v = $r->validate([
            'page' => 'sometimes|integer|min:1',
            'q' => 'nullable|string|max:100',
            'from' => ['nullable', 'required_with:to', 'string', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'],
            'to' => ['nullable', 'required_with:from', 'string', 'date', 'after:from', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'],
        ]);

        return response()->json(app(\App\Modules\Sales\Application\ReceiptHistory::class)->read($r->user(), $store, $v))
            ->header('Cache-Control', 'private, no-store');
    }

    public function receipt(Request $r, string $sale): mixed
    {
        $record = Sale::findOrFail($sale);
        $s = $this->access->store($r->user(), $record->store_id, false);
        abort_unless((int) $record->cashier_id === $r->user()->id || $this->access->canManage($r->user(), $s->organization_id), 403);

        return ['data' => $this->checkout->receipt($sale)];
    }

    public function summary(Request $r, string $store): mixed
    {
        $this->access->manager($r->user(), $store, false);
        $v = $r->validate(['from' => ['required', 'string', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'], 'to' => ['required', 'string', 'date', 'after:from', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/']]);
        $from = CarbonImmutable::parse($v['from'])->utc();
        $to = CarbonImmutable::parse($v['to'])->utc();
        abort_if($to->timestamp - $from->timestamp > 31 * 86400, 422, 'Rentang laporan maksimal 31 hari.');
        $q = Sale::where('store_id', $store)->where('sold_at', '>=', $from)->where('sold_at', '<', $to);

        return ['data' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'transaction_count' => (clone $q)->count(), 'gross_sales' => (int) (clone $q)->sum('subtotal'), 'discount_amount' => (int) (clone $q)->sum('discount_amount'), 'net_sales' => (int) (clone $q)->sum('total_amount'), 'cash_sales' => (int) (clone $q)->sum('total_amount'), 'currency' => 'IDR']];
    }
}
