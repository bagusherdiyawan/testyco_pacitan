<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderChangeWebTest extends TestCase
{
    private const LINE = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private function sessionData(): array
    {
        return ['api_token' => 'fixture-private', 'api_user' => ['id' => 1, 'name' => 'Owner'], 'api_expires_at' => time() + 3600];
    }

    private function body(): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => 3, 'reason' => 'Kekurangan aktual', 'lines' => [['order_line_id' => self::LINE, 'quantity_packs' => 12]]];
    }

    private function context(bool $pending = false): array
    {
        $line = ['id' => self::LINE, 'sku' => 'OLD8', 'local_sku' => 'LOCAL8', 'quantity_packs' => 20, 'pack_size' => 8, 'unit_price' => 18000];
        $change = ['id' => 'CH1', 'supply_order_id' => 'O1', 'status' => 'pending', 'base_version' => 3, 'requested_by' => 1, 'reason' => '<script>shortage</script>', 'before' => ['lines' => [$line], 'total_amount' => 360000], 'after' => ['lines' => [[...$line, 'quantity_packs' => 12]], 'total_amount' => 216000]];

        return ['order' => ['id' => 'O1', 'version' => $pending ? 4 : 3, 'status' => 'processing', 'lines' => [$line]], 'change_pending' => $pending, 'actions' => ['propose' => ! $pending, 'approve' => $pending, 'reject' => $pending, 'withdraw' => false], 'data' => $pending ? [$change] : [], 'total' => $pending ? 1 : 0, 'per_page' => 50, 'current_page' => 1, 'next_page_url' => 'https://untrusted.test/collect'];
    }

    private function ack(string $status = 'pending'): array
    {
        return ['data' => ['order' => ['id' => 'O1', 'version' => $status === 'pending' ? 4 : 5], 'change' => ['id' => 'CH1', 'supply_order_id' => 'O1', 'status' => $status]]];
    }

    public function test_session_denial_and_foreign_order_payloads_are_protected(): void
    {
        $this->get('/app/orders/O1/changes')->assertRedirect('/login');
        $wrong = $this->context();
        $wrong['order']['id'] = 'OTHER';
        Http::fake(['*' => Http::sequence()->push([], 403)->push($wrong)]);
        $this->withSession($this->sessionData())->get('/app/orders/O1/changes')->assertForbidden();
        $this->get('/app/orders/O1/changes')->assertStatus(503)->assertDontSee('Kirim usulan untuk persetujuan ulang');
    }

    public function test_scoped_capabilities_render_review_snapshots_and_escape_reason_without_token_or_external_links(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->context())->push($this->context(true))]);
        $this->withSession($this->sessionData())->get('/app/orders/O1/changes')->assertOk()->assertSee('Kirim usulan untuk persetujuan ulang')->assertSee('value="20"', false)->assertDontSee('fixture-private');
        $r = $this->get('/app/orders/O1/changes')->assertOk()->assertSee('20 → 12 kemasan')->assertSee('360.000')->assertSee('216.000')->assertSee('Setujui jumlah revisi')->assertSee('Tolak usulan')->assertDontSee('Kirim usulan untuk persetujuan ulang')->assertDontSee('<script>shortage</script>', false)->assertDontSee('untrusted.test');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        Http::assertSent(fn ($r) => $r->method() === 'GET' && parse_url($r->url(), PHP_URL_PATH) === '/api/v1/orders/O1/changes' && $r->data() == ['page' => 1]);
    }

    public function test_proposal_sends_exact_validated_body_then_clears_pending_on_matching_ack(): void
    {
        Http::fake(['*' => Http::response($this->ack(), 201)]);
        $body = $this->body();
        $this->withSession($this->sessionData())->post('/app/orders/O1/changes', [...$body, 'total_amount' => 1, '_token' => 'browser-csrf'])->assertRedirect('/app/orders/O1/changes')->assertSessionMissing('order_changes_pending.O1');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->data() === $body && $r->hasHeader('Authorization', 'Bearer fixture-private'));
    }

    public function test_unknown_outcome_preserves_exact_request_through_later_denial_and_retries_only_from_server_session(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 503)->push([], 403)->push($this->ack(), 201)]);
        $body = $this->body();
        $this->withSession($this->sessionData())->post('/app/orders/O1/changes', $body)->assertRedirect('/app/orders/O1/changes')->assertSessionHas('order_changes_pending.O1.uncertain', true);
        $this->get('/app/orders/O1/changes')->assertOk()->assertSee('Ulangi request yang sama')->assertSee($body['operation_id'])->assertDontSee('fixture-private')->assertDontSee('Kirim usulan untuk persetujuan ulang');
        $this->post('/app/orders/O1/changes', [...$body, 'operation_id' => (string) Str::uuid(), 'version' => 99])->assertConflict();
        $this->post('/app/orders/O1/changes/retry', ['version' => 99, 'reason' => 'attacker'])->assertRedirect('/app/orders/O1/changes')->assertSessionHas('order_changes_pending.O1.body', $body);
        $this->post('/app/orders/O1/changes/retry', ['version' => 99])->assertRedirect('/app/orders/O1/changes')->assertSessionMissing('order_changes_pending.O1');
        Http::assertSentCount(3);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('POST', $request->method());
            $this->assertSame($body, $request->data());
            $this->assertSame('/api/v1/orders/O1/changes', parse_url($request->url(), PHP_URL_PATH));
        }
    }

    public function test_malformed_decision_ack_is_unknown_and_keeps_original_id_version_and_uuid(): void
    {
        Http::fake(['*' => Http::sequence()->push(['data' => ['order' => ['id' => 'OTHER']]])->push($this->ack('approved'))]);
        $body = ['operation_id' => (string) Str::uuid(), 'version' => 4];
        $this->withSession($this->sessionData())->post('/app/orders/O1/changes/CH1/approve', [...$body, 'quantity_packs' => 1])->assertRedirect('/app/orders/O1/changes')->assertSessionHas('order_changes_pending.O1.uncertain', true);
        $this->post('/app/orders/O1/changes/retry', ['version' => 88])->assertRedirect('/app/orders/O1/changes')->assertSessionMissing('order_changes_pending.O1');
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame($body, $request->data());
            $this->assertSame('/api/v1/orders/O1/changes/CH1/approve', parse_url($request->url(), PHP_URL_PATH));
        }
    }

    public function test_known_validation_clears_new_pending_but_401_discards_session_instead_of_restoring_payload(): void
    {
        Http::fake(['*' => Http::sequence()->push(['errors' => ['version' => ['Stale']]], 422)->push([], 503)->push([], 401)]);
        $body = $this->body();
        $this->withSession($this->sessionData())->from('/app/orders/O1/changes')->post('/app/orders/O1/changes', $body)->assertSessionHasErrors('version')->assertSessionMissing('order_changes_pending.O1');
        $this->post('/app/orders/O1/changes', $body)->assertRedirect('/app/orders/O1/changes');
        $this->post('/app/orders/O1/changes/retry')->assertRedirect('/login')->assertSessionMissing('api_token')->assertSessionMissing('order_changes_pending');
    }

    public function test_account_switch_clears_previous_pending_and_reject_requires_reason_before_api(): void
    {
        Http::fake(['*/auth/login' => Http::response(['token' => 'other-token', 'user' => ['id' => 2, 'name' => 'Other'], 'expires_in' => 28800])]);
        $this->withSession([...$this->sessionData(), 'order_changes_pending' => ['O1' => ['body' => $this->body()]]])->post('/login', ['email' => 'other@example.test', 'password' => 'FixturePassword123'])->assertRedirect('/app')->assertSessionMissing('order_changes_pending');
        $this->postJson('/app/orders/O1/changes/CH1/reject', ['operation_id' => (string) Str::uuid(), 'version' => 4])->assertUnprocessable();
        Http::assertSentCount(1);
    }
}
