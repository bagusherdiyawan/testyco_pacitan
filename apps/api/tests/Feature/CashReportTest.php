<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Sales\Infrastructure\SaleIntent;
use App\Modules\Sales\Infrastructure\SaleLine;
use App\Modules\Shifts\Infrastructure\CashShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashReportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Store $a;

    private Store $b;

    private User $lead;

    private Product $product;

    private string $path;

    private array $period = ['from' => '2026-10-27T00:00:00+07:00', 'to' => '2026-10-29T00:00:00+07:00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'A', 'type' => 'partner']);
        $this->a = Store::create(['organization_id' => $this->org->id, 'name' => 'A1']);
        $this->b = Store::create(['organization_id' => $this->org->id, 'name' => 'A2']);
        $this->lead = $this->user($this->org, 'partner_lead');
        $this->product = Product::create(['organization_id' => $this->org->id, 'sku' => 'AYAM', 'name' => 'Ayam', 'condition' => 'frozen', 'pack_size' => 10, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 20000]);
        $this->path = '/api/v1/organizations/'.$this->org->id.'/reports/cash-sales';
        Sanctum::actingAs($this->lead);
    }

    private function user(Organization $org, string $role, ?Store $store = null): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $u->id, 'role' => $role]);
        if ($store) {
            DB::table('store_user')->insert(['store_id' => $store->id, 'user_id' => $u->id]);
        }

        return $u;
    }

    private function fact(Store $store, int $quantity = 4, string $time = '2026-10-28 03:00:00', int $pieces = 1, string $name = 'Ayam'): Sale
    {
        $total = $quantity * $pieces * 3000;
        $shift = CashShift::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'cashier_id' => $this->lead->id, 'cashier_name' => 'Kasir historis', 'device_name' => 'Fixture', 'status' => 'closed', 'version' => 1, 'opening_cash' => 0, 'cash_sales' => $total, 'cash_in' => 0, 'cash_out' => 0, 'expected_cash' => $total, 'opened_at' => $time, 'closed_at' => $time]);
        $intent = SaleIntent::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'cash_shift_id' => $shift->id, 'cashier_id' => $this->lead->id, 'status' => 'paid', 'total_amount' => $total, 'price_hash' => str_repeat('a', 64), 'items' => []]);
        $sale = Sale::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'cash_shift_id' => $shift->id, 'sale_intent_id' => $intent->id, 'cashier_id' => $this->lead->id, 'number' => 'POS-'.Str::ulid(), 'store_name' => $store->name, 'store_address' => null, 'cashier_name' => 'Kasir historis', 'status' => 'completed', 'currency' => 'IDR', 'payment_method' => 'cash', 'subtotal' => $total, 'discount_amount' => 0, 'total_amount' => $total, 'cash_received' => $total + 10000, 'change_amount' => 10000, 'sold_at' => $time]);
        SaleLine::create(['sale_id' => $sale->id, 'product_id' => $this->product->id, 'sku' => 'AYAM', 'name' => $name, 'condition' => 'frozen', 'unit' => $pieces === 1 ? 'piece' : 'portion', 'quantity' => $quantity, 'pieces_per_unit' => $pieces, 'unit_price' => 3000 * $pieces, 'line_total' => $total]);

        return $sale;
    }

    private function url(string $suffix = '', array $extra = []): string
    {
        return $this->path.$suffix.'?'.http_build_query([...$this->period, ...$extra]);
    }

    public function test_totals_equal_store_rows_and_keep_empty_inactive_store_history(): void
    {
        $this->fact($this->a);
        $this->fact($this->b, 2);
        Store::create(['organization_id' => $this->org->id, 'name' => 'A3']);
        $this->b->update(['active' => false]);
        $data = $this->getJson($this->url())->assertOk()->assertJsonPath('data.totals.transaction_count', 2)->assertJsonPath('data.totals.net_sales', 18000)->json('data');
        $this->assertCount(3, $data['by_store']);
        $this->assertSame($data['totals']['net_sales'], array_sum(array_column($data['by_store'], 'net_sales')));
        $this->assertFalse($data['by_store'][1]['active']);
        $this->assertSame(0, $data['by_store'][2]['net_sales']);
        foreach (['', '/products'] as $suffix) {
            $response = $this->getJson($this->url($suffix))->assertOk();
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertArrayHasKey('observed_at', $data);
        $this->assertSame('server_confirmed_cash_sales', $data['scope']);
        $this->getJson($this->url('', ['store_id' => $this->b->id]))->assertJsonPath('data.totals.net_sales', 6000)->assertJsonCount(1, 'data.by_store');
    }

    public function test_store_manager_only_sees_assigned_stores_in_every_output_and_export(): void
    {
        $one = $this->fact($this->a);
        $two = $this->fact($this->b);
        Sanctum::actingAs($this->user($this->org, 'store_manager', $this->a));
        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data.available_stores')->assertJsonPath('data.totals.transaction_count', 1);
        $this->getJson($this->url('/products'))->assertOk()->assertJsonPath('data.0.sold_pieces', 4);
        $csv = $this->get($this->url('/export'))->assertOk()->streamedContent();
        $this->assertStringContainsString($one->number, $csv);
        $this->assertStringNotContainsString($two->number, $csv);
        foreach (['', '/products', '/export'] as $suffix) {
            $this->getJson($this->url($suffix, ['store_id' => $this->b->id]))->assertForbidden();
        }
    }

    public function test_cashier_warehouse_unassigned_other_tenant_and_center_override_are_denied(): void
    {
        $other = Organization::create(['name' => 'B', 'type' => 'partner']);
        $center = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        foreach ([$this->user($this->org, 'cashier', $this->a), $this->user($this->org, 'warehouse', $this->a), $this->user($other, 'partner_lead'), $this->user($center, 'owner')] as $u) {
            Sanctum::actingAs($u);
            foreach (['', '/products', '/export'] as $suffix) {
                $this->getJson($this->url($suffix))->assertForbidden();
            }
        }
        Sanctum::actingAs($this->user($this->org, 'store_manager'));
        $this->getJson($this->url())->assertJsonPath('data.totals.transaction_count', 0)->assertJsonCount(0, 'data.available_stores');
        $this->getJson($this->url('', ['store_id' => $this->a->id]))->assertForbidden();
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_foreign_store_filters_are_denied_even_for_org_leader(): void
    {
        $other = Organization::create(['name' => 'B', 'type' => 'partner']);
        $store = Store::create(['organization_id' => $other->id, 'name' => 'B1']);
        $this->fact($store, 100);
        foreach (['', '/products', '/export'] as $suffix) {
            $this->getJson($this->url($suffix, ['store_id' => $store->id]))->assertForbidden();
        }
        $this->getJson($this->url())->assertJsonPath('data.totals.net_sales', 0);
    }

    public function test_wib_boundaries_are_start_inclusive_end_exclusive(): void
    {
        $this->fact($this->a, 1, '2026-10-26 16:59:59');
        $first = $this->fact($this->a, 2, '2026-10-26 17:00:00');
        $this->fact($this->a, 3, '2026-10-28 16:59:59');
        $last = $this->fact($this->a, 4, '2026-10-28 17:00:00');
        $this->getJson($this->url())->assertJsonPath('data.totals.transaction_count', 2)->assertJsonPath('data.totals.net_sales', 15000);
        $csv = $this->get($this->url('/export'))->assertOk()->streamedContent();
        $this->assertStringContainsString($first->number, $csv);
        $this->assertStringNotContainsString($last->number, $csv);
        $this->assertStringContainsString('2026-10-26T17:00:00+00:00', $csv);
    }

    public function test_products_use_historical_conversion_and_names_after_master_changes(): void
    {
        $this->fact($this->a, 2, '2026-10-28 03:00:00', 4, 'Nama lama');
        $this->fact($this->a, 3, '2026-10-28 04:00:00', 1, 'Nama baru');
        $this->product->update(['active' => false, 'pack_size' => 8, 'retail_price' => 999, 'name' => 'Master diubah']);
        $this->getJson($this->url('/products'))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'Nama baru')->assertJsonPath('data.0.sold_pieces', 3)->assertJsonPath('data.1.name', 'Nama lama')->assertJsonPath('data.1.sold_pieces', 8)->assertJsonPath('data.1.gross_sales', 24000);
        $this->getJson($this->url())->assertJsonPath('data.totals.net_sales', 33000);
    }

    public function test_product_pagination_is_scoped_and_stable_for_historical_names(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->fact($this->a, 1, '2026-10-28 03:00:00', 1, 'Nama '.sprintf('%03d', $i));
        }
        $this->getJson($this->url('/products'))->assertJsonCount(50, 'data')->assertJsonPath('total', 51)->assertJsonPath('last_page', 2);
        $this->getJson($this->url('/products', ['page' => 2]))->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Nama 050');
    }

    public function test_csv_snapshot_formula_safety_headers_and_export_audit(): void
    {
        $this->a->update(['name' => '=HYPERLINK("untrusted")']);
        $sale = $this->fact($this->a);
        $sale->update(['cashier_name' => " \t+SUM(1,2)"]);
        $this->a->update(['name' => 'Nama master baru']);
        $response = $this->get($this->url('/export'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $csv = $response->streamedContent();
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        fgetcsv($stream, 0, ',', '"', '');
        $row = fgetcsv($stream, 0, ',', '"', '');
        fclose($stream);
        $this->assertSame("'=HYPERLINK(\"untrusted\")", $row[2]);
        $this->assertSame("' \t+SUM(1,2)", $row[4]);
        $this->assertSame('12000', $row[8]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'report.cash_export_prepared', 'actor_id' => $this->lead->id]);
        $this->assertDatabaseCount('stock_entries', 0);
        $this->assertSame(12000, $sale->fresh()->total_amount);
    }

    public function test_rejects_missing_timezone_invalid_dates_excess_period_and_page(): void
    {
        foreach ([['from' => '2026-10-27'], ['to' => '2026-10-27T00:00:00+07:00'], ['to' => '2026-12-01T00:00:00+07:00'], ['from' => 'invalid'], ['page' => 0], ['store_id' => 'bad']] as $bad) {
            $this->getJson($this->url('', $bad))->assertUnprocessable();
        }
        $this->org->update(['active' => false]);
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_export_limit_accepts_ten_thousand_and_rejects_overflow_without_truncation(): void
    {
        $sample = $this->fact($this->a);
        $sale = $sample->getAttributes();
        $intent = SaleIntent::findOrFail($sample->sale_intent_id)->getAttributes();
        $line = SaleLine::where('sale_id', $sample->id)->firstOrFail()->getAttributes();
        for ($offset = 0; $offset < 9999; $offset += 500) {
            $sales = $intents = $lines = [];
            for ($i = $offset; $i < min($offset + 500, 9999); $i++) {
                $sid = (string) Str::ulid();
                $iid = (string) Str::ulid();
                $intents[] = [...$intent, 'id' => $iid];
                $sales[] = [...$sale, 'id' => $sid, 'number' => 'POS-'.$sid, 'sale_intent_id' => $iid];
                $lines[] = [...$line, 'id' => (string) Str::ulid(), 'sale_id' => $sid];
            }
            DB::table('sale_intents')->insert($intents);
            DB::table('sales')->insert($sales);
            DB::table('sale_lines')->insert($lines);
        }
        $this->get($this->url('/export'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->fact($this->a);
        $this->getJson($this->url('/export'))->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->assertDatabaseCount('audit_entries', 1);
    }
}
