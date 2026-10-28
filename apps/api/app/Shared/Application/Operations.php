<?php

namespace App\Shared\Application;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Operations
{
    public function run(string $organization, int $actor, string $id, string $scope, array $payload, Closure $work): array
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $canonical($item);
                }
            }

            return $value;
        };
        $hash = hash('sha256', json_encode($canonical($payload), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($organization, $actor, $id, $scope, $hash, $work) {
            DB::table('operation_receipts')->insertOrIgnore(['id' => (string) Str::ulid(), 'organization_id' => $organization, 'actor_id' => $actor, 'operation_id' => $id, 'scope' => $scope, 'payload_hash' => $hash, 'created_at' => now()]);
            $receipt = DB::table('operation_receipts')->where('organization_id', $organization)->where('operation_id', $id)->lockForUpdate()->first();
            abort_unless($receipt && (int) $receipt->actor_id === $actor && $receipt->scope === $scope && hash_equals($receipt->payload_hash, $hash), 409, 'ID operasi sudah dipakai untuk permintaan berbeda.');
            if ($receipt->result !== null) {
                return json_decode($receipt->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $work();
            DB::table('operation_receipts')->where('id', $receipt->id)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR)]);

            return $result;
        }, 3);
    }
}
