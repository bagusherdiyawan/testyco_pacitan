<?php

namespace App\Modules\Identity\Infrastructure;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class Backend
{
    public function all(string $path): array
    {
        $items = [];
        $page = 1;
        do {
            $result = $this->request('GET', $path, $page > 1 ? ['page' => $page] : [])->json();
            $items = array_merge($items, $result['data']);
            $page++;
        } while ($page <= ($result['last_page'] ?? 1));

        return $items;
    }

    public function page(string $path, string $parameter = 'page'): LengthAwarePaginator
    {
        $page = max(1, (int) request()->query($parameter, 1));
        $result = $this->request('GET', $path, $page > 1 ? ['page' => $page] : [])->json();

        return new LengthAwarePaginator($result['data'], $result['total'] ?? count($result['data']), $result['per_page'] ?? max(1, count($result['data'])), $result['current_page'] ?? $page, ['path' => url()->current(), 'pageName' => $parameter, 'query' => request()->query()]);
    }

    public function request(string $method, string $path, array $data = []): Response
    {
        try {
            $response = Http::timeout(8)->acceptJson()->withToken(session('api_token', ''))->send($method, rtrim(config('backend.url'), '/').'/api/v1/'.$path, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException $e) {
            abort(503, 'API belum tersedia. Silakan coba kembali.');
        }
        if ($response->status() === 401) {
            session()->invalidate();
            session()->regenerateToken();
            throw new HttpResponseException(redirect('/login')->withErrors(['email' => 'Sesi berakhir. Silakan masuk kembali.']));
        }
        if ($response->status() === 422) {
            throw ValidationException::withMessages($response->json('errors') ?: ['form' => $response->json('message') ?: 'Data tidak valid.']);
        }
        abort_unless($response->successful(), $response->status() >= 500 ? 503 : $response->status(), $response->status() >= 500 ? 'Layanan API sedang tidak tersedia.' : ($response->json('message') ?: 'Permintaan tidak dapat diproses.'));

        return $response;
    }
}
