<?php

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class UserReactivationController
{
    public function __construct(private Backend $api) {}

    private function slot(string $user): string
    {
        return 'user_reactivation_pending.'.(int) $user;
    }

    private function context(string $org, string $user): array
    {
        $data = $this->api->request('GET', "organizations/$org/users/$user/reactivation")->json('data');
        abort_unless(is_array($data)
            && data_get($data, 'organization.id') === $org
            && is_string(data_get($data, 'organization.name'))
            && is_bool(data_get($data, 'organization.active'))
            && is_int(data_get($data, 'user.id')) && (string) data_get($data, 'user.id') === $user
            && is_string(data_get($data, 'user.name')) && is_string(data_get($data, 'user.email'))
            && is_string(data_get($data, 'user.role'))
            && is_int(data_get($data, 'user.access_version')) && data_get($data, 'user.access_version') > 0
            && is_bool($data['can_reactivate'] ?? null) && is_bool(data_get($data, 'user.active'))
            && is_int(data_get($data, 'user.membership_count')) && data_get($data, 'user.membership_count') >= 1
            && is_array(data_get($data, 'user.stores')), 503, 'Cakupan tinjauan akun API tidak valid.');
        foreach ($data['user']['stores'] as $store) {
            abort_unless(is_array($store) && ($store['organization_id'] ?? null) === $org && is_string($store['id'] ?? null) && is_string($store['name'] ?? null) && is_bool($store['active'] ?? null), 503, 'Penugasan bukan milik organisasi yang ditinjau.');
        }
        abort_unless(count(array_unique(array_column($data['user']['stores'], 'id'))) === count($data['user']['stores']), 503, 'Penugasan API duplikat.');

        return $data;
    }

    public function show(string $organization, string $user): mixed
    {
        $user = (string) (int) $user;
        $pending = session($this->slot($user));
        if ($pending !== null && data_get($pending, 'review.organization.id') !== $organization) {
            return redirect('/app/organizations/'.data_get($pending, 'review.organization.id').'/users/'.$user.'/reactivation');
        }
        $data = $pending === null ? $this->context($organization, $user) : null;

        return response()->view('pages.user-reactivation', compact('organization', 'user', 'pending', 'data'))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $r, string $organization, string $user): mixed
    {
        $user = (string) (int) $user;
        abort_if(session()->has($this->slot($user)), 409, 'Selesaikan request yang hasilnya belum pasti terlebih dahulu.');
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000']);
        $data = $this->context($organization, $user);
        abort_unless($data['can_reactivate'] && $data['user']['access_version'] === (int) $v['version'], 409, 'Status atau penugasan akun berubah. Muat ulang tinjauan.');

        return $this->send($organization, $user, ['path' => "organizations/$organization/users/$user/reactivate", 'body' => $v, 'review' => $data, 'uncertain' => false]);
    }

    public function retry(string $organization, string $user): mixed
    {
        $user = (string) (int) $user;
        $pending = session($this->slot($user));
        abort_unless(is_array($pending) && data_get($pending, 'review.organization.id') === $organization, 409, 'Tidak ada request pada organisasi ini yang perlu diulang.');

        return $this->send($organization, $user, $pending);
    }

    private function send(string $org, string $user, array $pending): mixed
    {
        session()->put($this->slot($user), $pending);
        try {
            $result = $this->api->request('POST', $pending['path'], $pending['body'])->json('data');
            if (! is_array($result) || ($result['organization_id'] ?? null) !== $org || (string) data_get($result, 'user.id') !== $user || data_get($result, 'user.active') !== true || data_get($result, 'user.access_version') !== ((int) $pending['body']['version'] + 1)) {
                throw new \UnexpectedValueException('ACK tidak valid.');
            }
        } catch (HttpResponseException $e) {
            // 401 already invalidated the session. Do not restore prior-account state.
            throw $e;
        } catch (\Throwable $e) {
            $known = $e instanceof ValidationException || ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 && ! in_array($e->getStatusCode(), [408, 429], true));
            if ($known && ! $pending['uncertain']) {
                session()->forget($this->slot($user));
                throw $e;
            }
            $pending['uncertain'] = true;
            session()->put($this->slot($user), $pending);

            return redirect('/app/organizations/'.$org.'/users/'.$user.'/reactivation')->with('status', 'Hasil reaktivasi belum pasti. Ulangi request yang sama; jangan buat pengganti.');
        }
        session()->forget($this->slot($user));

        return redirect('/app/organizations/'.$org.'/users/'.$user.'/reactivation')->with('status', 'Respons reaktivasi diterima. Periksa status terbaru; hasil replay dapat historis.');
    }
}
