<?php

namespace App\Modules\Operations\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ShipmentDiscrepancyController
{
    public function __construct(private Backend $api) {}

    private function slot(string $shipment): string
    {
        return 'shipment_discrepancy_pending.'.$shipment;
    }

    private function path(string $order, string $shipment): string
    {
        return "orders/$order/shipments/$shipment/discrepancy";
    }

    private function context(string $order, string $shipment, array $query = []): array
    {
        $c = $this->api->request('GET', $this->path($order, $shipment), $query)->json();
        abort_unless(is_array($c) && data_get($c, 'order.id') === $order && data_get($c, 'shipment.id') === $shipment && data_get($c, 'shipment.supply_order_id') === $order
            && is_int(data_get($c, 'order.version')) && is_array($c['actions'] ?? null) && is_array($c['data'] ?? null) && is_array($c['resolutions'] ?? null), 503, 'Cakupan kasus kiriman API tidak valid.');
        $case = $c['case'] ?? null;
        if ($case !== null) {
            abort_unless(is_array($case) && ($case['supply_order_id'] ?? null) === $order && ($case['shipment_id'] ?? null) === $shipment && is_string($case['id'] ?? null) && is_int($case['version'] ?? null), 503, 'Kasus bukan milik kiriman ini.');
        }
        foreach ($c['data'] as $note) {
            abort_unless($case && ($note['shipment_discrepancy_id'] ?? null) === $case['id'], 503, 'Catatan kasus API tidak valid.');
        }
        foreach ($c['resolutions']['data'] ?? [] as $plan) {
            abort_unless($case && ($plan['shipment_discrepancy_id'] ?? null) === $case['id'], 503, 'Riwayat rencana bukan milik kasus ini.');
        }
        if (($c['pending_resolution'] ?? null) !== null) {
            abort_unless($case && data_get($c, 'pending_resolution.shipment_discrepancy_id') === $case['id'] && data_get($c, 'pending_resolution.status') === 'pending', 503, 'Rencana pending API tidak valid.');
        }
        foreach ([$c, $c['resolutions']] as $page) {
            abort_unless(is_array($page['data'] ?? null) && is_int($page['total'] ?? null) && $page['total'] >= 0 && ($page['per_page'] ?? null) === 50 && is_int($page['current_page'] ?? null) && $page['current_page'] > 0, 503, 'Pagination kasus API tidak valid.');
        }
        abort_unless($c['current_page'] === (int) ($query['page'] ?? 1) && $c['resolutions']['current_page'] === (int) ($query['resolutions_page'] ?? 1), 503, 'Halaman kasus API tidak sesuai.');

        return $c;
    }

    public function show(Request $r, string $order, string $shipment): mixed
    {
        $pending = session($this->slot($shipment));
        if ($pending !== null) {
            if ($pending['order_id'] !== $order) {
                return redirect('/app/'.$this->path($pending['order_id'], $shipment));
            }

            return response()->view('pages.operations.shipment-discrepancy', compact('order', 'shipment', 'pending'))->header('Cache-Control', 'private, no-store');
        }
        $v = $r->validate(['page' => 'sometimes|integer|min:1', 'resolutions_page' => 'sometimes|integer|min:1']);
        $c = $this->context($order, $shipment, $v);
        $notes = new LengthAwarePaginator($c['data'], $c['total'], 50, $c['current_page'], ['path' => url()->current(), 'pageName' => 'page', 'query' => ['resolutions_page' => $c['resolutions']['current_page']]]);
        $history = $c['resolutions'];
        $resolutions = new LengthAwarePaginator($history['data'], $history['total'], 50, $history['current_page'], ['path' => url()->current(), 'pageName' => 'resolutions_page', 'query' => ['page' => $c['current_page']]]);

        return response()->view('pages.operations.shipment-discrepancy', compact('order', 'shipment', 'pending', 'c', 'notes', 'resolutions'))->header('Cache-Control', 'private, no-store');
    }

    public function report(Request $r, string $order, string $shipment): mixed
    {
        return $this->submit($r, $order, $shipment, 'report');
    }

    public function note(Request $r, string $order, string $shipment): mixed
    {
        return $this->submit($r, $order, $shipment, 'note');
    }

    public function propose(Request $r, string $order, string $shipment): mixed
    {
        return $this->submit($r, $order, $shipment, 'propose');
    }

    public function decide(Request $r, string $order, string $shipment, string $resolution, string $action): mixed
    {
        abort_unless(in_array($action, ['confirm', 'reject', 'withdraw'], true), 404);

        return $this->submit($r, $order, $shipment, $action, $resolution);
    }

