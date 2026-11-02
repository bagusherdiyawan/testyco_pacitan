<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShipmentDiscrepancyWebTest extends TestCase
{
    private string $base = '/app/orders/O1/shipments/H1/discrepancy';

    private string $slot = 'shipment_discrepancy_pending.H1';

    private function auth(): array
    {
        return ['api_token' => 'fixture-private', 'api_expires_at' => time() + 1000, 'api_user' => ['id' => 1, 'name' => 'Staff']];
    }

    private function fake(mixed $callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function snapshot(): array
    {
        return ['received_at' => '2026-11-02T01:00:00+00:00', 'received_by' => 2, 'receipt_note' => 'Physical receipt', 'problem_packs' => 3, 'lines' => [['shipment_line_id' => 'L1', 'order_line_id' => 'OL1', 'source_batch_id' => 'B1', 'sku' => 'OLD8', 'local_sku' => 'LOCAL8', 'name' => 'Old name', 'local_name' => 'Local old', 'batch_code' => 'LOT', 'expires_on' => '2030-01-01', 'pack_size' => 8, 'sent_packs' => 20, 'good_packs' => 17, 'damaged_packs' => 2, 'missing_packs' => 1, 'damaged_pieces' => 16, 'missing_pieces' => 8, 'condition_note' => '<script>bad</script>']]];
    }

    private function case(int $version = 1): array
    {
        return ['id' => 'C1', 'supply_order_id' => 'O1', 'shipment_id' => 'H1', 'version' => $version, 'status' => 'reported', 'reported_by' => 2, 'reason' => 'Physical discrepancy', 'reference' => 'Log <img src=x>', 'snapshot' => $this->snapshot()];
    }

    private function plan(string $status = 'pending'): array
    {
        return ['id' => 'R1', 'shipment_discrepancy_id' => 'C1', 'proposed_by' => 9, 'type' => 'credit', 'status' => $status, 'proposal' => 'Proposed plan', 'reference' => null, 'decided_by' => null, 'decision_note' => null, 'decided_at' => null];
    }

    private function context(bool $exists = false, bool $supplier = false, bool $pending = false): array
    {
        return ['order' => ['id' => 'O1', 'version' => 5, 'status' => 'partially_received', 'kind' => 'wholesale', 'destination_name' => 'A1'],
            'shipment' => ['id' => 'H1', 'supply_order_id' => 'O1', 'received_at' => '2026-11-02T01:00:00Z'], 'case' => $exists ? $this->case($pending ? 2 : 1) : null, 'snapshot' => $this->snapshot(),
            'data' => [], 'total' => 0, 'current_page' => 1, 'per_page' => 50, 'last_page' => 1,
            'resolutions' => ['data' => [], 'total' => 0, 'current_page' => 1, 'per_page' => 50, 'last_page' => 1],
            'pending_resolution' => $pending ? $this->plan() : null,
            'actions' => ['report' => ! $exists && ! $supplier, 'note' => $exists, 'propose_resolution' => $exists && $supplier && ! $pending, 'confirm_resolution' => $pending && ! $supplier, 'reject_resolution' => $pending && ! $supplier, 'withdraw_resolution' => $pending && $supplier],
            'access_side' => $supplier ? 'supplier' : 'recipient'];
    }

    private function ack(int $version = 1, ?string $resolution = null, ?string $message = null): array
    {
        return ['data' => ['order_id' => 'O1', 'shipment_id' => 'H1', 'case' => $this->case($version),
            'resolution' => $resolution ? $this->plan($resolution) : null, 'note' => $message ? ['id' => 'N1', 'shipment_discrepancy_id' => 'C1', 'case_version' => $version, 'message' => $message] : null]];
    }

    public function test_session_scope_snapshot_escaping_and_server_capability_control(): void
    {
        $this->get($this->base)->assertRedirect('/login');
        $this->fake(['*' => Http::response($this->context())]);
        $this->withSession($this->auth())->get($this->base)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Catat kasus selisih')->assertSee('Rusak 2 (16 biji)')->assertSee('Kurang 1 (8 biji)')->assertDontSee('<script>', false)->assertDontSee('fixture-private');
        $this->fake(['*' => Http::response($this->context(true, true))]);
        $this->get($this->base)->assertOk()->assertSee('Ajukan rencana penyelesaian')->assertDontSee('Konfirmasi rencana')->assertDontSee('Catat kasus selisih');
        $this->fake(['*' => Http::response($this->context(true, false, true))]);
        $this->get($this->base)->assertOk()->assertSee('Konfirmasi rencana')->assertSee('Tolak rencana')->assertDontSee('Tarik usulan pusat');
    }

    public function test_known_ack_report_notes_and_partner_confirmation_send_only_validated_body(): void
    {
        $this->fake(fn ($r) => $r->method() === 'GET' ? Http::response($this->context()) : Http::response($this->ack()));
        $body = ['operation_id' => (string) Str::uuid(), 'order_version' => 5, 'reason' => 'Physical discrepancy'];
        $this->withSession($this->auth())->post($this->base, [...$body, 'quantity_packs' => 999, 'amount' => 100000])->assertRedirect($this->base)->assertSessionMissing($this->slot);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->data() === $body);
        $this->fake(fn ($r) => $r->method() === 'GET' ? Http::response($this->context(true)) : Http::response($this->ack(2, message: 'Follow up')));
        $this->post($this->base.'/notes', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'message' => 'Follow up'])->assertRedirect($this->base)->assertSessionMissing($this->slot);
        $this->fake(fn ($r) => $r->method() === 'GET' ? Http::response($this->context(true, false, true)) : Http::response($this->ack(3, 'confirmed')));
        $this->post($this->base.'/resolutions/R1/confirm', ['operation_id' => (string) Str::uuid(), 'version' => 2, 'reason' => 'Reviewed plan'])->assertRedirect($this->base)->assertSessionMissing($this->slot);
    }

    public function test_unknown_report_preserves_request_through_denial_parent_alias_and_retry_edits(): void
    {
        $calls = 0;
        $this->fake(function ($r) use (&$calls) {
            if ($r->method() === 'GET') {
                return Http::response($this->context());
            }
            $calls++;

            return $calls === 1 ? Http::response([], 503) : ($calls === 2 ? Http::response([], 403) : Http::response($this->ack()));
        });
        $body = ['operation_id' => (string) Str::uuid(), 'order_version' => 5, 'reason' => 'Physical discrepancy'];
        $this->withSession($this->auth())->post($this->base, $body)->assertRedirect($this->base)->assertSessionHas($this->slot.'.uncertain', true);
        $this->get($this->base)->assertOk()->assertSee('Ulangi request yang sama')->assertDontSee('Catat kasus selisih')->assertDontSee('fixture-private');
        $this->post($this->base, $body)->assertConflict();
        $this->get('/app/orders/OTHER/shipments/H1/discrepancy')->assertRedirect($this->base);
        $this->post($this->base.'/retry', ['reason' => 'Edited', 'operation_id' => (string) Str::uuid()])->assertRedirect($this->base)->assertSessionHas($this->slot.'.body', $body);
        $this->post($this->base.'/retry', ['order_version' => 99])->assertRedirect($this->base)->assertSessionMissing($this->slot);
        Http::assertSentCount(4);
        foreach (Http::recorded() as [$r]) {
            if ($r->method() === 'POST') {
                $this->assertSame($body, $r->data());
            }
        }
    }

    public function test_malformed_plan_ack_is_unknown_and_401_invalidates_session_instead_of_restoring_it(): void
    {
        $bad = $this->ack(2, 'pending');
        $bad['data']['resolution']['type'] = 'return';
        $this->fake(fn ($r) => $r->method() === 'GET' ? Http::response($this->context(true, true)) : Http::response($bad));
        $this->withSession($this->auth())->post($this->base.'/resolutions', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'type' => 'credit', 'proposal' => 'Proposed plan'])->assertRedirect($this->base)->assertSessionHas($this->slot.'.uncertain', true);
        $this->fake(['*' => Http::response([], 401)]);
        $this->post($this->base.'/retry')->assertRedirect('/login')->assertSessionMissing('api_token')->assertSessionMissing('shipment_discrepancy_pending');
    }

    public function test_stale_version_missing_reason_and_false_capability_do_not_submit_mutations(): void
    {
        $this->fake([]);
        $this->withSession($this->auth())->post($this->base.'/resolutions/R1/reject', ['operation_id' => (string) Str::uuid(), 'version' => 2, 'reason' => ''])->assertSessionHasErrors('reason');
        Http::assertNothingSent();
        $this->fake(['*' => Http::response($this->context(true))]);
        $this->post($this->base.'/notes', ['operation_id' => (string) Str::uuid(), 'version' => 99, 'message' => 'Stale'])->assertConflict()->assertSessionMissing($this->slot);
        $this->post($this->base.'/resolutions', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'type' => 'credit', 'proposal' => 'No source authority'])->assertConflict();
        Http::assertSent(fn ($r) => $r->method() === 'GET');
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_foreign_context_rows_and_denial_do_not_render_as_empty_success(): void
    {
        $contexts = [];
        $p = $this->context(true);
        $p['order']['id'] = 'OTHER';
        $contexts[] = $p;
        $p = $this->context(true);
        $p['case']['shipment_id'] = 'OTHER';
        $contexts[] = $p;
        $p = $this->context(true);
        $p['data'] = [['shipment_discrepancy_id' => 'OTHER']];
        $contexts[] = $p;
        $p = $this->context(true, false, true);
        $p['pending_resolution']['shipment_discrepancy_id'] = 'OTHER';
        $contexts[] = $p;
        foreach ($contexts as $c) {
            $this->fake(['*' => Http::response($c)]);
            $this->withSession($this->auth())->get($this->base)->assertStatus(503);
        }
        $this->fake(['*' => Http::response([], 403)]);
        $this->get($this->base)->assertForbidden();
    }

    public function test_local_independent_pagination_and_successful_login_drop_prior_account_pending(): void
    {
        $c = $this->context(true);
        $c['current_page'] = 2;
        $c['total'] = 51;
        $c['last_page'] = 2;
        $c['resolutions']['current_page'] = 2;
        $c['resolutions']['last_page'] = 2;
        $c['resolutions']['total'] = 51;
        $c['next_page_url'] = 'https://untrusted.test/steal';
        $c['resolutions']['next_page_url'] = 'https://untrusted.test/steal';
        $this->fake(['*' => Http::response($c)]);
        $this->withSession($this->auth())->get($this->base.'?page=2&resolutions_page=2')->assertOk()->assertSee('page=1')->assertSee('resolutions_page=1')->assertDontSee('untrusted.test');
        $this->fake(['*/auth/login' => Http::response(['token' => 'new-fixture', 'user' => ['id' => 9, 'name' => 'Other'], 'expires_in' => 1000])]);
        $this->withSession([$this->slot => ['uncertain' => true]])->post('/login', ['email' => 'other@example.test', 'password' => 'ExamplePassword123'])->assertRedirect('/app')->assertSessionMissing('shipment_discrepancy_pending');
    }
}
