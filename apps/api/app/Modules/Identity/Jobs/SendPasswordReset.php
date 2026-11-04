<?php

namespace App\Modules\Identity\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

final class SendPasswordReset implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private string $email, private string $url) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        Mail::raw("Permintaan reset kata sandi Testyco. Abaikan jika bukan Anda. Tautan berlaku sesuai batas reset dan hanya dapat digunakan sekali:\n".$this->url, function ($m) {
            $m->to($this->email)->subject('Reset kata sandi Testyco');
        });
    }
}