    private function submit(Request $r, string $order, string $shipment, string $action, ?string $resolution = null): mixed
    {
        abort_if(session()->has($this->slot($shipment)), 409, 'Selesaikan request yang belum pasti dahulu.');
        $rules = match ($action) {
            'report' => ['order_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000', 'reference' => 'nullable|string|max:500'],
            'note' => ['version' => 'required|integer|min:1', 'message' => 'required|string|max:2000', 'reference' => 'nullable|string|max:500'],
            'propose' => ['version' => 'required|integer|min:1', 'type' => 'required|in:return,replacement,credit', 'proposal' => 'required|string|max:2000', 'reference' => 'nullable|string|max:500'],
            default => ['version' => 'required|integer|min:1', 'reason' => $action === 'confirm' ? 'nullable|string|max:2000' : 'required|string|max:2000'],
        };
        $v = $r->validate(['operation_id' => 'required|uuid', ...$rules]);
        $c = $this->context($order, $shipment);
        $cap = match ($action) {
            'propose' => 'propose_resolution','confirm','reject','withdraw' => $action.'_resolution',default => $action
        };
        abort_unless(($c['actions'][$cap] ?? false) === true && ($action === 'report' ? $c['order']['version'] === (int) $v['order_version'] : data_get($c, 'case.version') === (int) $v['version']), 409, 'Hak, versi atau status kasus berubah. Tinjau kembali.');
        if ($resolution !== null) {
            abort_unless(data_get($c, 'pending_resolution.id') === $resolution, 409, 'Usulan yang ditinjau berubah.');
        }
        $suffix = match ($action) {
            'report' => '','note' => '/notes','propose' => '/resolutions',default => "/resolutions/$resolution/$action"
        };
        $review = ['order' => $c['order'], 'shipment' => $c['shipment'], 'snapshot' => $c['snapshot'], 'pending_resolution' => $c['pending_resolution'] ?? null];

        return $this->send($order, $shipment, ['order_id' => $order, 'path' => $this->path($order, $shipment).$suffix, 'body' => $v, 'action' => $action, 'case_id' => data_get($c, 'case.id'), 'resolution_id' => $resolution, 'review' => $review, 'uncertain' => false]);
    }

    public function retry(string $order, string $shipment): mixed
    {
        $pending = session($this->slot($shipment));
        abort_unless(is_array($pending) && $pending['order_id'] === $order, 409, 'Tidak ada request yang perlu diulang pada kiriman ini.');

        return $this->send($order, $shipment, $pending);
    }

    private function send(string $order, string $shipment, array $pending): mixed
    {
        session()->put($this->slot($shipment), $pending);
        try {
            $d = $this->api->request('POST', $pending['path'], $pending['body'])->json('data');
            $version = $pending['action'] === 'report' ? 1 : (int) $pending['body']['version'] + 1;
            if (! is_array($d) || ($d['order_id'] ?? null) !== $order || ($d['shipment_id'] ?? null) !== $shipment || data_get($d, 'case.supply_order_id') !== $order || data_get($d, 'case.shipment_id') !== $shipment
                || ! is_string(data_get($d, 'case.id')) || data_get($d, 'case.id') === '' || data_get($d, 'case.version') !== $version || ($pending['case_id'] !== null && data_get($d, 'case.id') !== $pending['case_id'])) {
                throw new \UnexpectedValueException('ACK kasus tidak valid.');
            }
            if ($pending['action'] === 'note' && (! is_string(data_get($d, 'note.id')) || data_get($d, 'note.id') === '' || data_get($d, 'note.shipment_discrepancy_id') !== $d['case']['id'] || data_get($d, 'note.case_version') !== $version || data_get($d, 'note.message') !== $pending['body']['message'])) {
                throw new \UnexpectedValueException('ACK catatan tidak valid.');
            }
            if ($pending['action'] === 'report' && (data_get($d, 'case.reason') !== $pending['body']['reason'] || data_get($d, 'case.snapshot') !== $pending['review']['snapshot'])) {
                throw new \UnexpectedValueException('ACK laporan tidak valid.');
            }
            if (in_array($pending['action'], ['propose', 'confirm', 'reject', 'withdraw'], true)) {
                $status = ['propose' => 'pending', 'confirm' => 'confirmed', 'reject' => 'rejected', 'withdraw' => 'withdrawn'][$pending['action']];
                if ($pending['action'] === 'propose' && (data_get($d, 'resolution.type') !== $pending['body']['type'] || data_get($d, 'resolution.proposal') !== $pending['body']['proposal'])) {
                    throw new \UnexpectedValueException('ACK isi rencana tidak valid.');
                }
                if (data_get($d, 'resolution.shipment_discrepancy_id') !== $d['case']['id'] || data_get($d, 'resolution.status') !== $status || ! is_string(data_get($d, 'resolution.id')) || data_get($d, 'resolution.id') === ''
                    || ($pending['resolution_id'] !== null && data_get($d, 'resolution.id') !== $pending['resolution_id'])) {
                    throw new \UnexpectedValueException('ACK rencana tidak valid.');
                }
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

            return redirect('/app/'.$this->path($order, $shipment))->with('status', 'Hasil request belum pasti. Ulangi request yang sama; jangan membuat pengganti.');
        }
        session()->forget($this->slot($shipment));

        return redirect('/app/'.$this->path($order, $shipment))->with('status', 'Respons diterima. Periksa riwayat terbaru; replay dapat historis.');
    }
}
