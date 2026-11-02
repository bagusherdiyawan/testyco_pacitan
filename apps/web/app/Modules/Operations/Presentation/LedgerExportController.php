<?php

namespace App\Modules\Operations\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\CsvDownload;
use App\Shared\Application\ExportTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class LedgerExportController
{
    public function __construct(private Backend $api) {}

    public function __invoke(Request $r, string $store): mixed
    {
        [$query,$filters] = LedgerSearch::parse($r, true);
        $response = $this->api->request('GET', "stores/$store/inventory/ledger/export", $query);
        abort_if(strlen($response->body()) > 9 * 1024 * 1024, 503, 'Payload ekspor stok API terlalu besar.');
        $data = $response->json();
        abort_unless(is_array($data) && data_get($data, 'store.id') === $store && is_array($data['data'] ?? null)
            && is_bool(data_get($data, 'store.active')) && count($data['data']) <= 10000 && ($data['row_count'] ?? null) === count($data['data']), 503, 'Cakupan ekspor stok API tidak valid.');
        $rows = [];
        $ids = [];
        foreach ($data['data'] as $entry) {
            abort_unless(is_array($entry) && ($entry['store_id'] ?? null) === $store && is_string($entry['id'] ?? null) && $entry['id'] !== '' && ! isset($ids[$entry['id']])
                && is_string($entry['inventory_batch_id'] ?? null) && $entry['inventory_batch_id'] !== ''
                && (! isset($filters['batch_id']) || $entry['inventory_batch_id'] === $filters['batch_id'])
                && is_int($entry['quantity_pieces'] ?? null) && is_int($entry['pack_size'] ?? null) && $entry['pack_size'] > 0
                && is_int($entry['actor_id'] ?? null) && ExportTime::valid($entry['occurred_at'] ?? null)
                && array_key_exists('note', $entry) && (is_string($entry['note']) || $entry['note'] === null), 503, 'Baris ekspor stok API tidak valid.');
            foreach (['sku', 'name', 'batch_code', 'condition', 'expires_on', 'source_type', 'source_id', 'bucket'] as $key) {
                abort_unless(is_string($entry[$key] ?? null), 503, 'Snapshot ekspor stok API tidak valid.');
            }
            $ids[$entry['id']] = true;
            $rows[] = [$entry['id'], $store, $entry['inventory_batch_id'], $entry['sku'], $entry['name'], $entry['batch_code'], $entry['condition'], $entry['pack_size'], $entry['expires_on'],
                CarbonImmutable::parse($entry['occurred_at'])->utc()->toIso8601String(), $entry['actor_id'], $entry['source_type'], $entry['source_id'], $entry['bucket'], $entry['quantity_pieces'], $entry['note'] ?? ''];
        }

        return CsvDownload::response('stock-ledger.csv', ['entry_id', 'store_id', 'batch_id', 'sku', 'name', 'batch_code', 'condition', 'pack_size', 'expires_on', 'occurred_at_utc', 'actor_id', 'source_type', 'source_id', 'bucket', 'quantity_pieces', 'note'], $rows);
    }
}
