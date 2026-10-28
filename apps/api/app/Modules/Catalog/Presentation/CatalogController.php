<?php

namespace App\Modules\Catalog\Presentation;

use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class CatalogController
{
    public function __construct(private Access $access) {}

    private function manage(Request $r, string $org): void
    {
        abort_unless($this->access->role($r->user(), $org)?->manages(), 403);
    }

    public function index(Request $r, string $organization): mixed
    {
        $this->manage($r, $organization);

        return Product::where('organization_id', $organization)->orderBy('name')->paginate(50);
    }

    public function storeCatalog(Request $r, string $store): mixed
    {
        $s = $this->access->operatingStore($r->user(), $store);

        return Product::where('organization_id', $s->organization_id)->where('active', true)->orderBy('name')->paginate(100, ['id', 'sku', 'name', 'variant', 'condition', 'pack_size', 'portion_size', 'retail_price']);
    }

    private function rules(string $org, ?string $product = null): array
    {
        return ['sku' => ['required', 'string', 'max:60', Rule::unique('products')->where('organization_id', $org)->ignore($product)], 'name' => 'required|string|max:120', 'variant' => 'nullable|string|max:80', 'condition' => ['required', Rule::in(['frozen', 'ready'])], 'pack_size' => 'required|integer|min:1|max:10000', 'portion_size' => 'required|integer|min:1|max:10000', 'wholesale_price' => 'required|integer|min:0|max:1000000000', 'retail_price' => 'required|integer|min:0|max:1000000000', 'active' => 'sometimes|boolean'];
    }

    public function create(Request $r, string $organization): mixed
    {
        $this->manage($r, $organization);
        $v = $r->validate($this->rules($organization));

        return DB::transaction(function () use ($r, $organization, $v) {
            $p = Product::create([...$v, 'organization_id' => $organization]);
            Audit::record($r->user()->id, $organization, 'product.created', $p->id, null, $p->toArray());

            return response()->json(['data' => $p], 201);
        });
    }

    public function update(Request $r, string $organization, string $product): mixed
    {
        $this->manage($r, $organization);
        $p = Product::where('organization_id', $organization)->findOrFail($product);
        $v = $r->validate($this->rules($organization, $product));

        return DB::transaction(function () use ($r, $organization, $p, $v) {
            $p = Product::where('organization_id', $organization)->lockForUpdate()->findOrFail($p->id);
            $before = $p->toArray();
            $p->update($v);
            Audit::record($r->user()->id, $organization, 'product.updated', $p->id, $before, $p->toArray());

            return ['data' => $p];
        });
    }
}
