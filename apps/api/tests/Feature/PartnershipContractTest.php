<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnershipContractTest extends TestCase
{
    use RefreshDatabase;

    private Organization $center;

    private Organization $partner;

    private Organization $other;

    private User $owner;

    private User $lead;

    private User $outside;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->center = Organization::create(['name' => 'Center', 'type' => 'center']);
        $this->partner = Organization::create(['name' => 'Partner', 'type' => 'partner']);
        $this->other = Organization::create(['name' => 'Other', 'type' => 'partner']);
        $this->owner = $this->user($this->center, 'owner');
        $this->lead = $this->user($this->partner, 'partner_lead');
        $this->outside = $this->user($this->other, 'partner_lead');
        $this->cashier = $this->user($this->partner, 'cashier');
    }

    private function user(Organization $o, string $role): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $o->id, 'user_id' => $u->id, 'role' => $role]);

        return $u;
    }

    private function act(User $u): self
    {
        Sanctum::actingAs($u);

        return $this;
    }

    private function path(?Organization $o = null): string
    {
        return '/api/v1/organizations/'.($o ?? $this->center)->id.'/contracts';
    }

    private function body(): array
    {
        return ['operation_id' => (string) Str::uuid(), 'partner_id' => $this->partner->id, 'title' => 'Agreement <script>', 'mode' => 'consignment', 'starts_on' => '2026-11-03', 'ends_on' => '2030-12-31', 'terms' => 'Terms v1', 'brand_terms' => 'Agreed brand use', 'data_scope' => 'Onboarding only, no POS sales access', 'facilities' => 'No automatic asset charges'];
    }

    public function test_draft_private_publish_accept_explicit_consent_and_replay_freeze_terms(): void
    {
        $body = $this->body();
        $r = $this->act($this->owner)->postJson($this->path(), $body)->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.status', 'draft')->json('data');
        $this->postJson($this->path(), $body)->assertCreated();
        $this->act($this->lead)->getJson($this->path($this->partner))->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, DB::table('audit_entries')->where('organization_id', $this->partner->id)->where('action', 'contract.drafted')->count());
        $publish = ['operation_id' => (string) Str::uuid(), 'version' => 1];
        $url = $this->path().'/'.$r['id'].'/publish';
        $this->act($this->owner)->postJson($url, $publish)->assertOk()->assertJsonPath('data.status', 'published');
        $this->postJson($url, $publish)->assertOk();
        $this->act($this->lead)->getJson($this->path($this->partner))->assertJsonCount(1, 'data')->assertJsonPath('can_decide', true);
        $accept = $this->path($this->partner).'/'.$r['id'].'/accept';
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 2, 'consent_name' => 'Partner Director'];
        $this->postJson($accept, $v)->assertUnprocessable();
        $v['consent'] = true;
        $accepted = $this->postJson($accept, $v)->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.status', 'accepted')->json('data');
        $this->assertSame(64, strlen($accepted['terms_sha256']));
        $this->postJson($accept, $v)->assertOk();
        $this->assertSame('Terms v1', $accepted['terms']);
        $this->assertSame('consignment', $accepted['mode']);
        $this->assertDatabaseCount('stock_entries', 0);
        $this->act($this->owner)->postJson($this->path().'/'.$r['id'].'/withdraw', ['operation_id' => (string) Str::uuid(), 'version' => 3, 'reason' => 'Cannot erase accepted consent'])->assertConflict();
    }

    public function test_wrong_roles_tenants_stale_versions_reject_and_withdraw(): void
    {
        foreach ([$this->lead, $this->cashier, $this->outside] as $u) {
            $this->act($u)->postJson($this->path(), $this->body())->assertForbidden();
        }
        $r = $this->act($this->owner)->postJson($this->path(), $this->body())->assertCreated()->json('data');
        $this->postJson($this->path().'/'.$r['id'].'/publish', ['operation_id' => (string) Str::uuid(), 'version' => 2])->assertConflict();
        $this->postJson($this->path().'/'.$r['id'].'/publish', ['operation_id' => (string) Str::uuid(), 'version' => 1])->assertOk();
        $this->act($this->outside)->postJson($this->path($this->other).'/'.$r['id'].'/accept', ['operation_id' => (string) Str::uuid(), 'version' => 2, 'consent' => true, 'consent_name' => 'Other'])->assertNotFound();
        $this->act($this->cashier)->getJson($this->path($this->partner))->assertForbidden();
        $this->act($this->lead)->postJson($this->path($this->partner).'/'.$r['id'].'/reject', ['operation_id' => (string) Str::uuid(), 'version' => 2, 'reason' => 'Request revised terms'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->act($this->owner)->postJson($this->path().'/'.$r['id'].'/publish', ['operation_id' => (string) Str::uuid(), 'version' => 3])->assertConflict();
        $migration = require database_path('migrations/2026_11_03_000012_create_partnership_contracts.php');
        try {
            $migration->down();
            $this->fail('Must preserve history');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ditolak', $e->getMessage());
        }
    }

    public function test_pdf_attachment_is_private_until_publication_and_hash_is_bound_to_consent(): void
    {
        Storage::fake('local');
        $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF";
        $r = $this->act($this->owner)->postJson($this->path(), [...$this->body(), 'attachment_base64' => base64_encode($pdf)])->assertCreated()->assertJsonMissingPath('data.attachment_path')->assertJsonPath('data.has_attachment', true)->json('data');
        $this->assertSame(hash('sha256', $pdf), $r['attachment_sha256']);
        $this->get($this->path().'/'.$r['id'].'/attachment')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Disposition', 'attachment; filename="contract.pdf"');
        $this->act($this->lead)->get($this->path($this->partner).'/'.$r['id'].'/attachment')->assertNotFound();
        $this->act($this->owner)->postJson($this->path().'/'.$r['id'].'/publish', ['operation_id' => (string) Str::uuid(), 'version' => 1])->assertOk();
        $this->act($this->lead)->get($this->path($this->partner).'/'.$r['id'].'/attachment')->assertOk();
        $this->act($this->outside)->get($this->path($this->other).'/'.$r['id'].'/attachment')->assertNotFound();
        $this->act($this->owner)->postJson($this->path(), [...$this->body(), 'attachment_base64' => base64_encode('<script>notpdf</script>')])->assertUnprocessable();
    }
}
