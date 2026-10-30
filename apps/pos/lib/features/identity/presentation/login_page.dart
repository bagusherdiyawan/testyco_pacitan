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
  bool _busy = false;
  String? _error;
  Future<void> _submit() async {
    if (_busy || !_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.session.login(_email.text, _password.text);
    } catch (error) {
      if (mounted) setState(() => _error = error.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  void dispose() {
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
                    enabled: !_busy,
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
                    enabled: !_busy,
                    autofillHints: const [AutofillHints.password],
                    action: TextInputAction.done,
                    onSubmitted: (_) => _submit(),
                    validator: (v) => v == null || v.isEmpty
                        ? 'Kata sandi wajib diisi.'
                        : null,
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
                    child: Text(_busy ? 'Memproses…' : 'Masuk'),
                  ),
                  const SizedBox(height: 16),
                  const Text(
                    'Sesi disimpan di memori dan berakhir setelah aplikasi ditutup. Koneksi internet diperlukan.',
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
