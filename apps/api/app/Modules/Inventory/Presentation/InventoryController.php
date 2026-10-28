<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Catalog\Application\SupplyCatalog;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Shared\Application\Operations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InventoryController
{
    public function __construct(private Access $access, private StockLedger $stock, private SupplyCatalog $catalog, private Operations $operations) {}

    public function batches(Request $r, string $store): mixed
    {
        $this->access->store($r->user(), $store);

        return InventoryBatch::where('store_id', $store)->orderBy('expires_on')->orderBy('id')->paginate(100);
    }

    public function ledger(Request $r, string $store): mixed
    {
        $this->access->store($r->user(), $store, ['owner', 'admin', 'warehouse', 'partner_lead', 'store_manager']);

        return DB::table('stock_entries')->where('store_id', $store)->orderByDesc('id')->paginate(100);
    }

    public function intake(Request $r, string $store): mixed
    {
        $s = $this->access->operatingStore($r->user(), $store, ['owner', 'admin', 'warehouse']);
        abort_unless(Organization::findOrFail($s->organization_id)->type === 'center', 403, 'Penerimaan awal gudang hanya untuk pusat; toko mitra melalui pesanan yang disetujui.');
        $v = $r->validate(['operation_id' => 'required|uuid', 'product_id' => 'required|ulid', 'batch_code' => 'required|string|max:80', 'expires_on' => 'required|date_format:Y-m-d', 'quantity_packs' => 'required|integer|min:1|max:10000', 'note' => 'required|string|max:2000']);
        $result = $this->operations->run($s->organization_id, $r->user()->id, $v['operation_id'], 'inventory.intake:'.$store, $v, function () use ($r, $s, $v) {
            $product = $this->catalog->intakeProduct($v['product_id'], $s->organization_id);
            $id = (string) Str::ulid();
            $batch = $this->stock->credit($s->organization_id, $s->id, $product, $v['batch_code'], $v['expires_on'], (int) $v['quantity_packs'], $r->user()->id, 'warehouse_intake', $id, 'usable', $v['note']);
            Audit::record($r->user()->id, $s->organization_id, 'inventory.intake', $batch['id'], null, ['source_id' => $id, 'quantity_packs' => (int) $v['quantity_packs'], 'note' => $v['note']]);

            return $batch;
        });

        return response()->json(['data' => $result], 201);
    }
}
