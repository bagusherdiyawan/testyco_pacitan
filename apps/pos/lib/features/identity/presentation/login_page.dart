import 'package:flutter/material.dart';
import '../../../design_system/molecules/password_field.dart';
import '../application/session.dart';

class LoginPage extends StatefulWidget {
  const LoginPage({required this.session, super.key});
  final Session session;
  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();
  bool _busy = false;
  String? _error;
  Future<void> _submit() async {
    if (_busy || !_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      if (widget.session.api.needsMfa) {
        await widget.session.completeMfa(_code.text);
      } else {
        await widget.session.login(_email.text, _password.text);
      }
    } catch (error) {
      if (widget.session.api.needsMfa) _password.clear();
      if (mounted) setState(() => _error = error.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  void dispose() {
    _code.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    body: Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 460),
          child: AutofillGroup(
            child: Form(
              key: _form,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Testyco POS',
                    style: Theme.of(context).textTheme.headlineLarge,
                  ),
                  const SizedBox(height: 16),
                  const Text('Masuk untuk membuka toko yang ditugaskan.'),
                  if (widget.session.notice != null)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 16),
                      child: Text(widget.session.notice!),
                    ),
                  const SizedBox(height: 24),
                  TextFormField(
                    controller: _email,
                    enabled: !_busy && !widget.session.api.needsMfa,
                    decoration: const InputDecoration(labelText: 'Email'),
                    keyboardType: TextInputType.emailAddress,
                    autofillHints: const [AutofillHints.username],
                    validator: (v) => v != null && v.contains('@')
                        ? null
                        : 'Masukkan email yang valid.',
                  ),
                  const SizedBox(height: 16),
                  PasswordField(
                    label: 'Kata sandi',
                    controller: _password,
                    enabled: !_busy && !widget.session.api.needsMfa,
                    autofillHints: const [AutofillHints.password],
                    action: TextInputAction.done,
                    onSubmitted: (_) => _submit(),
                    validator: (v) =>
                        !widget.session.api.needsMfa && (v == null || v.isEmpty)
                        ? 'Kata sandi wajib diisi.'
                        : null,
                  ),
                  if (widget.session.api.needsMfa)
                    TextFormField(
                      controller: _code,
                      enabled: !_busy,
                      decoration: const InputDecoration(
                        labelText: 'Kode authenticator / recovery',
                      ),
                      autofillHints: const [AutofillHints.oneTimeCode],
                      validator: (v) =>
                          v == null || v.isEmpty ? 'Isi kode MFA.' : null,
                    ),
                  if (_error != null)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 16),
                      child: Text(
                        _error!,
                        semanticsLabel: 'Gagal masuk: $_error',
                      ),
                    ),
                  const SizedBox(height: 24),
                  FilledButton(
                    onPressed: _busy ? null : _submit,
                    child: Text(
                      _busy
                          ? 'Memproses…'
                          : widget.session.api.needsMfa
                          ? 'Verifikasi MFA'
                          : 'Masuk',
                    ),
                  ),
                  const SizedBox(height: 16),
                  if (widget.session.api.needsMfa)
                    TextButton(
                      onPressed: _busy
                          ? null
                          : () => setState(() {
                              widget.session.api.resetMfaChallenge();
                              _code.clear();
                              _error = null;
                            }),
                      child: const Text('Mulai login baru'),
                    ),
                  Text(
                    widget.session.deviceState == null
                        ? 'Sesi disimpan di memori. Koneksi internet diperlukan.'
                        : 'Sesi dan antrean perangkat terenkripsi. Offline hanya tunai dalam izin tersimpan; sinkronisasi memerlukan login yang valid.',
                  ),
                  TextButton(
                    onPressed: _busy
                        ? null
                        : () async {
                            try {
                              await widget.session.api.request(
                                'POST',
                                'auth/password/forgot',
                                {'email': _email.text.trim()},
                              );
                              if (mounted) {
                                setState(
                                  () => _error =
                                      'Jika akun memenuhi syarat, instruksi pemulihan dikirim.',
                                );
                              }
                            } catch (e) {
                              if (mounted) {
                                setState(
                                  () => _error =
                                      'Layanan email pemulihan belum tersedia atau percobaan dibatasi.',
                                );
                              }
                            }
                          },
                    child: const Text('Kirim email pemulihan kata sandi'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    ),
  );
}
