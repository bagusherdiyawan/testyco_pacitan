<?php

namespace App\Modules\Assets\Application;

use App\Models\User;
use App\Modules\Assets\Infrastructure\AssetIncident;
use App\Modules\Assets\Infrastructure\AssetPlacement;
use App\Modules\Assets\Infrastructure\BusinessAsset;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Partnership\Infrastructure\PartnershipContract;
use App\Shared\Application\Operations;

final class AssetWorkflow
{
    public function __construct(private Access $access, private Operations $ops) {}

    private function manage(User $u, string $org): void
    {
        abort_unless(in_array($this->access->role($u, $org)?->value, ['owner', 'admin', 'partner_lead'], true), 403);
    }

    private function recipient(User $u, AssetPlacement $p): void
    {
        $this->manage($u, $p->recipient_organization_id);
    }

    private function placement(BusinessAsset $a): ?AssetPlacement
    {
        return AssetPlacement::where('active_asset_id', $a->id)->first();
    }

    private function view(User $u, BusinessAsset $a): void
    {
        if (in_array($this->access->role($u, $a->organization_id)?->value, ['owner', 'admin', 'partner_lead'], true)) {
            return;
        }$p = $this->placement($a);
        abort_unless($p, 403);
        $this->access->store($u, $p->store_id, ['owner', 'admin', 'partner_lead', 'store_manager', 'warehouse']);
    }

    private function audit(User $u, BusinessAsset $a, string $event, array $data, ?AssetPlacement $p = null): void
    {
        foreach (array_unique(array_filter([$a->organization_id, $p?->recipient_organization_id])) as $org) {
            Audit::record($u->id, $org, 'asset.'.$event, $a->id, null, $data);
        }
    }

    public function index(User $u, string $org): array
    {
        $this->manage($u, $org);
        $q = BusinessAsset::where('organization_id', $org)->orWhereIn('id', AssetPlacement::where('recipient_organization_id', $org)->whereNotNull('active_asset_id')->select('business_asset_id'));

        return [...$q->orderBy('id')->paginate(50)->through(fn ($a) => $a->organization_id === $org ? $a->toArray() : $a->only(['id', 'organization_id', 'number', 'kind', 'brand', 'serial_number', 'status', 'version', 'store_id', 'condition_note']))->toArray(), 'organization_id' => $org];
    }

    public function context(User $u, string $id): array
    {
        $a = BusinessAsset::findOrFail($id);
        $this->view($u, $a);
        $owner = in_array($this->access->role($u, $a->organization_id)?->value, ['owner', 'admin', 'partner_lead'], true);
        $p = $this->placement($a);
        $placements = AssetPlacement::where('business_asset_id', $id);
        $incidents = AssetIncident::where('business_asset_id', $id);
        if (! $owner) {
            $orgs = $this->access->stores($u)->pluck('id');
            $placements->whereIn('store_id', $orgs);
            $incidents->whereIn('store_id', $orgs);
        }

        return ['placement_options' => $owner ? $this->options($a) : ['stores' => [], 'contracts' => []], 'asset' => $owner ? $a->toArray() : $a->only(['id', 'organization_id', 'number', 'kind', 'brand', 'serial_number', 'status', 'version', 'store_id', 'condition_note']), 'is_owner' => $owner, 'can_confirm' => $p && in_array($this->access->role($u, $p->recipient_organization_id)?->value, ['owner', 'admin', 'partner_lead'], true), 'active_placement' => $p, 'placements' => $placements->orderByDesc('id')->paginate(50)->toArray(), 'incidents' => $incidents->orderByDesc('id')->paginate(50, ['*'], 'incidents_page')->toArray()];
    }

    private function options(BusinessAsset $a): array
    {
        $contracts = PartnershipContract::where('organization_id', $a->organization_id)->where('status', 'accepted')->get(['id', 'partner_id', 'title', 'starts_on', 'ends_on']);
        $orgs = [$a->organization_id, ...$contracts->pluck('partner_id')];
        $stores = Store::whereIn('organization_id', $orgs)->where('active', true)->orderBy('name')->get(['id', 'organization_id', 'name']);

        return ['stores' => $stores->toArray(), 'contracts' => $contracts->toArray()];
    }

