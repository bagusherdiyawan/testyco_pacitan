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

        $supplies = null;
        if ($context['shift']) {
            try {
                $supplies = $this->api->request('GET', "stores/$store/consumables")->json('data.items');
            } catch (\Throwable) {
                // Supplies tracking is optional; the cart simply hides the service type choice.
            }
        }

        return view('pages.pos.index', ['supplies' => is_array($supplies) && count($supplies) ? $supplies : null, 'context' => $context, 'products' => $context['shift'] ? $this->api->all("stores/$store/catalog") : [], 'batches' => $context['shift'] ? $this->api->all("stores/$store/inventory/batches") : [], 'shifts' => $this->api->page("stores/$store/shifts")]);
    }

    public function open(Request $r, string $store): mixed
    {
        $payload = $r->only('operation_id', 'device_name', 'opening_cash');
        if ($r->filled('opening_note')) {
            $payload['opening_note'] = $r->input('opening_note');
        }
        $this->api->request('POST', "stores/$store/shifts", $payload);

        return redirect('/app/stores/'.$store.'/pos')->with('status', 'Shift dibuka.');
    }

    public function policy(Request $r, string $store): mixed
    {
        $this->api->request('POST', "stores/$store/pos/policy", [
            'operation_id' => $r->input('operation_id'),
            'single_active_shift' => $r->boolean('single_active_shift'),
        ]);

        return redirect('/app/stores/'.$store.'/pos')->with('status', 'Kebijakan laci shift toko diperbarui.');
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
        $r->validate(['service_type' => 'nullable|in:dine_in,takeaway']);
        $v = $r->only('operation_id', 'shift_id', 'voucher_code');
        if ($r->filled('service_type')) {
            $v['service_type'] = $r->input('service_type');
        }
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
        return view('pages.pos.receipt', [
            'sale' => $this->api->request('GET', "sales/$sale")->json('data'),
            'receiptSettings' => session('pos_receipt_settings', [
                'header_text' => '',
                'footer_text' => 'Simpan sebagai bukti transaksi',
                'show_address' => true,
                'show_cashier' => true,
                'show_discount' => true,
                'show_logo' => false,
                'logo_data_uri' => null,
            ]),
        ]);
    }

    public function saveReceiptSettings(Request $r): mixed
    {
        $v = $r->validate([
            'header_text' => 'nullable|string|max:160',
            'footer_text' => 'nullable|string|max:240',
            'show_address' => 'nullable|boolean',
            'show_cashier' => 'nullable|boolean',
            'show_discount' => 'nullable|boolean',
            'show_logo' => 'nullable|boolean',
            'remove_logo' => 'nullable|boolean',
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,webp|max:512',
        ]);
        $current = session('pos_receipt_settings', []);
        $logoUri = $current['logo_data_uri'] ?? null;
        if ($r->boolean('remove_logo')) {
            $logoUri = null;
        }
        if ($r->hasFile('logo')) {
            $file = $r->file('logo');
            $mime = $file->getMimeType();
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                $logoUri = 'data:'.$mime.';base64,'.base64_encode($file->getContent());
            }
        }
        session([
            'pos_receipt_settings' => [
                'header_text' => trim((string) ($v['header_text'] ?? '')),
                'footer_text' => trim((string) ($v['footer_text'] ?? '')),
                'show_address' => $r->boolean('show_address'),
                'show_cashier' => $r->boolean('show_cashier'),
                'show_discount' => $r->boolean('show_discount'),
                'show_logo' => $logoUri !== null && $r->boolean('show_logo'),
                'logo_data_uri' => $logoUri,
            ],
        ]);

        return back()->with('status', 'Pengaturan tampilan struk thermal disimpan.');
    }

    public function productImage(string $store, string $product): mixed
    {
        $response = $this->api->request('GET', "stores/$store/catalog/$product/image");
        $mime = $response->header('Content-Type');
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 503);

        return response($response->body())
            ->header('Content-Type', $mime)
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    public function shift(string $shift): mixed
    {
        return view('pages.pos.shift', ['shift' => $this->api->request('GET', "shifts/$shift")->json('data')]);
    }

    public function close(Request $r, string $shift): mixed
    {
        $payload = $r->only('operation_id', 'version', 'counted_cash', 'closing_note');
        if ($r->filled('retained_cash')) {
            $payload['retained_cash'] = (int) $r->input('retained_cash');
        }
        $this->api->request('POST', "shifts/$shift/close", $payload);

        return redirect('/app/pos/shifts/'.$shift)->with('status', 'Shift ditutup; hasil hitung & selisih tersimpan.');
    }

    public function forceClose(Request $r, string $shift): mixed
    {
        $payload = $r->only('operation_id', 'version', 'counted_cash', 'reason', 'closing_note');
        if ($r->filled('retained_cash')) {
            $payload['retained_cash'] = (int) $r->input('retained_cash');
        }
        $this->api->request('POST', "shifts/$shift/force-close", $payload);

        return redirect('/app/pos/shifts/'.$shift)->with('status', 'Shift ditutup paksa oleh pengelola.');
    }

    public function movement(Request $r, string $shift): mixed
    {
        $this->api->request('POST', "shifts/$shift/cash-movements", $r->only('operation_id', 'version', 'kind', 'amount', 'category', 'reason'));

        return redirect('/app/pos/shifts/'.$shift)->with('status', 'Pergerakan kas dicatat terpisah dari penjualan.');
    }

    public function requestMovement(Request $r, string $shift): mixed
    {
        $this->api->request('POST', "shifts/$shift/cash-movement-requests", $r->only('operation_id', 'kind', 'amount', 'category', 'reason', 'evidence_reference'));

        return redirect('/app/pos/shifts/'.$shift)->with('status', 'Pengajuan kas dikirim ke pengelola untuk persetujuan.');
    }

    public function approveRequest(Request $r, string $requestRecord): mixed
    {
        $shift = $r->input('shift_id');
        $this->api->request('POST', "shift-cash-requests/$requestRecord/approve", $r->only('operation_id', 'version', 'decision_note'));

        return redirect('/app/pos/shifts/'.$shift)->with('status', 'Pengajuan kas disetujui dan saldo shift diperbarui.');
    }

    public function rejectRequest(Request $r, string $requestRecord): mixed
    {
        $shift = $r->input('shift_id');
        $this->api->request('POST', "shift-cash-requests/$requestRecord/reject", $r->only('operation_id', 'version', 'decision_note'));

        return redirect('/app/pos/shifts/'.$shift)->with('status', 'Pengajuan kas ditolak.');
    }

    public function cancelRequest(Request $r, string $requestRecord): mixed
    {
        $shift = $r->input('shift_id');
        $this->api->request('POST', "shift-cash-requests/$requestRecord/cancel", $r->only('operation_id', 'version', 'reason'));

        return redirect('/app/pos/shifts/'.$shift)->with('status', 'Pengajuan kas dibatalkan.');
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
