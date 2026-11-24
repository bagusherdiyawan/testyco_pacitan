<?php

namespace App\Modules\Pos\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\PendingMutation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class RefundController
{
    public function __construct(private Backend $api, private PendingMutation $mutations) {}

    private function context(string $sale): array
    {
        $c = $this->api->request('GET', "sales/$sale/refunds")->json();
        abort_unless(is_array($c) && data_get($c, 'sale.id') === $sale && is_string(data_get($c, 'sale.store_id')) && is_int(data_get($c, 'sale.version')) && is_array(data_get($c, 'sale.lines')) && is_array($c['open_shifts'] ?? null) && is_array($c['refunds'] ?? null), 503, 'Cakupan refund tidak valid.');
        foreach ($c['sale']['lines'] as $l) {
            abort_unless(($l['sale_id'] ?? null) === $sale, 503);
        }foreach ($c['open_shifts'] as $s) {
            abort_unless(($s['store_id'] ?? null) === $c['sale']['store_id'] && ($s['status'] ?? null) === 'open' && is_int($s['version'] ?? null), 503);
        }foreach ($c['refunds'] as $r) {
            abort_unless(($r['sale_id'] ?? null) === $sale, 503);
        }

        return $c;
    }

    public function show(string $sale): mixed
    {
        $pending = session('refund_pending.'.$sale);
        $review = session('refund_review.'.$sale);
        $c = $pending || $review ? null : $this->context($sale);

        return response()->view('pages.pos.refunds', compact('sale', 'pending', 'review', 'c'))->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $r, string $sale): mixed
    {
        abort_if(session()->has('refund_pending.'.$sale) || session()->has('refund_review.'.$sale), 409, 'Selesaikan review sebelumnya.');
        $pair = explode(':', (string) $r->input('shift', ''), 2);
        $r->merge(['shift_id' => $pair[0] ?? null, 'shift_version' => $pair[1] ?? null]);
        $v = $r->validate(['version' => 'required|integer|min:1', 'shift_id' => 'required|ulid', 'shift_version' => 'required|integer|min:1', 'stock_disposition' => 'required|in:no_stock,quarantine', 'reference' => 'required|string|max:160', 'reason' => 'required|string|max:1800', 'lines' => 'required|array|min:1|max:100', 'lines.*.sale_line_id' => 'required|ulid|distinct', 'lines.*.quantity' => 'nullable|integer|min:0|max:10000']);
        $v['version'] = (int) $v['version'];
        $v['shift_version'] = (int) $v['shift_version'];
        $v['lines'] = array_values(array_map(fn ($l) => [...$l, 'quantity' => (int) $l['quantity']], array_filter($v['lines'], fn ($l) => (int) ($l['quantity'] ?? 0) > 0)));
        abort_unless(count($v['lines']), 422, 'Pilih jumlah refund.');
        $c = $this->context($sale);
        $s = collect($c['open_shifts'])->firstWhere('id', $v['shift_id']);
        abort_unless($s && $s['version'] === $v['shift_version'] && $c['sale']['version'] === $v['version'], 409, 'Versi struk/shift berubah.');
        $d = $this->api->request('POST', "sales/$sale/refunds/preview", $v)->json('data');
        abort_unless(is_array($d) && ($d['sale_id'] ?? null) === $sale && ($d['store_id'] ?? null) === $c['sale']['store_id'] && ($d['version'] ?? null) === $v['version'] && ($d['shift_id'] ?? null) === $v['shift_id'] && ($d['shift_version'] ?? null) === $v['shift_version'] && ($d['stock_disposition'] ?? null) === $v['stock_disposition'] && ($d['reference'] ?? null) === $v['reference'] && ($d['payment_method'] ?? null) === $c['sale']['payment_method'] && is_int($d['amount'] ?? null) && $d['amount'] >= 0 && is_string($d['review_hash'] ?? null) && strlen($d['review_hash']) === 64 && is_array($d['lines'] ?? null), 503, 'Review refund tidak valid.');
        $a = collect($d['lines'])->map(fn ($l) => [$l['sale_line_id'], $l['quantity']])->sort()->values()->all();
        $b = collect($v['lines'])->map(fn ($l) => [$l['sale_line_id'], $l['quantity']])->sort()->values()->all();
        abort_unless($a === $b, 503, 'Baris review refund tidak cocok.');
        session()->put('refund_review.'.$sale, ['path' => "sales/$sale/refunds", 'body' => [...$v, 'operation_id' => (string) Str::uuid(), 'review_hash' => $d['review_hash']], 'review' => $d, 'target' => "/app/pos/sales/$sale/refunds", 'uncertain' => false]);

        return redirect("/app/pos/sales/$sale/refunds");
    }

    public function discard(string $sale): mixed
    {
        abort_if(session()->has('refund_pending.'.$sale), 409);
        session()->forget('refund_review.'.$sale);

        return redirect("/app/pos/sales/$sale/refunds");
    }

    public function confirm(Request $r, string $sale): mixed
    {
        $r->validate(['verified' => 'required|accepted']);
        abort_if(session()->has('refund_pending.'.$sale), 409);
        $p = session('refund_review.'.$sale);
        abort_unless(is_array($p), 409);
        $p['body']['verified'] = true;
        session()->forget('refund_review.'.$sale);

        return $this->send($sale, $p);
    }

    public function retry(string $sale): mixed
    {
        $p = session('refund_pending.'.$sale);
        abort_unless(is_array($p), 409);

        return $this->send($sale, $p);
    }

    private function send(string $sale, array $p): mixed
    {
        return $this->mutations->send('refund_pending.'.$sale, $p, fn ($d, $p) => ($d['sale_id'] ?? null) === $sale && ($d['sale_version'] ?? null) === $p['body']['version'] + 1 && is_string(data_get($d, 'refund.id')) && data_get($d, 'refund.sale_id') === $sale && data_get($d, 'refund.cash_shift_id') === $p['body']['shift_id'] && data_get($d, 'refund.payment_method') === $p['review']['payment_method'] && data_get($d, 'refund.amount') === $p['review']['amount'] && data_get($d, 'refund.reference') === $p['body']['reference'] && data_get($d, 'refund.stock_disposition') === $p['body']['stock_disposition'] && $this->sameLines(data_get($d, 'refund.lines'), $p['review']['lines']));
    }

    /**
     * The API assigns a fresh source_id to every allocation when it commits the refund,
     * so the committed lines can never equal the reviewed lines byte for byte. Require
     * a non-empty string source_id and compare everything else exactly.
     */
    private function sameLines(mixed $committed, array $reviewed): bool
    {
        if (! is_array($committed)) {
            return false;
        }
        foreach ($committed as $i => $line) {
            foreach (($line['allocations'] ?? []) as $j => $allocation) {
                if (! is_string($allocation['source_id'] ?? null) || $allocation['source_id'] === '') {
                    return false;
                }
                unset($committed[$i]['allocations'][$j]['source_id']);
            }
        }

        return $committed === $reviewed;
    }
}