    public function create(User $u, string $org, array $v): array
    {
        $this->manage($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'asset.create:'.$org, $v, function () use ($u, $org, $v) {
            $this->manage($u, $org);
            Organization::whereKey($org)->lockForUpdate()->firstOrFail();
            abort_if(BusinessAsset::where('organization_id', $org)->where('number', $v['number'])->exists(), 409, 'Nomor aset sudah digunakan.');
            $a = BusinessAsset::create(['organization_id' => $org, 'status' => 'available', 'version' => 1, 'created_by' => $u->id, ...array_intersect_key($v, array_flip(['number', 'kind', 'brand', 'serial_number', 'condition_note', 'ownership_reference']))]);
            $this->audit($u, $a, 'created', $a->toArray());

            return $a->toArray();
        });
    }

    public function propose(User $u, string $id, array $v): array
    {
        $a = BusinessAsset::findOrFail($id);
        $this->manage($u, $a->organization_id);

        return $this->ops->run($a->organization_id, $u->id, $v['operation_id'], 'asset.place:'.$id, $v, function () use ($u, $id, $v) {
            $a = BusinessAsset::lockForUpdate()->findOrFail($id);
            $this->manage($u, $a->organization_id);
            abort_unless($a->version === (int) $v['version'] && $a->status === 'available' && ! $this->placement($a), 409, 'Aset tidak tersedia atau versi berubah.');
            $store = Store::where('active', true)->findOrFail($v['store_id']);
            abort_unless(Organization::whereKey($store->organization_id)->where('active', true)->exists(), 409);
            if ($store->organization_id !== $a->organization_id) {
                abort_unless(isset($v['contract_id']), 422, 'Kontrak diterima wajib untuk penempatan lintas organisasi.');
                $contract = PartnershipContract::where('organization_id', $a->organization_id)->where('partner_id', $store->organization_id)->where('status', 'accepted')->findOrFail($v['contract_id']);
                abort_unless($v['handed_over_on'] >= $contract->starts_on->format('Y-m-d') && $v['handed_over_on'] <= $contract->ends_on->format('Y-m-d'), 422, 'Tanggal serah terima di luar kontrak.');
            }
            $p = AssetPlacement::create(['business_asset_id' => $id, 'active_asset_id' => $id, 'owner_organization_id' => $a->organization_id, 'recipient_organization_id' => $store->organization_id, 'status' => 'pending', 'version' => 1, 'proposed_by' => $u->id, 'store_name' => $store->name, ...array_intersect_key($v, array_flip(['store_id', 'contract_id', 'responsible_name', 'handed_over_on', 'condition_note', 'agreement_reference', 'evidence_reference']))]);
            $a->fill(['status' => 'reserved', 'version' => $a->version + 1])->save();
            $this->audit($u, $a, 'placement_proposed', $p->toArray(), $p);

            return ['asset' => $a->toArray(), 'placement' => $p->toArray()];
        });
    }

