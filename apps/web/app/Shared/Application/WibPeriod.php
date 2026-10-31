<?php

namespace App\Shared\Application;

use Carbon\CarbonImmutable;

final readonly class WibPeriod
{
    private function __construct(public CarbonImmutable $from, public CarbonImmutable $to) {}

    public static function fromDays(?string $from, ?string $to): self
    {
        $today = CarbonImmutable::now('Asia/Jakarta')->format('Y-m-d');
        $start = CarbonImmutable::parse($from ?? $today, 'Asia/Jakarta')->startOfDay();
        $end = CarbonImmutable::parse($to ?? $today, 'Asia/Jakarta')->startOfDay()->addDay();
        abort_unless($end->greaterThan($start) && $end->timestamp - $start->timestamp <= 31 * 86400, 422, 'Rentang maksimal 31 hari dan tanggal akhir tidak sebelum awal.');

        return new self($start, $end);
    }

    public function api(): array
    {
        return ['from' => $this->from->toIso8601String(), 'to' => $this->to->toIso8601String()];
    }

    public function calendar(): array
    {
        return ['from_day' => $this->from->format('Y-m-d'), 'to_day' => $this->to->subDay()->format('Y-m-d')];
    }
}
