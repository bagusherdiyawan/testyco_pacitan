<?php

namespace App\Shared\Application;

use Illuminate\Validation\ValidationException;

final class BoundedExport
{
    public const MAX_ROWS = 10000;

    public const MAX_BYTES = 8 * 1024 * 1024;

    public static function collect(iterable $entries, string $label): array
    {
        $rows = [];
        $bytes = 2;
        foreach ($entries as $entry) {
            if (count($rows) === self::MAX_ROWS) {
                throw ValidationException::withMessages(['export' => "Ekspor $label maksimal 10.000 catatan. Persempit filter."]);
            }
            $bytes += strlen(json_encode($entry, JSON_THROW_ON_ERROR)) + 1;
            if ($bytes > self::MAX_BYTES) {
                throw ValidationException::withMessages(['export' => "Data ekspor $label melebihi 8 MiB. Persempit filter."]);
            }
            $rows[] = $entry;
        }

        return $rows;
    }
}
