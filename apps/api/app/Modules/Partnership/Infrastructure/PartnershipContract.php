<?php

namespace App\Modules\Partnership\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class PartnershipContract extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['attachment_path'];

    protected $appends = ['has_attachment'];

    public function getHasAttachmentAttribute(): bool
    {
        return isset($this->attributes['attachment_path']);
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'decided_at' => 'datetime', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }
}
