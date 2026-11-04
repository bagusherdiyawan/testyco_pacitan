<?php

namespace App\Modules\Pos\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\PendingMutation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class PosController
{
    public function __construct(private Backend $api, private PendingMutation $pending) {}

    public function index(string $store): mixed
    {
        $context = $this->api->request('GET', "stores/$store/pos/context")->json('data');

        return view('pages.pos.index', ['context' => $context, 'products' => $context['shift'] ? $this->api->all("stores/$store/catalog") : [], 'batches' => $context['shift'] ? $this->api->all("stores/$store/inventory/batches") : [], 'shifts' => $this->api->page("stores/$store/shifts")]);
    }

    public function open(Request $r, string $store): mixed
    {
        $this->api->request('POST', "stores/$store/shifts", $r->only('operation_id', 'device_name', 'opening_cash'));

        return redirect('/app/stores/'.$store.'/pos')->with('status', 'Shift dibuka.');
    }

    public function create(Request $r, string $store): mixed
    {
        $r->validate(['lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array', 'lines.*.stock' => 'required|string']);
        $lines = $r->input('lines', []);
        foreach ($lines as &$line) {
            $stock = explode(':', (string) ($line['stock'] ?? ''), 2);
            $line = ['product_id' => $line['product_id'] ?? null, 'unit' => $line['unit'] ?? null, 'quantity' => $line['quantity'] ?? null, 'allocations' => [['batch_id' => $stock[0] ?? null, 'bucket' => $stock[1] ?? null, 'quantity' => $line['quantity'] ?? null]]];
        }
        unset($line);
        $v = $r->only('operation_id', 'shift_id', 'voucher_code');
        $v['lines'] = array_values($lines);
        $intent = $this->api->request('POST', "stores/$store/sales/intents", $v)->json('data');

        return redirect('/app/pos/intents/'.$intent['id']);
    }

    public function intent(string $intent): mixed
    {
        $pending = session('pos_payment_pending.'.$intent);
        if ($pending) {
            return response()->view('pages.pos.payment-pending', compact('pending', 'intent'))->header('Cache-Control', 'private, no-store');
        }
        $data = $this->api->request('GET', "sales/intents/$intent")->json('data');
        if ($data['sale']) {
            return redirect('/app/pos/sales/'.$data['sale']['id']);
        }

        return view('pages.pos.intent', $data);
    }

    public function pay(Request $r, string $intent): mixed
    {
        $p = session('pos_payment_pending.'.$intent);
        if (! $p) {
            $v = $r->only('operation_id', 'payment_method', 'cash_received', 'payment_reference', 'verified', 'verified_amount');
            foreach (['cash_received', 'verified_amount'] as $key) {
                if (isset($v[$key])) {
                    $v[$key] = (int) $v[$key];
                }
            }if (isset($v['verified'])) {
                $v['verified'] = $r->boolean('verified');
            }$p = ['path' => "sales/intents/$intent/pay", 'body' => $v, 'target' => '/app/pos/intents/'.$intent, 'uncertain' => false];
        }

        return $this->pending->send('pos_payment_pending.'.$intent, $p, fn ($d, $p) => ($d['sale_intent_id'] ?? null) === $intent && is_string($d['id'] ?? null) && ($d['payment_method'] ?? null) === $p['body']['payment_method'] && ($d['payment_method'] === 'cash' ? ($d['cash_received'] ?? null) === $p['body']['cash_received'] : ($d['payment_reference'] ?? null) === $p['body']['payment_reference'] && ($d['total_amount'] ?? null) === $p['body']['verified_amount']), fn ($d) => '/app/pos/sales/'.$d['id']);
    }

    public function cancel(Request $r, string $intent): mixed
    {
        abort_if(session()->has('pos_payment_pending.'.$intent), 409, 'Selesaikan pembayaran yang belum pasti.');
        $result = $this->api->request('POST', "sales/intents/$intent/cancel", $r->only('operation_id', 'reason'))->json('data');

        return redirect('/app/stores/'.$result['store_id'].'/pos')->with('status', 'Draft dibatalkan tanpa perubahan kas/stok.');
    }

    public function receipt(string $sale): mixed
    {
        return view('pages.pos.receipt', ['sale' => $this->api->request('GET', "sales/$sale")->json('data')]);
    }

    public function shift(string $shift): mixed
    {
        return view('pages.pos.shift', ['shift' => $this->api->request('GET', "shifts/$shift")->json('data')]);
    }

    public function close(Request $r, string $shift): mixed
    {
        $this->api->request('POST', "shifts/$shift/close", $r->only('operation_id', 'version', 'counted_cash', 'closing_note'));

        return redirect('/app/pos/shifts/'.$shift)->with('status', 'Shift ditutup; selisih tersimpan.');
    }

    public function movement(Request $r, string $shift): mixed
    {
        $this->api->request('POST', "shifts/$shift/cash-movements", $r->only('operation_id', 'version', 'kind', 'amount', 'category', 'reason'));

        return redirect('/app/pos/shifts/'.$shift)->with('status', 'Pergerakan kas dicatat terpisah dari penjualan.');
    }

    public function report(Request $r, string $store): mixed
    {
        $v = $r->validate(['day' => 'sometimes|date_format:Y-m-d']);
        $day = isset($v['day']) ? CarbonImmutable::parse($v['day'], 'Asia/Jakarta') : CarbonImmutable::now('Asia/Jakarta');
        $from = $day->startOfDay();
        $to = $from->addDay();

        return view('pages.pos.report', ['store' => $store, 'day' => $from->format('Y-m-d'), 'summary' => $this->api->request('GET', "stores/$store/sales/summary", ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()])->json('data')]);
    }
}
