"""Local SMTP integration only: disposable in-memory database and fixture recipients.
No external SMTP credentials are used and no production email is sent.
"""
import os
import socketserver
import subprocess
import tempfile
import threading
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IMAGE = 'composer@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc'
gateway = subprocess.check_output(['docker', 'network', 'inspect', 'bridge', '--format', '{{(index .IPAM.Config 0).Gateway}}'], text=True).strip()
messages = []
class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b'220 fixture.local ESMTP\r\n')
        while True:
            line = self.rfile.readline(65536)
            if not line: return
            command = line.upper()
            if command.startswith((b'EHLO',b'HELO')): self.wfile.write(b'250-fixture.local\r\n250 8BITMIME\r\n')
            elif command.startswith(b'DATA'):
                self.wfile.write(b'354 End with dot\r\n')
                body = bytearray()
                while True:
                    part = self.rfile.readline(65536)
                    if part in (b'.\r\n', b''): break
                    body.extend(part)
                messages.append(bytes(body))
                self.wfile.write(b'250 accepted\r\n')
            elif command.startswith(b'QUIT'):
                self.wfile.write(b'221 bye\r\n'); return
            else: self.wfile.write(b'250 ok\r\n')
with socketserver.ThreadingTCPServer((gateway,0), SMTP) as server, tempfile.TemporaryDirectory(prefix='testyco-smtp-') as directory:
    threading.Thread(target=server.serve_forever, daemon=True).start()
    fixture=Path(directory)/'check.php'
    fixture.write_text(r'''<?php
require '/app/apps/api/vendor/autoload.php';
$app=require '/app/apps/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:',
'queue.default'=>'database','queue.connections.database.connection'=>'sqlite','queue.connections.database.after_commit'=>false,
'mail.default'=>'smtp','mail.mailers.smtp.host'=>getenv('FIXTURE_SMTP_HOST'),'mail.mailers.smtp.port'=>(int)getenv('FIXTURE_SMTP_PORT'),
'mail.mailers.smtp.username'=>null,'mail.mailers.smtp.password'=>null,'mail.mailers.smtp.url'=>null,'mail.mailers.smtp.scheme'=>'smtp',
'mail.mailers.smtp.timeout'=>5,'mail.from.address'=>'no-reply@fixture.invalid',
'security.email_reset_enabled'=>true,'security.public_web_url'=>'https://web.fixture.invalid','reliability.smtp_enabled'=>true]);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
$u=App\Models\User::create(['name'=>'SMTP Fixture','email'=>'recipient@fixture.invalid','password'=>Illuminate\Support\Str::random(32)]);
$org=App\Modules\Identity\Infrastructure\Organization::create(['name'=>'SMTP Fixture','type'=>'partner']);
Illuminate\Support\Facades\DB::table('organization_user')->insert(['organization_id'=>$org->id,'user_id'=>$u->id,'role'=>'partner_lead']);
app(App\Modules\Identity\Application\AccountSecurity::class)->forgot($u->email);
$job=Illuminate\Support\Facades\DB::table('jobs')->first();
if(!$job || str_contains($job->payload,$u->email) || str_contains($job->payload,'reset-password#token=')) throw new RuntimeException('Reset job must be encrypted');
Illuminate\Support\Facades\Artisan::call('queue:work',['--once'=>true,'--tries'=>1,'--timeout'=>15]);
if(Illuminate\Support\Facades\DB::table('jobs')->exists() || Illuminate\Support\Facades\DB::table('failed_jobs')->exists()) throw new RuntimeException('Reset email worker failed');
$inbox=app(App\Modules\Reliability\Application\Inbox::class);
$inbox->preferences($u,['version'=>0,'email_enabled'=>true]);
Illuminate\Support\Facades\DB::transaction(fn()=>$inbox->emit('smtp-test',$org->id,null,'food.recalled','PRIVATE-TRANSACTION-CONTENT','/app/organizations/'.$org->id));
$result=$inbox->deliver(1);
if($result!==['sent'=>1,'failed'=>0]) throw new RuntimeException('Notification delivery failed');
echo 'PASS: encrypted queued reset job and SMTP notification delivery'.PHP_EOL;
''')
    try:
        result=subprocess.run(['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}',
            '--add-host','smtp-fixture:host-gateway','-e','APP_ENV=testing','-e','APP_DEBUG=false',
            '-e','FIXTURE_SMTP_HOST=smtp-fixture','-e',f'FIXTURE_SMTP_PORT={server.server_address[1]}',
            '-v',f'{ROOT}:/app:ro','-v',directory+':/fixtures:ro','-w','/app/apps/api',IMAGE,'php','/fixtures/check.php'],
            text=True,capture_output=True,timeout=60)
        if result.returncode: raise RuntimeError('SMTP fixture failed: '+result.stdout+result.stderr)
        assert len(messages)==2, 'Expected reset and inbox email'
        from email import policy
        from email.parser import BytesParser
        decoded=[str(BytesParser(policy=policy.default).parsebytes(m).get_body(preferencelist=('plain',)).get_content()) for m in messages]
        assert any('/reset-password#token=' in m for m in decoded)
        assert all('PRIVATE-TRANSACTION-CONTENT' not in m for m in decoded)
        print(result.stdout.strip())
        print('PASS: two local SMTP messages; reset fragment link and generic notification verified; no external mail sent')
    finally: server.shutdown()
