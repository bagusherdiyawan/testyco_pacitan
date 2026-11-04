<?php

namespace App\Modules\Reliability\Presentation;

use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Application\Access;
use App\Modules\Inventory\Application\FoodSafety;
use App\Modules\Reliability\Application\FoodPolicy;
use App\Modules\Reliability\Application\Inbox;
use Illuminate\Http\Request;

final class ReliabilityController
{
    public function __construct(private Inbox $inbox, private FoodPolicy $food) {}

    public function fefo(Request $r, string $store): mixed
    {
        $s = app(Access::class)->store($r->user(), $store);
        $v = $r->validate(['product_id' => 'required|ulid', 'bucket' => 'required|in:usable,opened,ready', 'condition' => 'required|in:frozen,ready', 'pack_size' => 'required_if:bucket,usable|nullable|integer|min:1']);
        Product::where('organization_id', $s->organization_id)->findOrFail($v['product_id']);

        return response()->json(app(FoodSafety::class)->suggestions($store, $v['product_id'], $v['bucket'], $v['condition'], isset($v['pack_size']) ? (int) $v['pack_size'] : null))->header('Cache-Control', 'private, no-store');
    }

    public function inbox(Request $r): mixed
    {
        $v = $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json($this->inbox->read($r->user(), (int) ($v['page'] ?? 1)))->header('Cache-Control', 'private, no-store');
    }

    public function mark(Request $r, string $entry): mixed
    {
        return ['data' => $this->inbox->mark($r->user(), $entry)];
    }

    public function preferences(Request $r): mixed
    {
        $v = $r->validate(['version' => 'required|integer|min:0', 'email_enabled' => 'required|boolean']);

        return ['data' => $this->inbox->preferences($r->user(), $v)];
    }

    public function policy(Request $r, string $organization): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1', 'ready_page' => 'sometimes|integer|min:1']);

        return response()->json($this->food->context($r->user(), $organization))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $r, string $organization): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:0', 'enabled' => 'required|boolean', 'block_expired' => 'required|boolean', 'fefo' => 'required|in:off,suggest,enforce', 'ready_life_minutes' => 'present|nullable|integer|min:1|max:525600', 'reference' => 'required|string|max:2000']);

        return ['data' => $this->food->save($r->user(), $organization, $v)];
    }

    public function recall(Request $r, string $organization): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'product_id' => 'required|ulid', 'batch_code' => 'required|string|max:80', 'reason' => 'required|string|max:2000', 'reference' => 'required|string|max:2000']);

        return response()->json(['data' => $this->food->recall($r->user(), $organization, $v)], 201);
    }

    public function withdraw(Request $r, string $organization, string $recall): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000']);

        return ['data' => $this->food->withdraw($r->user(), $organization, $recall, $v)];
    }

    public function ready(Request $r, string $organization): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'batch_id' => 'required|ulid', 'expected_revision' => 'required|integer|min:1', 'prepared_at' => ['required', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'], 'reference' => 'required|string|max:2000']);

        return ['data' => $this->food->ready($r->user(), $organization, $v)];
    }
}
