<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Infrastructure\Product;
use Illuminate\Support\Facades\DB;

final class EffectivePrice
{
    public static function for(Product $p, string $channel, ?string $partner = null): int
    {
        $today = now('Asia/Jakarta')->format('Y-m-d');
        $q = DB::table('product_price_periods')->where('product_id', $p->id)->where('organization_id', $p->organization_id)->where('channel', $channel)->where('active', true)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today);
        if ($channel === 'wholesale' && $partner) {
            $specific = (clone $q)->where('partner_id', $partner)->value('price');
            if ($specific !== null) {
                return (int) $specific;
            }
        }
        $price = $q->whereNull('partner_id')->value('price');

        return $price !== null ? (int) $price : (int) ($channel === 'retail' ? $p->retail_price : $p->wholesale_price);
    }
}
