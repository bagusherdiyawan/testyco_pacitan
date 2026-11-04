<?php

namespace App\Shared\Application;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PendingMutation
{
    public function __construct(private Backend $api) {}

    public function send(string $slot, array $pending, callable $ack, ?callable $target = null): mixed
    {
        session()->put($slot, $pending);
        try {
            $d = $this->api->request('POST', $pending['path'], $pending['body'])->json('data');
            if (! is_array($d) || ! $ack($d, $pending)) {
                throw new \UnexpectedValueException('ACK tidak cocok dengan review.');
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $known = $e instanceof ValidationException || ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 && ! in_array($e->getStatusCode(), [408, 429], true));
            if ($known && ! ($pending['uncertain'] ?? false)) {
                session()->forget($slot);
                throw $e;
            }$pending['uncertain'] = true;
            session()->put($slot, $pending);

            return redirect($pending['target'])->with('status', 'Hasil belum pasti. Ulangi request yang sama; jangan membuat pengganti.');
        }
        session()->forget($slot);

        return redirect($target ? $target($d) : $pending['target'])->with('status', 'Respons diterima. Tinjau riwayat terbaru; replay dapat historis.');
    }
}
