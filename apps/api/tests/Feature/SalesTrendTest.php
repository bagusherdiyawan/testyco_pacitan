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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Daily sales for dashboards: all confirmed payment methods, Jakarta calendar days, same scoping as the cash report. */
class SalesTrendTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $rival;

    private Store $a;

    private Store $b;

    private User $lead;

    private User $manager;

    private User $cashier;

    private User $rivalLead;

    private string $path;

    private array $period = ['from' => '2026-10-26T00:00:00+07:00', 'to' => '2026-10-29T00:00:00+07:00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'A', 'type' => 'partner']);
        $this->rival = Organization::create(['name' => 'B', 'type' => 'partner']);
        $this->a = Store::create(['organization_id' => $this->org->id, 'name' => 'A1']);
        $this->b = Store::create(['organization_id' => $this->org->id, 'name' => 'A2']);
        $this->lead = $this->user($this->org, 'partner_lead');
        $this->manager = $this->user($this->org, 'store_manager', $this->a);
        $this->cashier = $this->user($this->org, 'cashier', $this->a);
        $this->rivalLead = $this->user($this->rival, 'partner_lead');
        $this->path = '/api/v1/organizations/'.$this->org->id.'/reports/sales-trend';
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

    private function sale(Store $store, string $utc, int $total, string $method = 'cash', string $sku = 'AYAM', int $pieces = 4): Sale
    {
        $shift = CashShift::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'cashier_id' => $this->lead->id, 'cashier_name' => 'Kasir', 'device_name' => 'Fixture', 'status' => 'closed', 'version' => 1, 'opening_cash' => 0, 'cash_sales' => 0, 'cash_in' => 0, 'cash_out' => 0, 'expected_cash' => 0, 'opened_at' => $utc, 'closed_at' => $utc]);
        $intent = SaleIntent::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'cash_shift_id' => $shift->id, 'cashier_id' => $this->lead->id, 'status' => 'paid', 'total_amount' => $total, 'price_hash' => str_repeat('a', 64), 'items' => []]);
        $sale = Sale::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'cash_shift_id' => $shift->id, 'sale_intent_id' => $intent->id, 'cashier_id' => $this->lead->id, 'number' => 'POS-'.Str::ulid(), 'store_name' => $store->name, 'store_address' => null, 'cashier_name' => 'Kasir', 'status' => 'completed', 'currency' => 'IDR', 'payment_method' => $method, 'subtotal' => $total, 'discount_amount' => 0, 'total_amount' => $total, 'cash_received' => $method === 'cash' ? $total : 0, 'change_amount' => 0, 'sold_at' => $utc]);
        $product = Product::firstOrCreate(['organization_id' => $store->organization_id, 'sku' => $sku], ['name' => 'Produk '.$sku, 'condition' => 'frozen', 'pack_size' => 10, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 20000]);
        SaleLine::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'sku' => $sku, 'name' => 'Produk '.$sku, 'condition' => 'frozen', 'unit' => 'piece', 'quantity' => 1, 'pieces_per_unit' => $pieces, 'unit_price' => $total, 'line_total' => $total]);

        return $sale;
    }

    private function refund(Sale $sale, string $utc, int $amount): void
    {
        DB::table('sale_refunds')->insert(['id' => (string) Str::ulid(), 'sale_id' => $sale->id, 'cash_shift_id' => $sale->cash_shift_id, 'actor_id' => $this->lead->id, 'payment_method' => $sale->payment_method, 'reference' => 'R-'.Str::ulid(), 'stock_disposition' => 'no_stock', 'amount' => $amount, 'lines' => '[]', 'reason' => 'x', 'refunded_at' => $utc]);
    }

    private function read(array $extra = []): TestResponse
    {
        return $this->getJson($this->path.'?'.http_build_query([...$this->period, ...$extra]));
    }

    public function test_days_use_jakarta_calendar_cover_empty_days_and_split_cash_from_noncash(): void
    {
        // 2026-10-26 20:00 UTC is 27 Oct 03:00 WIB; 2026-10-27 18:00 UTC is 28 Oct 01:00 WIB.
        $this->sale($this->a, '2026-10-26 20:00:00', 12000, 'cash');
        $this->sale($this->a, '2026-10-26 22:00:00', 8000, 'qris_manual');
        $this->sale($this->b, '2026-10-27 18:00:00', 5000, 'bank_transfer');
        $r = $this->read()->assertOk()->assertJsonPath('data.scope', 'server_confirmed_sales_all_payment_methods');
        $days = collect($r->json('data.days'))->keyBy('date');
        $this->assertSame(['2026-10-26', '2026-10-27', '2026-10-28'], $days->keys()->all());
        $this->assertSame(0, $days['2026-10-26']['transactions']);
        $this->assertSame([2, 12000, 8000], [$days['2026-10-27']['transactions'], $days['2026-10-27']['cash'], $days['2026-10-27']['noncash']]);
        $this->assertSame([1, 0, 5000], [$days['2026-10-28']['transactions'], $days['2026-10-28']['cash'], $days['2026-10-28']['noncash']]);
        $this->assertSame(25000, $r->json('data.totals.revenue'));
        $this->assertSame(3, $r->json('data.totals.transactions'));
    }

    public function test_drafts_cancelled_other_currency_and_outside_period_are_excluded_and_refunds_stay_on_their_own_day(): void
    {
        $kept = $this->sale($this->a, '2026-10-27 03:00:00', 10000);
        $this->sale($this->a, '2026-10-27 03:00:00', 99999)->update(['status' => 'void']);
        $this->sale($this->a, '2026-10-27 03:00:00', 77777)->update(['currency' => 'USD']);
        $this->sale($this->a, '2026-10-24 03:00:00', 55555);
        $this->sale($this->a, '2026-10-29 03:00:00', 44444);
        $this->refund($kept, '2026-10-28 03:00:00', 3000);
        $r = $this->read()->assertOk();
        $days = collect($r->json('data.days'))->keyBy('date');
        $this->assertSame(10000, $days['2026-10-27']['cash']);
        $this->assertSame([0, 3000], [$days['2026-10-27']['refunds'], $days['2026-10-28']['refunds']]);
        $this->assertSame(7000, $r->json('data.totals.net_revenue'));
    }

    public function test_top_products_rank_by_sales_across_payment_methods(): void
    {
        $this->sale($this->a, '2026-10-27 03:00:00', 9000, 'cash', 'AYAM');
        $this->sale($this->a, '2026-10-27 04:00:00', 4000, 'qris_manual', 'AYAM');
        $this->sale($this->b, '2026-10-27 05:00:00', 20000, 'bank_transfer', 'UDANG', 8);
        $top = $this->read()->assertOk()->json('data.top_products');
        $this->assertSame(['UDANG', 'AYAM'], array_column($top, 'sku'));
        $this->assertSame([20000, 8], [$top[0]['gross_sales'], $top[0]['sold_pieces']]);
        $this->assertSame([13000, 8], [$top[1]['gross_sales'], $top[1]['sold_pieces']]);
    }

    public function test_scope_follows_the_cash_report_store_assignment_and_tenant_rules(): void
    {
        $this->sale($this->a, '2026-10-27 03:00:00', 10000);
        $this->sale($this->b, '2026-10-27 03:00:00', 6000);
        $this->assertSame(16000, $this->read()->json('data.totals.revenue'));
        $this->assertSame(10000, $this->read(['store_id' => $this->a->id])->json('data.totals.revenue'));
        // A store manager only sees the assigned store; another store is refused, not shown as zero.
        Sanctum::actingAs($this->manager);
        $this->assertSame(10000, $this->read()->json('data.totals.revenue'));
        $this->read(['store_id' => $this->b->id])->assertStatus(403);
        // Cashiers and other organizations have no aggregate access.
        Sanctum::actingAs($this->cashier);
        $this->read()->assertForbidden();
        Sanctum::actingAs($this->rivalLead);
        $this->read()->assertForbidden();
    }

    public function test_the_period_is_validated_like_other_reports(): void
    {
        $this->read(['to' => '2026-10-26T00:00:00+07:00'])->assertStatus(422);
        $this->read(['from' => '2026-08-01T00:00:00+07:00'])->assertStatus(422);
        $this->getJson($this->path.'?from=2026-10-26&to=2026-10-29')->assertStatus(422);
        $this->getJson($this->path)->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson($this->path.'?'.http_build_query($this->period))->assertUnauthorized();
    }
}
