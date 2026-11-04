<?php

namespace App\Modules\Operations\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class OrderRevisionController
{
    public function __construct(private Backend $api) {}

    private function slot(string $order): string
    {
        return 'order_revisions_pending.'.$order;
    }

    public function index(Request $r, string $order): mixed
    {
        $pending = session($this->slot($order));
        if ($pending !== null) {
            return response()->view('pages.operations.order-revisions', ['orderId' => $order, 'pending' => $pending])->header('Cache-Control', 'private, no-store');
        }
        $candidate = session('order_revision_review.'.$order);
        if ($candidate) {
            return response()->view('pages.operations.order-revision-review', ['orderId' => $order, 'candidate' => $candidate])->header('Cache-Control', 'private, no-store');
        }
        $v = $r->validate(['page' => 'sometimes|integer|min:1']);
        $page = (int) ($v['page'] ?? 1);
        $data = $this->api->request('GET', "orders/$order/revisions", ['page' => $page])->json();
        abort_unless(is_array($data) && data_get($data, 'order.id') === $order && is_array($data['data'] ?? null) && is_array($data['actions'] ?? null), 503, 'Cakupan usulan perubahan API tidak valid.');
        foreach ($data['data'] as $change) {
            abort_unless(($change['supply_order_id'] ?? null) === $order, 503, 'Usulan bukan milik pesanan ini.');
        }
        $changes = new LengthAwarePaginator($data['data'], $data['total'], $data['per_page'], $data['current_page'], ['path' => url()->current()]);

        return response()->view('pages.operations.order-revisions', ['orderId' => $order, 'pending' => null, 'context' => $data, 'changes' => $changes])->header('Cache-Control', 'private, no-store');
    }

    public function propose(Request $r, string $order): mixed
    {
        abort_if(session()->has($this->slot($order)), 409, 'Selesaikan request yang hasilnya belum pasti terlebih dahulu.');
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000', 'existing' => 'required|array|min:1|max:100', 'existing.*.order_line_id' => 'required|ulid|distinct', 'existing.*.quantity_packs' => 'required|integer|min:0|max:10000', 'additions' => 'sometimes|array|max:100', 'additions.*.supplier_product_id' => 'nullable|ulid', 'additions.*.local_product_id' => 'nullable|ulid', 'additions.*.quantity_packs' => 'nullable|integer|min:1|max:10000']);

        $v['additions'] = array_values(array_filter($v['additions'] ?? [], fn ($l) => ($l['quantity_packs'] ?? null) !== null));
        foreach ($v['additions'] as $line) {
            abort_unless(isset($line['supplier_product_id'], $line['local_product_id']), 422, 'Pilih SKU sumber/lokal untuk tambahan.');
        }
        $c = $this->api->request('GET', "orders/$order/revisions")->json();
        abort_unless(data_get($c, 'order.id') === $order && data_get($c, 'order.version') === (int) $v['version'] && data_get($c, 'actions.propose') === true, 409, 'Versi atau izin revisi berubah.');

        $quote = $this->api->request('POST', "orders/$order/revisions/preview", array_diff_key($v, ['operation_id' => true]))->json('data');
        abort_unless(is_array($quote) && ($quote['order_id'] ?? null) === $order && ($quote['version'] ?? null) === (int) $v['version'] && is_string($quote['review_hash'] ?? null), 503, 'Review revisi tidak valid.');
        $v['review_hash'] = $quote['review_hash'];
        session()->put('order_revision_review.'.$order, ['body' => $v, 'quote' => $quote]);

        return redirect('/app/orders/'.$order.'/revisions');
    }

    public function confirm(string $order): mixed
    {
        abort_if(session()->has($this->slot($order)), 409);
        $candidate = session('order_revision_review.'.$order);
        abort_unless(is_array($candidate), 409, 'Tinjau usulan dahulu.');
        session()->forget('order_revision_review.'.$order);

        return $this->send($order, ['path' => "orders/$order/revisions", 'body' => $candidate['body'], 'action' => 'pending', 'change_id' => null, 'uncertain' => false, 'review' => $candidate['quote']]);
    }

    public function discard(string $order): mixed
    {
        abort_if(session()->has($this->slot($order)), 409);
        session()->forget('order_revision_review.'.$order);

        return redirect('/app/orders/'.$order.'/revisions');
    }

    public function decide(Request $r, string $order, string $change, string $action): mixed
    {
        abort_unless(in_array($action, ['approve', 'reject', 'withdraw'], true), 404);
        abort_if(session()->has($this->slot($order)), 409, 'Selesaikan request yang hasilnya belum pasti terlebih dahulu.');
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => $action === 'approve' ? 'nullable|string|max:2000' : 'required|string|max:2000']);

        return $this->send($order, ['path' => "orders/$order/revisions/$change/$action", 'body' => $v, 'action' => ['approve' => 'approved', 'reject' => 'rejected', 'withdraw' => 'withdrawn'][$action], 'change_id' => $change, 'uncertain' => false]);
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
            if (! is_array($result) || data_get($result, 'order.id') !== $order || data_get($result, 'order.version') !== ((int) $pending['body']['version'] + 1) || data_get($result, 'revision.supply_order_id') !== $order || data_get($result, 'revision.status') !== $pending['action'] || ! is_string(data_get($result, 'revision.id')) || ($pending['change_id'] !== null && data_get($result, 'revision.id') !== $pending['change_id'])) {
                throw new \UnexpectedValueException('ACK tidak valid.');
            }
        } catch (HttpResponseException $e) {
            // Backend already invalidated the session on 401. Never restore an old payload into it.
            throw $e;
        } catch (\Throwable $e) {
            $known = $e instanceof ValidationException || ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 && ! in_array($e->getStatusCode(), [408, 429], true));
            if ($known && ! $pending['uncertain']) {
                session()->forget($this->slot($order));
                throw $e;
            }
            $pending['uncertain'] = true;
            session()->put($this->slot($order), $pending);

            return redirect('/app/orders/'.$order.'/revisions')->with('status', 'Hasil request belum dapat dipastikan. Ulangi request yang sama; jangan buat pengganti.');
        }
        session()->forget($this->slot($order));

        return redirect('/app/orders/'.$order.'/revisions')->with('status', 'Respons diterima. Periksa riwayat terbaru; replay dapat mengembalikan hasil historis.');
    }
}
