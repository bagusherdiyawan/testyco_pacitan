<?php

namespace App\Modules\Commercial\Application;

use App\Models\User;
use App\Modules\Accounting\Application\CommercialPosting;
use App\Modules\Accounting\Application\ConsignorCosting;
use App\Modules\Commercial\Infrastructure\CommercialAgreement;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Modules\Partnership\Infrastructure\PartnershipContract;
use App\Modules\Promotions\Infrastructure\Voucher;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AgreementWorkflow
{
    public function __construct(private Access $access, private Operations $ops) {}

    public function manager(User $u, string $org): void
    {
        abort_unless(in_array($this->access->role($u, $org)?->value, ['owner', 'admin', 'partner_lead'], true), 403);
    }

    public function party(User $u, string $org, CommercialAgreement $g): void
    {
        $this->manager($u, $org);
        abort_unless(in_array($org, [$g->organization_id, $g->partner_id], true), 403);
    }

    public function index(User $u, string $org): array
    {
        $this->manager($u, $org);

        return ['organization_id' => $org, ...CommercialAgreement::where(fn ($q) => $q->where('organization_id', $org)->orWhere('partner_id', $org))->orderByDesc('id')->paginate(50)->toArray()];
    }

    public function options(User $u, string $org, ?string $orderId, ?string $query): array
    {
        $this->manager($u, $org);
        $organization = Organization::findOrFail($org);
        $contracts = PartnershipContract::where('status', 'accepted')->where(fn ($q) => $q->where('organization_id', $org)->orWhere('partner_id', $org))->orderByDesc('id')->limit(101)->get(['id', 'organization_id', 'partner_id', 'title', 'mode', 'starts_on', 'ends_on']);
        $orders = SupplyOrder::where('supplier_organization_id', $org)->where('kind', 'wholesale')->whereNotIn('status', ['awaiting_approval', 'cancelled', 'rejected'])->when($query, fn ($q) => $q->where('id', 'like', '%'.$query.'%'))->orderByDesc('id')->limit(101)->get(['id', 'organization_id', 'store_id', 'status']);
        $selected = $orderId ? SupplyOrder::where('supplier_organization_id', $org)->with('lines')->findOrFail($orderId) : null;

        return ['organization_id' => $org, 'organization_type' => $organization->type, 'contracts' => $contracts->take(100)->toArray(), 'orders' => $orders->take(100)->toArray(), 'has_more_contracts' => $contracts->count() > 100, 'has_more_orders' => $orders->count() > 100, 'selected_order' => $selected?->toArray(), 'stores' => DB::table('stores')->where('organization_id', $org)->where('active', true)->orderBy('name')->get(['id', 'name'])->all(), 'products' => DB::table('products')->where('organization_id', $org)->where('active', true)->orderBy('sku')->get(['id', 'sku', 'name'])->all()];
    }

    public function show(User $u, string $org, string $id): array
    {
        $g = CommercialAgreement::findOrFail($id);
        $this->party($u, $org, $g);
        $balance = (int) DB::table('commercial_entries')->where('commercial_agreement_id', $id)->sum('amount') - (int) DB::table('commercial_payments')->where('commercial_agreement_id', $id)->sum('signed_amount');

        return ['organization_id' => $org, 'agreement' => $g->toArray(), 'supplier_receivable' => $balance, 'own_receivable' => $org === $g->organization_id ? $balance : -$balance, 'entries' => DB::table('commercial_entries')->where('commercial_agreement_id', $id)->orderByDesc('id')->paginate(50)->toArray(), 'payments' => DB::table('commercial_payments')->where('commercial_agreement_id', $id)->orderByDesc('id')->paginate(50, ['*'], 'payments_page')->toArray()];
    }

    public function propose(User $u, string $org, array $v): array
    {
        $this->manager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'commercial.propose:'.$org, $v, function () use ($u, $org, $v) {
            $c = PartnershipContract::lockForUpdate()->findOrFail($v['contract_id']);
            abort_unless($c->status === 'accepted' && in_array($org, [$c->organization_id, $c->partner_id], true), 403);
            abort_unless($v['starts_on'] >= $c->starts_on->format('Y-m-d') && $v['ends_on'] <= $c->ends_on->format('Y-m-d') && $v['starts_on'] >= now('Asia/Jakarta')->format('Y-m-d'), 422, 'Periode eksplisit harus tercakup kontrak dan tidak mundur.');
            $order = null;
            $t = $v['terms'];
            if ($v['kind'] === 'consignment') {
                abort_unless($org === $c->organization_id && $c->mode === 'consignment', 403);
                $order = SupplyOrder::where('supplier_organization_id', $c->organization_id)->where('organization_id', $c->partner_id)->lockForUpdate()->findOrFail($v['order_id']);
                abort_unless($order->kind === 'wholesale' && ! in_array($order->status, ['cancelled', 'rejected', 'awaiting_approval'], true), 409);
                $this->unbilled($order->id);
                abort_if(CommercialAgreement::where('active_order_id', $order->id)->exists(), 409, 'Pesanan sudah ditautkan ke perjanjian.');
                if ($t['basis'] === 'margin') {
                    $ids = $order->lines->pluck('id')->sort()->values()->all();
                    $costIds = array_column($t['costs'], 'order_line_id');
                    sort($costIds);
                    abort_unless($ids === $costIds, 422, 'HPP berbukti wajib untuk setiap baris tepat sekali.');
                }
            } else {
                abort_unless($org === $c->partner_id, 403, 'Pimpinan penerbit voucher mengajukan pendanaan kepada sponsor.');
                foreach ($t['voucher']['store_ids'] as $id) {
                    abort_unless(DB::table('stores')->where('organization_id', $org)->where('active', true)->where('id', $id)->exists(), 422);
                }
                foreach ($t['voucher']['product_ids'] as $id) {
                    abort_unless(DB::table('products')->where('organization_id', $org)->where('active', true)->where('id', $id)->exists(), 422);
                }
                abort_if(Voucher::where('organization_id', $org)->where('code', $t['voucher']['code'])->exists(), 409, 'Kode voucher sudah digunakan.');
            }
            $g = CommercialAgreement::create(['contract_id' => $c->id, 'organization_id' => $c->organization_id, 'partner_id' => $c->partner_id, 'proposer_organization_id' => $org, 'supply_order_id' => $order?->id, 'active_order_id' => $order?->id, 'kind' => $v['kind'], 'status' => 'pending', 'version' => 1, 'starts_on' => $v['starts_on'], 'ends_on' => $v['ends_on'], 'terms' => $t, 'reference' => $v['reference'], 'created_by' => $u->id]);
            $this->audit($u, $g, 'proposed');

            return $g->toArray();
        });
    }

    public function unbilled(string $order): void
    {
        abort_if(DB::table('invoices')->where('supply_order_id', $order)->whereIn('status', ['pending', 'accepted'])->exists() || DB::table('consignment_reports')->where('supply_order_id', $order)->whereIn('status', ['pending', 'accepted'])->exists(), 409, 'Pesanan sudah mempunyai tagihan/laporan manual; jangan tagih dua kali.');
    }

    public function decide(User $u, string $org, string $id, string $action, array $v): array
    {
        $initial = CommercialAgreement::findOrFail($id);
        $this->party($u, $org, $initial);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'commercial.'.$action.':'.$id, $v, function () use ($u, $org, $id, $action, $v) {
            if ($initialOrder = CommercialAgreement::findOrFail($id)->supply_order_id) {
                SupplyOrder::lockForUpdate()->findOrFail($initialOrder);
                if ($action === 'accept') {
                    app(ConsignmentAutomation::class)->lockOrderBatches($initialOrder);
                }
            }
            $q = Voucher::where('commercial_agreement_id', $id)->lockForUpdate()->first();
            $g = CommercialAgreement::lockForUpdate()->findOrFail($id);
            $this->party($u, $org, $g);
            abort_unless($g->version === (int) $v['version'], 409);
            if ($action === 'suspend') {
                abort_unless($g->status === 'accepted', 409);
                $g->status = 'suspended';
                $g->stopped_at = now();
                if ($q) {
                    $q->update(['active' => false, 'version' => $q->version + 1]);
                }
            } else {
                abort_unless($g->status === 'pending', 409);
                if ($action === 'withdraw') {
                    abort_unless($org === $g->proposer_organization_id, 403);
                } else {
                    abort_unless($org !== $g->proposer_organization_id, 403);
                }
                $g->status = match ($action) {
                    'accept' => 'accepted','reject' => 'rejected','withdraw' => 'withdrawn'
                };
                if ($action === 'accept') {
                    abort_unless(($v['consent'] ?? false) === true && ($v['sharing_consented'] ?? false) === true, 422);
                    $c = PartnershipContract::findOrFail($g->contract_id);
                    abort_unless($c->status === 'accepted' && $g->ends_on->format('Y-m-d') >= now('Asia/Jakarta')->format('Y-m-d') && Organization::whereIn('id', [$g->organization_id, $g->partner_id])->where('active', true)->count() === 2, 409);
                    if ($g->kind === 'consignment') {
                        $this->unbilled($g->supply_order_id);
                        app(ConsignmentAutomation::class)->assertFreshOrder($g->supply_order_id);
                    } else {
                        $t = $g->terms;
                        Voucher::create(['organization_id' => $g->partner_id, 'commercial_agreement_id' => $g->id, 'funding_organization_id' => $g->organization_id, 'version' => 1, 'active' => true, 'used_count' => 0, 'starts_on' => $g->starts_on, 'ends_on' => $g->ends_on, 'created_by' => $g->created_by, ...$t['voucher']]);
                    }
                    $terms = $g->terms;
                    $terms['authorization'] = ['proposed_by' => $g->created_by, 'approved_by' => $u->id, 'approved_in_organization' => $org, 'approved_role' => $this->access->role($u, $org)?->value, 'approved_at' => now()->toIso8601String(), 'accepted_version' => $g->version + 1, 'reason' => $v['reason']];
                    $g->terms = $terms;
                    $g->activated_at = now();
                } else {
                    $g->active_order_id = null;
                }
            }
            $g->decided_by = $u->id;
            $g->decision_reason = $v['reason'];
            $g->version++;
            $g->save();
            if ($action === 'accept') {
                app(ConsignorCosting::class)->automatic($g, $u->id);
            }
            $this->audit($u, $g, $action);

            return $g->toArray();
        });
    }

    private function audit(User $u, CommercialAgreement $g, string $action): void
    {
        foreach ([$g->organization_id, $g->partner_id] as $org) {
            Audit::record($u->id, $org, 'commercial.'.$action, $g->id, null, $g->toArray());
        }
    }

    public function payment(User $u, string $org, string $id, array $v): array
    {
        $g = CommercialAgreement::findOrFail($id);
        $this->party($u, $org, $g);
        abort_unless($org === $g->organization_id, 403, 'Pemilik saldo pemasok/sponsor memverifikasi bukti pembayaran.');

        return $this->ops->run($org, $u->id, $v['operation_id'], 'commercial.payment:'.$id, $v, function () use ($u, $org, $id, $v) {
            $g = CommercialAgreement::lockForUpdate()->findOrFail($id);
            abort_unless(in_array($g->status, ['accepted', 'suspended'], true) && $g->version === (int) $v['version'] && $v['verified'] === true, 409);
            $balance = (int) DB::table('commercial_entries')->where('commercial_agreement_id', $id)->sum('amount') - (int) DB::table('commercial_payments')->where('commercial_agreement_id', $id)->sum('signed_amount');
            $signed = $v['direction'] === 'collection' ? (int) $v['amount'] : -(int) $v['amount'];
            abort_unless($signed > 0 && $signed <= $balance || $signed < 0 && $signed >= $balance, 422, 'Nominal/arah wajib sesuai saldo aktual.');
            $p = ['id' => (string) Str::ulid(), 'commercial_agreement_id' => $id, 'signed_amount' => $signed, 'method' => $v['method'], 'reference' => $v['reference'], 'evidence_reference' => $v['evidence_reference'], 'verified_by' => $u->id, 'paid_on' => $v['paid_on'], 'created_at' => now()->toIso8601String()];
            DB::table('commercial_payments')->insert($p);
            app(CommercialPosting::class)->payment($g, $p);
            $g->increment('version');
            foreach ([$g->organization_id, $g->partner_id] as $party) {
                Audit::record($u->id, $party, 'commercial.payment_verified', $p['id'], null, $p);
            }

            return ['organization_id' => $org, 'agreement_id' => $id, 'version' => $g->version, 'payment' => $p];
        });
    }
}
