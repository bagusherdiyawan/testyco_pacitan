<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliveryWebTest extends TestCase
{
    private string $product;

    private string $partner;

    private string $line;

    private string $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = (string) Str::ulid();
        $this->partner = (string) Str::ulid();
        $this->line = (string) Str::ulid();
        $this->batch = (string) Str::ulid();
    }

    private function auth(): array
    {
        return ['api_token' => 'private-fixture', 'api_user' => ['id' => 1, 'name' => 'Staff'], 'api_expires_at' => time() + 1000];
    }

    private function fake(mixed $x): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($x);
    }

    private function contract(string $status = 'published', int $version = 2): array
    {
        return ['id' => 'C1', 'organization_id' => 'O1', 'partner_id' => $this->partner, 'version' => $version, 'status' => $status, 'title' => 'Terms <script>', 'mode' => 'buyout', 'starts_on' => '2026-11-03', 'ends_on' => '2030-01-01', 'terms' => 'Private <script>terms</script>', 'brand_terms' => 'Brand scope', 'data_scope' => 'Only agreed scope', 'facilities' => 'Facilities', 'decided_by' => null, 'consent_name' => $status === 'accepted' ? 'Director' : null];
    }

    private function config(): array
    {
        return ['product' => ['id' => $this->product, 'organization_id' => 'O1', 'sku' => 'BUNDLE1', 'name' => 'Bundle', 'pack_size' => 1, 'portion_size' => 1], 'image' => null, 'recipe' => null, 'prices' => [], 'partners' => [], 'components' => []];
    }

    public function test_contract_render_escapes_and_explicit_partner_consent_has_scoped_ack(): void
    {
        $org = $this->partner;
        $url = '/app/organizations/'.$org.'/contracts';
        $this->fake(fn ($r) => Http::response($r->method() === 'GET' ? ['organization_id' => $org, 'data' => [$this->contract()], 'total' => 1, 'current_page' => 1, 'per_page' => 50, 'can_create' => false, 'can_decide' => true] : ['data' => $this->contract('accepted', 3)]));
        $this->withSession($this->auth())->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Saya telah membaca')->assertDontSee('<script>terms</script>', false)->assertDontSee('private-fixture');
        $body = ['operation_id' => (string) Str::uuid(), 'version' => 2, 'consent' => '1', 'consent_name' => 'Director'];
        $this->post($url.'/C1/accept', $body)->assertRedirect($url)->assertSessionMissing('contract_pending.'.$org);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['consent'] === true);
    }

    public function test_unknown_contract_create_keeps_original_payload_through_denial_and_retry_edits(): void
    {
        $url = '/app/organizations/O1/contracts';
        $calls = 0;
        $this->fake(function ($r) use (&$calls) {
            $calls++;

            return $calls === 1 ? Http::response([], 503) : ($calls === 2 ? Http::response([], 403) : Http::response(['data' => [...$this->contract('draft', 1), 'title' => 'Contract', 'terms' => 'Terms', 'brand_terms' => 'Brand', 'data_scope' => 'Onboarding', 'facilities' => 'None']]));
        });
        $body = ['operation_id' => (string) Str::uuid(), 'partner_id' => $this->partner, 'title' => 'Contract', 'mode' => 'buyout', 'starts_on' => '2026-11-03', 'ends_on' => '2030-01-01', 'terms' => 'Terms', 'brand_terms' => 'Brand', 'data_scope' => 'Onboarding', 'facilities' => 'None'];
        $this->withSession($this->auth())->post($url, $body)->assertRedirect($url)->assertSessionHas('contract_pending.O1.body', $body);
        $this->get($url)->assertOk()->assertSee('Ulangi request yang sama');
        $this->assertSame(1, $calls);
        $this->post($url, $body)->assertConflict();
        $this->post($url.'/retry', ['terms' => 'Edited'])->assertRedirect($url)->assertSessionHas('contract_pending.O1.body', $body);
        $this->post($url.'/retry')->assertRedirect($url)->assertSessionMissing('contract_pending.O1');
        foreach (Http::recorded() as [$r]) {
            $this->assertSame($body, $r->data());
        }
    }

    public function test_catalog_configuration_escaped_scoped_price_unknown_and_account_reset(): void
    {
        $url = '/app/organizations/O1/products/'.$this->product.'/configuration';
        $c = $this->config();
        $c['product']['name'] = '<script>bad</script>';
        $this->fake(fn ($r) => Http::response($r->method() === 'GET' ? $c : [], $r->method() === 'GET' ? 200 : 429));
        $this->withSession($this->auth())->get($url)->assertOk()->assertSee('Harga dengan masa berlaku')->assertDontSee('<script>bad</script>', false);
        $body = ['operation_id' => (string) Str::uuid(), 'channel' => 'retail', 'starts_on' => '2026-11-03', 'ends_on' => '2030-01-01', 'price' => 5000, 'reason' => 'Reviewed'];
        $this->post($url.'/prices', $body)->assertRedirect($url)->assertSessionHas('catalog_configuration_pending.'.$this->product.'.uncertain', true);
        $this->fake(['*' => Http::response([], 401)]);
        $this->post($url.'/retry')->assertRedirect('/login')->assertSessionMissing('catalog_configuration_pending')->assertSessionMissing('api_token');
        $c['product']['organization_id'] = 'OTHER';
        $this->fake(['*' => Http::response($c)]);
        $this->withSession($this->auth())->get($url)->assertStatus(503);
    }

    public function test_image_upload_encodes_only_file_bytes_and_ack_does_not_expose_backend_cookie_or_path(): void
    {
        $url = '/app/organizations/O1/products/'.$this->product.'/configuration';
        $this->fake(['*' => Http::response(['data' => ['product_id' => $this->product, 'version' => 1, 'mime' => 'image/png', 'sha256' => hash('sha256', 'binary fixture')]])]);
        $file = UploadedFile::fake()->createWithContent('photo.png', 'binary fixture');
        $this->withSession($this->auth())->post($url.'/image', ['operation_id' => (string) Str::uuid(), 'version' => 0, 'image' => $file, 'path' => '/etc/passwd'])->assertRedirect($url)->assertSessionMissing('catalog_configuration_pending.'.$this->product);
        Http::assertSent(fn ($r) => $r['image_base64'] === base64_encode('binary fixture') && ! isset($r['path']) && ! isset($r['image']));
    }

    public function test_bundle_form_builds_server_recipe_quantities_and_recovers_unknown_draft(): void
    {
        $store = 'S1';
        $shift = (string) Str::ulid();
        $path = '/app/stores/S1/pos/bundles/'.$this->product;
        $c = ['product' => ['id' => $this->product, 'name' => 'Bundle'], 'recipe' => ['version' => 1, 'components' => [['product_id' => $this->line, 'sku' => 'AYAM', 'pieces' => 2]]], 'batches' => [['id' => $this->batch, 'store_id' => $store, 'product_id' => $this->line, 'batch_code' => 'LOT', 'opened_pieces' => 10, 'ready_pieces' => 0]]];
        $this->fake(function ($r) use ($c, $shift) {
            if ($r->method() === 'GET') {
                return Http::response(str_ends_with($r->url(), '/pos/context') ? ['data' => ['store' => ['id' => 'S1'], 'shift' => ['id' => $shift]]] : $c);
            }

            return Http::response(['data' => ['id' => 'I1', 'store_id' => 'S1', 'cash_shift_id' => $shift, 'status' => 'pending']]);
        });
        $this->withSession($this->auth())->get($path)->assertOk()->assertSee('AYAM');
        $body = ['operation_id' => (string) Str::uuid(), 'shift_id' => $shift, 'quantity' => 3, 'components' => [['product_id' => $this->line, 'stock' => $this->batch.':opened']]];
        $this->post($path, $body)->assertRedirect('/app/pos/intents/I1');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['lines'][0]['allocations'][0]['quantity'] === 6);
    }

    public function test_revision_preview_is_read_only_then_confirm_keeps_hash_and_malformed_ack_unknown(): void
    {
        $path = '/app/orders/O1/revisions';
        $line = ['id' => $this->line, 'sku' => 'OLD8', 'local_sku' => 'LOCAL8', 'pack_size' => 8, 'unit_price' => 18000, 'quantity_packs' => 20];
        $quote = ['order_id' => 'O1', 'version' => 3, 'review_hash' => str_repeat('a', 64), 'before' => ['lines' => [$line], 'total_amount' => 360000], 'after' => ['lines' => [[...$line, 'quantity_packs' => 8]], 'total_amount' => 144000]];
        $this->fake(function ($r) use ($quote, $line) {
            if ($r->method() === 'GET') {
                return Http::response(['order' => ['id' => 'O1', 'version' => 3, 'lines' => [$line]], 'actions' => ['propose' => true]]);
            }if (str_ends_with($r->url(), '/preview')) {
                return Http::response(['data' => $quote]);
            }

            return Http::response(['data' => ['order' => ['id' => 'WRONG', 'version' => 4], 'revision' => ['id' => 'R1', 'supply_order_id' => 'O1', 'status' => 'pending']]]);
        });
        $body = ['operation_id' => (string) Str::uuid(), 'version' => 3, 'reason' => 'Reduce remaining', 'existing' => [['order_line_id' => $this->line, 'quantity_packs' => 8]], 'additions' => []];
        $this->withSession($this->auth())->post($path, $body)->assertRedirect($path)->assertSessionHas('order_revision_review.O1.body.review_hash', str_repeat('a', 64));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && ! str_ends_with($r->url(), '/preview'));
        $this->get($path)->assertOk()->assertSee('Konfirmasi ajukan revisi')->assertSee('144000');
        $this->post($path.'/confirm', ['version' => 99])->assertRedirect($path)->assertSessionHas('order_revisions_pending.O1.body.version', 3)->assertSessionMissing('order_revision_review.O1');
    }
}
