<?php

namespace App\Modules\Operations\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ReturnController
{
    public function __construct(private Backend $api) {}

    private function path(string $order, string $shipment): string
    {
        return "orders/$order/shipments/$shipment/discrepancy/returns";
    }

    private function slot(string $shipment): string
    {
        return 'return_pending.'.$shipment;
    }

    private function context(string $order, string $shipment, int $page = 1): array
    {
        $c = $this->api->request('GET', $this->path($order, $shipment), $page === 1 ? [] : ['page' => $page])->json();
        abort_unless(is_array($c) && ($c['order_id'] ?? null) === $order && ($c['shipment_id'] ?? null) === $shipment && is_string($c['case_id'] ?? null) && $c['case_id'] !== '' && is_int($c['case_version'] ?? null) && $c['case_version'] > 0
            && data_get($c, 'resolution.shipment_discrepancy_id') === $c['case_id'] && data_get($c, 'resolution.status') === 'confirmed' && data_get($c, 'resolution.type') === 'return'
            && is_array($c['quota'] ?? null) && is_array($c['batches'] ?? null) && is_array($c['data'] ?? null) && is_bool($c['can_dispatch'] ?? null)
            && ($c['current_page'] ?? null) === $page && ($c['per_page'] ?? null) === 50 && is_int($c['total'] ?? null) && $c['total'] >= count($c['data']) && count($c['data']) <= 50, 503, 'Cakupan eksekusi retur API tidak valid.');
        $ids = [];
        foreach ($c['quota'] as $line) {
            abort_unless(is_array($line) && is_string($line['shipment_line_id'] ?? null) && ! in_array($line['shipment_line_id'], $ids, true) && is_int($line['limit_packs'] ?? null) && $line['limit_packs'] > 0 && is_int($line['remaining_packs'] ?? null) && $line['remaining_packs'] >= 0 && $line['remaining_packs'] <= $line['limit_packs'], 503, 'Kuota retur API tidak valid.');
            $ids[] = $line['shipment_line_id'];
        }
        foreach ($c['batches'] as $batch) {
            abort_unless(($batch['store_id'] ?? null) === ($c['source_store_id'] ?? null) && is_string($batch['id'] ?? null) && collect($c['quota'])->contains(fn ($q) => ($q['allocation_product_id'] ?? null) === ($batch['product_id'] ?? null) && ($q['pack_size'] ?? null) === ($batch['pack_size'] ?? null)), 503, 'Batch sumber API tidak valid.');
        }
        foreach ($c['data'] as $row) {
            abort_unless(($row['case_id'] ?? null) === $c['case_id'] && ($row['resolution_id'] ?? null) === data_get($c, 'resolution.id') && ($row['source_store_id'] ?? null) === $c['source_store_id'] && is_array($row['lines'] ?? null) && is_bool($row['can_receive'] ?? null), 503, 'Kiriman retur API tidak valid.');
            foreach ($row['lines'] as $line) {
                abort_unless(($line['return_shipment_id'] ?? null) === ($row['id'] ?? null) && in_array($line['original_shipment_line_id'] ?? null, $ids, true), 503, 'Baris retur API tidak valid.');
            }
        }

        return $c;
    }

    public function show(Request $r, string $order, string $shipment): mixed
    {
        $pending = session($this->slot($shipment));
        if ($pending && $pending['order_id'] !== $order) {
            return redirect('/app/'.$this->path($pending['order_id'], $shipment));
        }
        if ($pending) {
            return response()->view('pages.operations.returns', compact('order', 'shipment', 'pending'))->header('Cache-Control', 'private, no-store');
        }
        $v = $r->validate(['page' => 'sometimes|integer|min:1']);
        $c = $this->context($order, $shipment, (int) ($v['page'] ?? 1));
        $history = new LengthAwarePaginator($c['data'], $c['total'], 50, $c['current_page'], ['path' => url()->current()]);

        return response()->view('pages.operations.returns', compact('order', 'shipment', 'pending', 'c', 'history'))->header('Cache-Control', 'private, no-store');
    }

