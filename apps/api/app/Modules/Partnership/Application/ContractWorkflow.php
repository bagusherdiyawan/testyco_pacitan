<?php

namespace App\Modules\Partnership\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Partnership\Infrastructure\PartnershipContract;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\Storage;

final class ContractWorkflow
{
    public function __construct(private Access $access, private Operations $operations) {}

    private function owner(User $u, string $org): void
    {
        abort_unless(Organization::whereKey($org)->where('type', 'center')->exists() && in_array($this->access->role($u, $org)?->value, ['owner', 'admin'], true), 403);
    }

    private function lead(User $u, string $org): void
    {
        abort_unless($this->access->role($u, $org)?->value === 'partner_lead', 403);
    }

    private function audit(User $u, PartnershipContract $c, string $action): void
    {
        foreach ($action === 'drafted' ? [$c->organization_id] : [$c->organization_id, $c->partner_id] as $org) {
            Audit::record($u->id, $org, 'contract.'.$action, $c->id, null, $c->toArray());
        }
    }

    public function index(User $u, string $org): array
    {
        $role = $this->access->role($u, $org)?->value;
        abort_unless(in_array($role, ['owner', 'admin', 'partner_lead'], true), 403);
        $center = Organization::whereKey($org)->where('type', 'center')->exists();
        $q = PartnershipContract::query();
        $center ? $q->where('organization_id', $org) : $q->where('partner_id', $org)->where('status', '!=', 'draft');

        return [...$q->orderByDesc('id')->paginate(50)->toArray(), 'organization_id' => $org, 'can_create' => $center && in_array($role, ['owner', 'admin'], true), 'can_decide' => ! $center && $role === 'partner_lead'];
    }

    public function create(User $u, string $org, array $v): array
    {
        $this->owner($u, $org);

        return $this->operations->run($org, $u->id, $v['operation_id'], 'contract.create:'.$org, $v, function () use ($u, $org, $v) {
            $this->owner($u, $org);
            abort_unless(Organization::whereKey($v['partner_id'])->where('type', 'partner')->where('active', true)->exists(), 422, 'Mitra aktif tidak ditemukan.');
            $attachment = [];
            if (isset($v['attachment_base64'])) {
                $bytes = base64_decode($v['attachment_base64'], true);
                abort_unless(is_string($bytes) && strlen($bytes) <= 5 * 1024 * 1024 && str_starts_with($bytes, '%PDF-') && (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === 'application/pdf', 422, 'Lampiran wajib PDF maksimal5MiB.');
                $hash = hash('sha256', $bytes);
                $path = 'contracts/'.$org.'/'.$hash.'.pdf';
                abort_unless(Storage::disk('local')->put($path, $bytes), 503, 'Penyimpanan lampiran gagal.');
                $attachment = ['attachment_path' => $path, 'attachment_sha256' => $hash];
            }
            $c = PartnershipContract::create([...$attachment, 'version' => 1, 'status' => 'draft', 'organization_id' => $org, 'created_by' => $u->id, ...array_intersect_key($v, array_flip(['partner_id', 'title', 'mode', 'starts_on', 'ends_on', 'terms', 'brand_terms', 'data_scope', 'facilities']))]);
            $this->audit($u, $c, 'drafted');

            return $c->toArray();
        });
    }

    public function attachment(User $u, string $org, string $id): array
    {
        $c = PartnershipContract::findOrFail($id);
        if ($c->organization_id === $org) {
            $this->owner($u, $org);
        } else {
            abort_unless($c->partner_id === $org && $c->status !== 'draft', 404);
            $this->lead($u, $org);
        }
        abort_unless($c->attachment_path && Storage::disk('local')->exists($c->attachment_path), 404);

        return ['bytes' => Storage::disk('local')->get($c->attachment_path)];
    }

    public function decide(User $u, string $org, string $id, string $action, array $v): array
    {
        $initial = PartnershipContract::findOrFail($id);
        abort_unless(in_array($org, [$initial->organization_id, $initial->partner_id], true), 404);
        in_array($action, ['publish', 'withdraw'], true) ? $this->owner($u, $org) : $this->lead($u, $org);

        return $this->operations->run($org, $u->id, $v['operation_id'], 'contract.'.$action.':'.$id, $v, function () use ($u, $org, $id, $action, $v) {
            $c = PartnershipContract::lockForUpdate()->findOrFail($id);
            abort_unless($c->version === (int) $v['version'], 409, 'Versi kontrak berubah.');
            if (in_array($action, ['publish', 'withdraw'], true)) {
                abort_unless($c->organization_id === $org, 403);
                $this->owner($u, $org);
                abort_unless($action === 'publish' ? $c->status === 'draft' : in_array($c->status, ['draft', 'published'], true), 409);
                if ($action === 'publish') {
                    abort_unless(Organization::whereKey($c->partner_id)->where('active', true)->exists(), 409, 'Mitra nonaktif.');
                }$c->status = $action === 'publish' ? 'published' : 'withdrawn';
            } else {
                abort_unless($c->partner_id === $org, 403);
                $this->lead($u, $org);
                abort_unless($c->status === 'published', 409, 'Kontrak belum diterbitkan atau sudah diputuskan.');
                if ($action === 'accept') {
                    abort_unless(Organization::whereKey($c->organization_id)->where('active', true)->exists(), 409, 'Pusat nonaktif.');
                    abort_unless(($v['consent'] ?? false) === true, 422, 'Persetujuan eksplisit wajib.');
                    $c->consent_name = $v['consent_name'];
                }$c->status = $action === 'accept' ? 'accepted' : 'rejected';
            }
            $c->version++;
            $c->decided_by = $u->id;
            $c->decided_at = now();
            $c->decision_note = $v['reason'] ?? null;
            $c->terms_sha256 = hash('sha256', json_encode($c->only(['organization_id', 'partner_id', 'title', 'mode', 'starts_on', 'ends_on', 'terms', 'brand_terms', 'data_scope', 'facilities', 'attachment_sha256']), JSON_THROW_ON_ERROR));
            $c->save();
            $this->audit($u, $c, $action);

            return $c->toArray();
        });
    }
}
