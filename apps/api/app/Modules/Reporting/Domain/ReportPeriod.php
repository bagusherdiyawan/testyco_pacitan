<?php

namespace App\Modules\Reporting\Domain;

use Carbon\CarbonImmutable;

final readonly class ReportPeriod
{
    public function __construct(public CarbonImmutable $from, public CarbonImmutable $to)
    {
        if (! $to->greaterThan($from) || $to->timestamp - $from->timestamp > 31 * 86400) {
            throw new \DomainException('Periode laporan harus lebih dari nol dan maksimal 31 hari.');
        }
    }

    public function toArray(): array
    {
        return ['from' => $this->from->toIso8601String(), 'to' => $this->to->toIso8601String()];
    }
}