    public function dispatch(Request $r, string $order, string $shipment): mixed
    {
        abort_if(session()->has($this->slot($shipment)), 409, 'Selesaikan request yang belum pasti.');
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'destination_bucket' => 'required|in:quarantined', 'handler' => 'required|string|max:120', 'note' => 'nullable|string|max:2000', 'lines' => 'required|array|min:1|max:100', 'lines.*.original_shipment_line_id' => 'required|ulid|distinct', 'lines.*.batch_id' => 'nullable|ulid', 'lines.*.quantity_packs' => 'nullable|integer|min:0|max:10000']);
        $c = $this->context($order, $shipment);
        abort_unless($c['can_dispatch'] && $c['case_version'] === (int) $v['version'], 409, 'Hak atau versi kasus berubah.');
        $rows = [];
        foreach ($v['lines'] as $row) {
            $packs = (int) ($row['quantity_packs'] ?? 0);
            if ($packs === 0) {
                continue;
            }
            $q = collect($c['quota'])->firstWhere('shipment_line_id', $row['original_shipment_line_id']);
            $b = collect($c['batches'])->firstWhere('id', $row['batch_id'] ?? null);
            abort_unless($q && $b && $packs <= $q['remaining_packs'] && $q['allocation_product_id'] === $b['product_id'] && $q['pack_size'] === $b['pack_size'] && $packs * $q['pack_size'] <= $b['quarantined_pieces'], 422, 'Periksa jumlah dan batch retur yang dipilih.');
            $rows[] = ['original_shipment_line_id' => $row['original_shipment_line_id'], 'allocations' => [['batch_id' => $row['batch_id'], 'quantity_packs' => $packs]]];
        }
        abort_unless(count($rows) > 0, 422, 'Isi minimal satu jumlah retur.');
        $v['lines'] = $rows;

        return $this->send($order, $shipment, ['order_id' => $order, 'path' => $this->path($order, $shipment), 'body' => $v, 'action' => 'dispatch', 'return_id' => null, 'case_id' => $c['case_id'], 'resolution_id' => $c['resolution']['id'], 'review' => $c, 'uncertain' => false]);
    }

    public function receive(Request $r, string $order, string $shipment, string $return): mixed
    {
        abort_if(session()->has($this->slot($shipment)), 409, 'Selesaikan request yang belum pasti.');
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'page' => 'sometimes|integer|min:1', 'note' => 'nullable|string|max:2000', 'lines' => 'required|array|min:1|max:10000', 'lines.*.return_line_id' => 'required|ulid|distinct', 'lines.*.received_packs' => 'required|integer|min:0|max:10000', 'lines.*.condition_note' => 'nullable|string|max:2000']);
        $c = $this->context($order, $shipment, (int) ($v['page'] ?? 1));
        unset($v['page']);
        $row = collect($c['data'])->firstWhere('id', $return);
        abort_unless($row && $row['can_receive'] && $c['case_version'] === (int) $v['version'], 409, 'Kiriman, hak atau versi kasus berubah.');
        abort_unless(count($v['lines']) === count($row['lines']), 422, 'Konfirmasikan semua baris tepat sekali.');
        foreach ($v['lines'] as $line) {
            $original = collect($row['lines'])->firstWhere('id', $line['return_line_id']);
            abort_unless($original && $original['quantity_packs'] >= $line['received_packs'] && ((int) $line['received_packs'] === $original['quantity_packs'] || trim((string) ($line['condition_note'] ?? '')) !== ''), 422, 'Periksa jumlah aktual dan alasan selisih.');
        }

        return $this->send($order, $shipment, ['order_id' => $order, 'path' => $this->path($order, $shipment)."/$return/receive", 'body' => $v, 'action' => 'receive', 'return_id' => $return, 'case_id' => $c['case_id'], 'resolution_id' => $c['resolution']['id'], 'review' => $c, 'uncertain' => false]);
    }

    public function retry(string $order, string $shipment): mixed
    {
        $p = session($this->slot($shipment));
        abort_unless(is_array($p) && $p['order_id'] === $order, 409, 'Tidak ada request pada kiriman ini.');

        return $this->send($order, $shipment, $p);
    }

    private function ack(array $p, mixed $d, string $order, string $shipment): bool
    {
        if (! is_array($d) || ($d['order_id'] ?? null) !== $order || ($d['shipment_id'] ?? null) !== $shipment || ($d['case_id'] ?? null) !== $p['case_id'] || ($d['case_version'] ?? null) !== (int) $p['body']['version'] + 1
            || data_get($d, 'return.case_id') !== $p['case_id'] || data_get($d, 'return.resolution_id') !== $p['resolution_id'] || ! is_string(data_get($d, 'return.id')) || data_get($d, 'return.id') === '' || ! is_array(data_get($d, 'return.lines'))
            || data_get($d, 'return.source_store_id') !== $p['review']['source_store_id']) {
            return false;
        }
        $lines = $d['return']['lines'];
        $actual = [];
        foreach ($lines as $line) {
            if (! is_string($line['id'] ?? null) || $line['id'] === '' || ($line['return_shipment_id'] ?? null) !== $d['return']['id']) {
                return false;
            }
            if ($p['action'] === 'dispatch') {
                $actual[] = [$line['original_shipment_line_id'] ?? null, $line['inventory_batch_id'] ?? null, $line['quantity_packs'] ?? null];
            } else {
                $actual[] = [$line['id'], $line['received_packs'] ?? null, $line['condition_note'] ?? null];
            }
        }
        $expected = [];
        if ($p['action'] === 'dispatch') {
            if (data_get($d, 'return.received_at') !== null || data_get($d, 'return.handler') !== $p['body']['handler']) {
                return false;
            }
            foreach ($p['body']['lines'] as $line) {
                foreach ($line['allocations'] as $a) {
                    $expected[] = [$line['original_shipment_line_id'], $a['batch_id'], (int) $a['quantity_packs']];
                }
            }
        } else {
            if ($d['return']['id'] !== $p['return_id'] || ! is_string(data_get($d, 'return.received_at'))) {
                return false;
            }
            foreach ($p['body']['lines'] as $line) {
                $expected[] = [$line['return_line_id'], (int) $line['received_packs'], $line['condition_note'] ?? null];
            }
        }
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    private function send(string $order, string $shipment, array $pending): mixed
    {
        session()->put($this->slot($shipment), $pending);
        try {
            $d = $this->api->request('POST', $pending['path'], $pending['body'])->json('data');
            if (! $this->ack($pending, $d, $order, $shipment)) {
                throw new \UnexpectedValueException('ACK retur tidak sesuai.');
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $known = $e instanceof ValidationException || ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 && ! in_array($e->getStatusCode(), [408, 429], true));
            if ($known && ! $pending['uncertain']) {
                session()->forget($this->slot($shipment));
                throw $e;
            }
            $pending['uncertain'] = true;
            session()->put($this->slot($shipment), $pending);

            return redirect('/app/'.$this->path($order, $shipment))->with('status', 'Hasil belum pasti. Ulangi request yang sama; jangan kirim/potong stok kembali.');
        }
        session()->forget($this->slot($shipment));

        return redirect('/app/'.$this->path($order, $shipment))->with('status', 'Respons diterima. Periksa posisi retur terbaru; replay dapat historis.');
    }
}
