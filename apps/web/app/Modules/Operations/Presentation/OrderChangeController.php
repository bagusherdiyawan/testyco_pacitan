<?php

namespace App\Modules\Operations\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class OrderChangeController
{
    public function __construct(private Backend $api) {}

    private function slot(string $order): string
    {
        return 'order_changes_pending.'.$order;
    }

    public function index(Request $r, string $order): mixed
    {
        $pending = session($this->slot($order));
        if ($pending !== null) {
            return response()->view('pages.operations.order-changes', ['orderId' => $order, 'pending' => $pending])->header('Cache-Control', 'private, no-store');
        }
        $v = $r->validate(['page' => 'sometimes|integer|min:1']);
        $page = (int) ($v['page'] ?? 1);
        $data = $this->api->request('GET', "orders/$order/changes", ['page' => $page])->json();
        abort_unless(is_array($data) && data_get($data, 'order.id') === $order && is_array($data['data'] ?? null) && is_array($data['actions'] ?? null), 503, 'Cakupan usulan perubahan API tidak valid.');
        foreach ($data['data'] as $change) {
            abort_unless(($change['supply_order_id'] ?? null) === $order, 503, 'Usulan bukan milik pesanan ini.');
        }
        $changes = new LengthAwarePaginator($data['data'], $data['total'], $data['per_page'], $data['current_page'], ['path' => url()->current()]);

        return response()->view('pages.operations.order-changes', ['orderId' => $order, 'pending' => null, 'context' => $data, 'changes' => $changes])->header('Cache-Control', 'private, no-store');
    }

    public function propose(Request $r, string $order): mixed
    {
        abort_if(session()->has($this->slot($order)), 409, 'Selesaikan request yang hasilnya belum pasti terlebih dahulu.');
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000', 'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array:order_line_id,quantity_packs', 'lines.*.order_line_id' => 'required|ulid|distinct', 'lines.*.quantity_packs' => 'required|integer|min:1|max:10000']);

        return $this->send($order, ['path' => "orders/$order/changes", 'body' => $v, 'action' => 'pending', 'change_id' => null, 'uncertain' => false]);
    }

    public function decide(Request $r, string $order, string $change, string $action): mixed
    {
        abort_unless(in_array($action, ['approve', 'reject', 'withdraw'], true), 404);
        abort_if(session()->has($this->slot($order)), 409, 'Selesaikan request yang hasilnya belum pasti terlebih dahulu.');
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => $action === 'approve' ? 'nullable|string|max:2000' : 'required|string|max:2000']);

        return $this->send($order, ['path' => "orders/$order/changes/$change/$action", 'body' => $v, 'action' => ['approve' => 'approved', 'reject' => 'rejected', 'withdraw' => 'withdrawn'][$action], 'change_id' => $change, 'uncertain' => false]);
    }

    public function retry(string $order): mixed
    {
        $pending = session($this->slot($order));
        abort_unless(is_array($pending), 409, 'Tidak ada request yang perlu diulang.');

        return $this->send($order, $pending);
    }

    private function send(string $order, array $pending): mixed
    {
        session()->put($this->slot($order), $pending);
        try {
            $result = $this->api->request('POST', $pending['path'], $pending['body'])->json('data');
            if (! is_array($result) || data_get($result, 'order.id') !== $order || data_get($result, 'order.version') !== ((int) $pending['body']['version'] + 1) || data_get($result, 'change.supply_order_id') !== $order || data_get($result, 'change.status') !== $pending['action'] || ! is_string(data_get($result, 'change.id')) || ($pending['change_id'] !== null && data_get($result, 'change.id') !== $pending['change_id'])) {
                throw new \UnexpectedValueException('ACK tidak valid.');
            }
        } catch (HttpResponseException $e) {
            // Backend already invalidated the session on 401. Never restore an old payload into it.
            throw $e;
        } catch (\Throwable $e) {
            $known = $e instanceof ValidationException || ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500);
            if ($known && ! $pending['uncertain']) {
                session()->forget($this->slot($order));
                throw $e;
            }
            $pending['uncertain'] = true;
            session()->put($this->slot($order), $pending);

            return redirect('/app/orders/'.$order.'/changes')->with('status', 'Hasil request belum dapat dipastikan. Ulangi request yang sama; jangan buat pengganti.');
        }
        session()->forget($this->slot($order));

        return redirect('/app/orders/'.$order.'/changes')->with('status', 'Respons diterima. Periksa riwayat terbaru; replay dapat mengembalikan hasil historis.');
    }
}
