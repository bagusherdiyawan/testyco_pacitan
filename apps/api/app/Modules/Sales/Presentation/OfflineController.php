<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Sales\Application\OfflineCash;
use Illuminate\Http\Request;

final class OfflineController
{
    public function __construct(private OfflineCash $offline) {}

    private function lines(): array
    {
        return ['lines' => 'required|array|min:1|max:100', 'lines.*.product_id' => 'required|ulid|distinct', 'lines.*.unit' => 'required|in:piece,portion,pack', 'lines.*.quantity' => 'required|integer|min:1|max:10000', 'lines.*.allocations' => 'required|array|min:1|max:100', 'lines.*.allocations.*.batch_id' => 'required|ulid', 'lines.*.allocations.*.bucket' => 'required|in:usable,opened,ready', 'lines.*.allocations.*.quantity' => 'required|integer|min:1|max:10000', 'lines.*.allocations.*.component_product_id' => 'nullable|ulid'];
    }

    public function permit(Request $r, string $store): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'shift_id' => 'required|ulid', 'duration_minutes' => 'required|integer|min:5|max:480']);

        return response()->json(['data' => $this->offline->permit($r->user(), $store, $v)], 201);
    }

    public function end(Request $r, string $permit): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'queue_empty' => 'required|boolean']);

        return ['data' => $this->offline->end($r->user(), $permit, $v)];
    }

    public function context(Request $r, string $store): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json($this->offline->context($r->user(), $store))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, string $offline): mixed
    {
        return response()->json(['data' => $this->offline->show($r->user(), $offline)])->header('Cache-Control', 'private, no-store');
    }

    public function sync(Request $r, string $store): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'permit_id' => 'required|ulid', 'captured_at' => ['required', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'], 'client_total' => 'required|integer|min:0|max:1000000000000', 'cash_received' => 'required|integer|min:0|max:1000000000000', ...$this->lines()]);
        abort_if($r->hasAny(['voucher_code', 'payment_method']), 422, 'Offline hanya tunai tanpa voucher.');

        return response()->json(['data' => $this->offline->sync($r->user(), $store, $v)], 201);
    }

    public function resolve(Request $r, string $offline, string $action): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000', 'reconciliation_reference' => 'required|string|max:2000', 'verified' => 'required|boolean', ...($action === 'accept' ? ['shift_id' => 'required|ulid', 'shift_version' => 'required|integer|min:1'] : []), ...($r->has('lines') ? $this->lines() : [])]);

        return ['data' => $this->offline->resolve($r->user(), $offline, $action, $v)];
    }
}