    public function decide(User $u, string $id, string $placement, string $action, array $v): array
    {
        $a = BusinessAsset::findOrFail($id);
        $initial = AssetPlacement::where('business_asset_id', $id)->findOrFail($placement);
        in_array($action, ['withdraw', 'confirm_return'], true) ? $this->manage($u, $a->organization_id) : $this->recipient($u, $initial);

        return $this->ops->run($a->organization_id, $u->id, $v['operation_id'], 'asset.'.$action.':'.$placement, $v, function () use ($u, $id, $placement, $action, $v) {
            $a = BusinessAsset::lockForUpdate()->findOrFail($id);
            $p = AssetPlacement::where('business_asset_id', $id)->lockForUpdate()->findOrFail($placement);
            abort_unless($a->version === (int) $v['version'] && $p->active_asset_id === $id, 409, 'Versi/status penempatan berubah.');
            in_array($action, ['withdraw', 'confirm_return'], true) ? $this->manage($u, $a->organization_id) : $this->recipient($u, $p);
            if (in_array($action, ['accept', 'reject', 'withdraw'], true)) {
                abort_unless($p->status === 'pending', 409);
                if ($action === 'accept') {
                    abort_unless(($v['consent'] ?? false) === true, 422);
                    $this->access->operatingStore($u, $p->store_id, ['owner', 'admin', 'partner_lead']);
                    $p->status = 'active';
                    $p->confirmed_by = $u->id;
                    $p->confirmed_at = now();
                    $a->status = 'in_use';
                    $a->store_id = $p->store_id;
                } else {
                    $p->status = $action === 'reject' ? 'rejected' : 'withdrawn';
                    $p->active_asset_id = null;
                    $a->status = 'available';
                }
            } elseif ($action === 'request_return') {
                abort_unless(in_array($p->status, ['active', 'maintenance'], true), 409);
                $p->status = 'return_pending';
                abort_unless($v['returned_on'] >= $p->handed_over_on->format('Y-m-d'), 422, 'Pengembalian mendahului serah terima.');
                $p->returned_on = $v['returned_on'];
                $p->return_condition = $v['return_condition'];
                $p->return_reference = $v['return_reference'];
                $p->return_requested_by = $u->id;
            } else {
                abort_unless($p->status === 'return_pending' && ($v['consent'] ?? false) === true, 409);
                abort_unless($v['disposition'] === 'maintenance' || ! AssetIncident::where('business_asset_id', $id)->where('status', 'open')->exists(), 409, 'Insiden terbuka menahan ketersediaan.');
                $p->status = 'returned';
                $p->active_asset_id = null;
                $p->return_confirmed_by = $u->id;
                $p->return_confirmed_at = now();
                $a->store_id = null;
                $a->status = $v['disposition'];
                $a->condition_note = $v['inspection_note'];
            }
            $a->version++;
            $a->save();
            $p->version++;
            $p->decision_note = $v['reason'];
            $p->save();
            $this->audit($u, $a, $action, $p->toArray(), $p);

            return ['asset' => $a->toArray(), 'placement' => $p->toArray()];
        });
    }

    public function incident(User $u, string $id, array $v): array
    {
        $a = BusinessAsset::findOrFail($id);
        $this->view($u, $a);

        return $this->ops->run($a->organization_id, $u->id, $v['operation_id'], 'asset.incident:'.$id, $v, function () use ($u, $id, $v) {
            $a = BusinessAsset::lockForUpdate()->findOrFail($id);
            $this->view($u, $a);
            abort_unless($a->version === (int) $v['version'], 409);
            $p = $this->placement($a);
            abort_if($p && $p->status === 'pending', 409, 'Konfirmasi serah terima dahulu.');
            $i = AssetIncident::create(['business_asset_id' => $id, 'asset_placement_id' => $p?->id, 'owner_organization_id' => $a->organization_id, 'recipient_organization_id' => $p?->recipient_organization_id, 'store_id' => $p?->store_id, 'status' => 'open', 'version' => 1, 'reported_by' => $u->id, ...array_intersect_key($v, array_flip(['description', 'evidence_reference', 'reported_on']))]);
            $a->fill(['status' => 'maintenance', 'version' => $a->version + 1])->save();
            $this->audit($u, $a, 'incident_reported', $i->toArray(), $p);

            return ['asset' => $a->toArray(), 'incident' => $i->toArray()];
        });
    }

    public function resolve(User $u, string $id, string $incident, array $v): array
    {
        $a = BusinessAsset::findOrFail($id);
        $this->manage($u, $a->organization_id);

        return $this->ops->run($a->organization_id, $u->id, $v['operation_id'], 'asset.resolve:'.$incident, $v, function () use ($u, $id, $incident, $v) {
            $a = BusinessAsset::lockForUpdate()->findOrFail($id);
            $this->manage($u, $a->organization_id);
            $i = AssetIncident::where('business_asset_id', $id)->lockForUpdate()->findOrFail($incident);
            abort_unless($a->version === (int) $v['version'] && $i->status === 'open' && $v['completed_on'] >= $i->reported_on->format('Y-m-d'), 409);
            $i->fill(['status' => 'resolved', 'version' => $i->version + 1, 'resolved_by' => $u->id, ...array_intersect_key($v, array_flip(['resolution', 'cost_basis_reference', 'owner_cost', 'recipient_cost', 'completed_on']))])->save();
            $p = $this->placement($a);
            if (! AssetIncident::where('business_asset_id', $id)->where('status', 'open')->exists()) {
                $a->status = $p ? ($p->status === 'pending' ? 'reserved' : 'in_use') : 'available';
            }$a->version++;
            $a->save();
            $this->audit($u, $a, 'incident_resolved', $i->toArray(), $p);

            return ['asset' => $a->toArray(), 'incident' => $i->toArray()];
        });
    }
}
