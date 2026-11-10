<?php

use App\Models\User;
use App\Modules\Accounting\Application\AutomaticPosting;
use App\Modules\Accounting\Application\InvoiceValuation;
use App\Modules\Commercial\CommercialServiceProvider;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('identity:bootstrap {--name=} {--email=}', function () {
    if (Organization::where('type', 'center')->exists()) {
        $this->error('Pusat sudah tersedia; tidak ada perubahan.');

        return 1;
    }
    $name = $this->option('name') ?: $this->ask('Nama pemilik');
    $email = strtolower($this->option('email') ?: $this->ask('Email pemilik'));
    $password = $this->secret('Kata sandi (minimal 12 karakter, huruf besar/kecil dan angka)');
    $confirmation = $this->secret('Ulangi kata sandi');
    $v = Validator::make(compact('name', 'email', 'password') + ['password_confirmation' => $confirmation], ['name' => 'required|string|max:120', 'email' => 'required|email|max:255|unique:users,email', 'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()]]);
    if ($v->fails()) {
        foreach ($v->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }
    DB::transaction(function () use ($name, $email, $password) {
        $org = Organization::create(['name' => 'Testyco Pusat', 'type' => 'center']);
        $user = User::create(compact('name', 'email', 'password'));
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner']);
        Audit::record($user->id, $org->id, 'identity.bootstrapped', $org->id, null, ['name' => $org->name]);
    });
    $this->info('Pemilik pusat dibuat. Masuk melalui web atau aplikasi POS.');

    return 0;
})->purpose('Membuat pemilik awal; tidak menyediakan password default');

Artisan::command('accounting:post {--limit=100}', function () {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
    if (! $limit || $limit < 1 || $limit > 1000) {
        $this->error('Limit1–1000.');

        return 1;
    }
    $done = app(AutomaticPosting::class)->drain($limit);
    $this->info('Jurnal dibukukan: '.$done);

    return 0;
})->purpose('Posting antrean sumber sekali, memakai pemetaan yang disetujui');
Artisan::command('accounting:revalue {--limit=100}', function () {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
    if (! $limit || $limit < 1 || $limit > 1000) {
        $this->error('Limit1–1000.');

        return 1;
    }
    $done = app(InvoiceValuation::class)->drain($limit);
    $this->info('Penyesuaian diterapkan: '.$done);

    return 0;
})->purpose('Memproses ulang antrean biaya invoice tanpa menimpa histori');
Schedule::command('accounting:revalue --limit=100')->everyMinute()->withoutOverlapping();
Schedule::command('accounting:post --limit=100')->everyMinute()->withoutOverlapping();

Schedule::command('reliability:deliver --limit=100')->everyMinute()->withoutOverlapping()->when(fn () => config('reliability.smtp_enabled') && config('mail.default') === 'smtp');

Schedule::command('commercial:accrue --limit=100')->everyMinute()->withoutOverlapping()->when(fn () => app()->getProvider(CommercialServiceProvider::class));
