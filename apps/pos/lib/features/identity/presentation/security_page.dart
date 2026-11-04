import 'package:flutter/material.dart';
import '../../../core/network/api_client.dart';
import '../../../design_system/molecules/password_field.dart';
import '../application/session.dart';

class SecurityPage extends StatefulWidget {
  const SecurityPage({required this.session, super.key});
  final Session session;
  @override
  State<SecurityPage> createState() => _SecurityPageState();
}

class _SecurityPageState extends State<SecurityPage> {
  final password = TextEditingController(), code = TextEditingController();
  Map<String, dynamic>? contextData, enrollment;
  List sessions = [];
  String? error;
  bool busy = false, saved = false;
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    password.dispose();
    code.dispose();
    super.dispose();
  }

  Future<void> load() async {
    if (busy) return;
    setState(() => busy = true);
    try {
      final api = widget.session.api;
      contextData =
          (await api.request('GET', 'auth/security'))['data']
              as Map<String, dynamic>;
      sessions = (await api.request('GET', 'auth/sessions'))['data'] as List;
    } catch (e) {
      error = '$e';
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> mutate(String action) async {
    if (busy) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final api = widget.session.api;
      if (action == 'enroll') {
        final d = (await api.request('POST', 'auth/mfa/enroll', {
          'current_password': password.text,
        }))['data'];
        if (d is! Map ||
            d['secret'] is! String ||
            d['version'] is! int ||
            d['recovery_codes'] is! List ||
            (d['recovery_codes'] as List).length != 10) {
          throw const ApiException('Enrollment tidak valid.');
        }
        enrollment = Map<String, dynamic>.from(d);
        password.clear();
      } else {
        await api.request(
          'POST',
          'auth/mfa/$action',
          action == 'enable'
              ? {'version': enrollment!['version'], 'code': code.text}
              : {'current_password': password.text, 'code': code.text},
        );
        widget.session.clear(
          message:
              'Keamanan akun diperbarui. Masuk kembali; seluruh sesi dicabut.',
        );
      }
    } catch (e) {
      final uncertain =
          e is! ApiException ||
          e.status == 0 ||
          e.status >= 500 ||
          e.status == 408 ||
          e.status == 429;
      if (action != 'enroll' && uncertain) {
        widget.session.clear(
          message:
              'Hasil perubahan MFA belum pasti. Masuk kembali dan periksa status; jangan mengulang otomatis.',
        );
      } else {
        error = '$e';
      }
    } finally {
      password.clear();
      code.clear();
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Keamanan dan sesi perangkat')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (busy) const LinearProgressIndicator(),
        if (error != null) Text(error!),
        const Text(
          'Perubahan MFA mencabut seluruh sesi. Recovery sekali pakai harus disimpan di tempat aman terpisah; rahasia hanya ditampilkan di halaman ini.',
        ),
        if (contextData case final c?) ...[
          Text(
            c['enabled'] == true
                ? 'Authenticator aktif'
                : 'Authenticator belum aktif',
          ),
          if (enrollment case final e?) ...[
            const Text('Authenticator: Testyco · SHA1 · 6 digit · 30 detik'),
            SelectableText('Secret ${e['secret']}'),
            for (final recovery in e['recovery_codes'])
              SelectableText('$recovery'),
            CheckboxListTile(
              value: saved,
              onChanged: busy ? null : (v) => setState(() => saved = v!),
              title: const Text(
                'Saya telah menyimpan kode recovery secara aman',
              ),
            ),
            TextField(
              controller: code,
              enabled: !busy,
              decoration: const InputDecoration(
                labelText: 'Kode authenticator saat ini',
              ),
              autofillHints: const [AutofillHints.oneTimeCode],
            ),
            FilledButton(
              onPressed: busy || !saved ? null : () => mutate('enable'),
              child: const Text('Aktifkan MFA dan cabut sesi'),
            ),
          ] else ...[
            PasswordField(
              label: 'Kata sandi saat ini',
              controller: password,
              enabled: !busy,
            ),
            if (c['enabled'] == true)
              TextField(
                controller: code,
                enabled: !busy,
                decoration: const InputDecoration(
                  labelText: 'Kode authenticator / recovery',
                ),
                autofillHints: const [AutofillHints.oneTimeCode],
              ),
            FilledButton(
              onPressed: busy
                  ? null
                  : () => mutate(c['enabled'] == true ? 'disable' : 'enroll'),
              child: Text(
                c['enabled'] == true
                    ? 'Nonaktifkan MFA dan cabut sesi'
                    : 'Siapkan authenticator',
              ),
            ),
          ],
          const Text('Sesi perangkat'),
          for (final s in sessions)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${s['name']} · ${s['is_current'] == true ? 'Sesi ini' : 'Sesi lain'}',
                    ),
                    Text(
                      'Berakhir ${s['expires_at']} · Terakhir ${s['last_used_at'] ?? 'Belum'}',
                    ),
                    TextButton(
                      onPressed: busy
                          ? null
                          : () async {
                              setState(() => busy = true);
                              try {
                                await widget.session.api.request(
                                  'POST',
                                  'auth/sessions/${s['id']}/revoke',
                                );
                                if (s['is_current'] == true) {
                                  widget.session.clear(
                                    message: 'Sesi perangkat dicabut.',
                                  );
                                  return;
                                }
                              } catch (e) {
                                error = '$e';
                              } finally {
                                if (mounted) setState(() => busy = false);
                              }
                              await load();
                            },
                      child: const Text('Cabut sesi'),
                    ),
                  ],
                ),
              ),
            ),
        ],
      ],
    ),
  );
}
