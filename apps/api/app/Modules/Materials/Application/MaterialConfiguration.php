<?php

namespace App\Modules\Materials\Application;

use App\Modules\Materials\Domain\Quantity;
use App\Modules\Materials\Infrastructure\Material;

final class MaterialConfiguration
{
    public function update(string $org, array $v): array
    {
        $m = Material::where('organization_id', $org)->lockForUpdate()->findOrFail($v['id']);
        abort_unless($m->version === $v['version'], 409, 'Versi bahan berubah.');
        $m->fill(['name' => $v['name'], 'minimum_quantity' => Quantity::parse($v['minimum'], $m->unit, true), 'expiry_required' => $v['expiry_required'], 'fefo_required' => $v['fefo_required'], 'active' => $v['active'], 'version' => $m->version + 1]);
        $m->save();

        return $m->toArray();
    }
}
