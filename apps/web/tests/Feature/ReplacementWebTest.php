<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReplacementWebTest extends TestCase
{
    private string $base = '/app/orders/O1/shipments/H1/discrepancy/replacements';

    private string $slot = 'replacement_pending.H1';

    private string $line;

    private string $batch;

    private string $replacement;

    private string $replacementLine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->line = (string) Str::ulid();
        $this->batch = (string) Str::ulid();
        $this->replacement = (string) Str::ulid();
        $this->replacementLine = (string) Str::ulid();
    }

    private function auth(): array
    {
        return ['api_token' => 'private-fixture', 'api_expires_at' => time() + 1000, 'api_user' => ['id' => 1, 'name' => 'Staff']];
    }

    private function fake(mixed $value): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($value);
    }

    private function context(bool $supplier = true, bool $shipped = false): array
    {
        $line = ['shipment_line_id' => $this->line, 'order_line_id' => 'OL1', 'supplier_product_id' => 'P1', 'sku' => 'OLD8', 'local_sku' => 'LOCAL8', 'pack_size' => 8, 'limit_packs' => 3, 'remaining_packs' => $shipped ? 0 : 3, 'replacement_sent_packs' => $shipped ? 3 : 0, 'replacement_good_packs' => 0, 'in_transit_packs' => $shipped ? 3 : 0];

        return ['order_id' => 'O1', 'shipment_id' => 'H1', 'case_id' => 'C1', 'case_version' => $shipped ? 4 : 3, 'source_store_id' => 'S1', 'store_id' => 'S2', 'resolution' => ['id' => 'R1', 'shipment_discrepancy_id' => 'C1', 'status' => 'confirmed', 'type' => 'replacement', 'proposed_by' => 2, 'proposal' => 'Replace <script>x</script>', 'reference' => null],
            'quota' => [$line], 'batches' => $supplier ? [['id' => $this->batch, 'store_id' => 'S1', 'product_id' => 'P1', 'pack_size' => 8, 'batch_code' => 'LOT1', 'expires_on' => '2030-01-01', 'usable_pieces' => 24]] : [],
            'execution_status' => $shipped ? 'in_transit' : 'ready', 'can_dispatch' => $supplier && ! $shipped, 'data' => $shipped ? [[...$this->replacementRow(), 'can_receive' => ! $supplier]] : [], 'total' => $shipped ? 1 : 0, 'current_page' => 1, 'per_page' => 50, 'last_page' => 1];
    }

    private function replacementRow(bool $received = false): array
    {
        return ['id' => $this->replacement, 'case_id' => 'C1', 'resolution_id' => 'R1', 'source_store_id' => 'S1', 'received_at' => $received ? '2026-11-03T01:00:00Z' : null, 'dispatched_at' => '2026-11-03T00:00:00Z', 'handler' => 'Courier', 'note' => 'Physical', 'receipt_note' => null, 'lines' => [['id' => $this->replacementLine, 'replacement_shipment_id' => $this->replacement, 'original_shipment_line_id' => $this->line, 'inventory_batch_id' => $this->batch, 'sku' => 'OLD8', 'local_sku' => 'LOCAL8', 'batch_code' => 'LOT1', 'expires_on' => '2030-01-01', 'pack_size' => 8, 'quantity_packs' => 3, 'good_packs' => $received ? 3 : null, 'damaged_packs' => $received ? 0 : null, 'condition_note' => $received ? 'Checked' : null]]];
    }

    private function body(): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => 3, 'handler' => 'Courier', 'note' => 'Physical', 'lines' => [['original_shipment_line_id' => $this->line, 'batch_id' => $this->batch, 'quantity_packs' => 3]]];
    }

    private function receipt(): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => 4, 'lines' => [['replacement_line_id' => $this->replacementLine, 'good_packs' => 3, 'damaged_packs' => 0, 'condition_note' => 'Checked']]];
    }

    private function ack(bool $received = false): array
    {
        return ['data' => ['order_id' => 'O1', 'shipment_id' => 'H1', 'case_id' => 'C1', 'case_version' => $received ? 5 : 4, 'replacement' => $this->replacementRow($received), 'execution_status' => $received ? 'fulfilled' : 'in_transit']];
    }

    public function test_scoped_page_uses_server_capabilities_escapes_snapshots_and_has_local_pagination(): void
    {
        $this->get($this->base)->assertRedirect('/login');
        $this->fake(['*' => Http::response($this->context())]);
        $this->withSession($this->auth())->get($this->base)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Konfirmasi kirim dan kurangi stok gudang')->assertDontSee('<script>x</script>', false)->assertDontSee('private-fixture');
        $c = $this->context(false, true);
        $c['current_page'] = 2;
        $c['total'] = 51;
        $c['next_page_url'] = 'https://untrusted.test/steal';
        $this->fake(['*' => Http::response($c)]);
        $this->get($this->base.'?page=2')->assertOk()->assertSee('Konfirmasi penerimaan fisik')->assertSee('page=1')->assertDontSee('untrusted.test')->assertDontSee('Konfirmasi kirim dan kurangi stok gudang');
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->data() === ['page' => 2]);
    }

    public function test_dispatch_and_receive_only_send_whitelist_and_exact_ack_releases_pending(): void
    {
        $body = $this->body();
        $this->fake(fn ($r) => Http::response($r->method() === 'GET' ? $this->context() : $this->ack()));
        $this->withSession($this->auth())->post($this->base, [...$body, 'amount' => 99999, 'store_id' => 'OTHER'])->assertRedirect($this->base)->assertSessionMissing($this->slot);
        $expected = $body;
        $expected['lines'] = [['original_shipment_line_id' => $this->line, 'allocations' => [['batch_id' => $this->batch, 'quantity_packs' => 3]]]];
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->data() === $expected);
        $this->fake(fn ($r) => Http::response($r->method() === 'GET' ? $this->context(false, true) : $this->ack(true)));
        $this->post($this->base.'/'.$this->replacement.'/receive', [...$this->receipt(), 'page' => 1, 'source_store_id' => 'OTHER'])->assertRedirect($this->base)->assertSessionMissing($this->slot);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && ! isset($r['page']) && ! isset($r['source_store_id']));
    }

    public function test_unknown_dispatch_preserves_frozen_quantities_uuid_and_path_through_denial_and_wrong_parent(): void
    {
        $calls = 0;
        $this->fake(function ($r) use (&$calls) {
            if ($r->method() === 'GET') {
                return Http::response($this->context());
            }$calls++;

            return $calls === 1 ? Http::response([], 503) : ($calls === 2 ? Http::response([], 403) : Http::response($this->ack()));
        });
        $body = $this->body();
        $this->withSession($this->auth())->post($this->base, $body)->assertRedirect($this->base)->assertSessionHas($this->slot.'.uncertain', true);
        $frozen = session($this->slot.'.body');
        $count = count(Http::recorded());
        $this->get($this->base)->assertOk()->assertSee('Ulangi request yang sama')->assertSee('Kirim 3 kemasan isi 8')->assertDontSee('Konfirmasi kirim dan kurangi stok gudang');
        $this->assertSame($count, count(Http::recorded()));
        $this->get(str_replace('O1', 'OTHER', $this->base))->assertRedirect($this->base);
        $this->post($this->base, $body)->assertConflict();
        $this->post($this->base.'/retry', ['operation_id' => (string) Str::uuid(), 'quantity_packs' => 99])->assertRedirect($this->base)->assertSessionHas($this->slot.'.body', $frozen);
        $this->post($this->base.'/retry', ['version' => 99])->assertRedirect($this->base)->assertSessionMissing($this->slot);
        foreach (Http::recorded(fn ($r) => $r->method() === 'POST') as [$r]) {
            $this->assertSame($frozen, $r->data());
        }
    }

    public function test_malformed_ack_stays_unknown_and_401_or_account_switch_drops_old_operation(): void
    {
        $ack = $this->ack();
        $ack['data']['replacement']['lines'][0]['quantity_packs'] = 4;
        $this->fake(fn ($r) => Http::response($r->method() === 'GET' ? $this->context() : $ack));
        $this->withSession($this->auth())->post($this->base, $this->body())->assertRedirect($this->base)->assertSessionHas($this->slot.'.uncertain', true);
        $this->fake(['*' => Http::response([], 401)]);
        $this->post($this->base.'/retry')->assertRedirect('/login')->assertSessionMissing('api_token')->assertSessionMissing('replacement_pending');
        $this->fake(['*/auth/login' => Http::response(['token' => 'new-fixture', 'user' => ['id' => 9, 'name' => 'New'], 'expires_in' => 1000])]);
        $this->withSession([$this->slot => ['uncertain' => true]])->post('/login', ['email' => 'new@example.test', 'password' => 'ExamplePassword123'])->assertRedirect('/app')->assertSessionMissing('replacement_pending');
    }

    public function test_stale_capability_invalid_counts_and_definitive_errors_never_hold_new_operations(): void
    {
        $c = $this->context();
        $c['can_dispatch'] = false;
        $this->fake(['*' => Http::response($c)]);
        $this->withSession($this->auth())->post($this->base, $this->body())->assertConflict()->assertSessionMissing($this->slot);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->fake(['*' => Http::response($this->context())]);
        $b = $this->body();
        $b['version'] = 2;
        $this->post($this->base, $b)->assertConflict();
        $b = $this->body();
        $b['lines'][0]['quantity_packs'] = 4;
        $this->post($this->base, $b)->assertUnprocessable();
        $this->fake(fn ($r) => Http::response($r->method() === 'GET' ? $this->context() : ['message' => 'Stock changed'], $r->method() === 'GET' ? 200 : 409));
        $this->post($this->base, $this->body())->assertConflict()->assertSessionMissing($this->slot);
        $this->fake(fn ($r) => Http::response($r->method() === 'GET' ? $this->context() : [], $r->method() === 'GET' ? 200 : 429));
        $this->post($this->base, $this->body())->assertRedirect($this->base)->assertSessionHas($this->slot.'.uncertain', true);
    }

    public function test_foreign_context_batches_history_and_receipt_ack_are_rejected(): void
    {
        $bad = [];
        $c = $this->context();
        $c['order_id'] = 'OTHER';
        $bad[] = $c;
        $c = $this->context();
        $c['batches'][0]['store_id'] = 'OTHER';
        $bad[] = $c;
        $c = $this->context(false, true);
        $c['data'][0]['case_id'] = 'OTHER';
        $bad[] = $c;
        $c = $this->context(false, true);
        $c['data'][0]['lines'][0]['original_shipment_line_id'] = 'OTHER';
        $bad[] = $c;
        foreach ($bad as $c) {
            $this->fake(['*' => Http::response($c)]);
            $this->withSession($this->auth())->get($this->base)->assertStatus(503);
        }
        $ack = $this->ack(true);
        $ack['data']['replacement']['lines'][0]['good_packs'] = 2;
        $this->fake(fn ($r) => Http::response($r->method() === 'GET' ? $this->context(false, true) : $ack));
        $this->post($this->base.'/'.$this->replacement.'/receive', $this->receipt())->assertRedirect($this->base)->assertSessionHas($this->slot.'.uncertain', true);
    }
}
