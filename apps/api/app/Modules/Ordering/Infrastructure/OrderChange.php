<?php

namespace App\Modules\Ordering\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class OrderChange extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['base_version' => 'integer', 'pending_version' => 'integer', 'before' => 'array', 'after' => 'array', 'decided_at' => 'datetime'];
    }

    public static function pending(string $order): bool
    {
        return self::where('pending_order_id', $order)->exists();
    }
}
