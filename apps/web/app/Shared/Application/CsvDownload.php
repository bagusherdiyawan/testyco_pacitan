<?php

namespace App\Shared\Application;

final class CsvDownload
{
    public static function response(string $filename, array $columns, array $rows): mixed
    {
        return response()->streamDownload(function () use ($columns, $rows) {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $columns, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($stream, array_map(fn ($value) => is_int($value) ? $value : CsvText::safe((string) $value), $row), ',', '"', '');
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
