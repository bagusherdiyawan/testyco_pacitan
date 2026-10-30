import 'package:flutter/material.dart';
import '../../../design_system/molecules/password_field.dart';
import '../application/password_change_controller.dart';
import '../application/session.dart';

class PasswordChangePage extends StatefulWidget {
  const PasswordChangePage({required this.session, super.key});
  final Session session;
  @override
  State<PasswordChangePage> createState() => _PasswordChangePageState();
}

class _PasswordChangePageState extends State<PasswordChangePage> {
  late final PasswordChangeController model;
  final current = TextEditingController(),
      password = TextEditingController(),
      confirmation = TextEditingController();
  @override
  void initState() {
    super.initState();
    model = PasswordChangeController(widget.session);
  }

  @override
  void dispose() {
    model.dispose();
    for (final c in [current, password, confirmation]) {
      c.clear();
      c.dispose();
    }
    super.dispose();
  }

  void submit() {
    FocusScope.of(context).unfocus();
    model.submit(current.text, password.text, confirmation.text);
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) => PopScope(
      canPop: !model.busy,
      child: Scaffold(
        appBar: AppBar(title: const Text('Ganti kata sandi')),
        body: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 520),
              child: AutofillGroup(
                onDisposeAction: AutofillContextAction.cancel,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Text(
                      'Sandi baru minimal 12 karakter, dengan huruf besar, huruf kecil dan angka. Aturan diperiksa oleh server.',
                    ),
                    const SizedBox(height: 16),
                    const Text(
                      'Setelah berhasil, seluruh sesi akun pada perangkat lain ikut keluar. Selesaikan transaksi dan operasi yang hasilnya belum pasti sebelum melanjutkan. Draft transaksi di server tetap tersimpan.',
                    ),
                    const SizedBox(height: 24),
                    PasswordField(
                      label: 'Kata sandi saat ini',
                      controller: current,
                      enabled: !model.busy,
                      autofillHints: const [AutofillHints.password],
                    ),
                    const SizedBox(height: 16),
                    PasswordField(
                      label: 'Kata sandi baru',
                      controller: password,
                      enabled: !model.busy,
                      autofillHints: const [AutofillHints.newPassword],
                    ),
                    const SizedBox(height: 16),
                    PasswordField(
                      label: 'Ulangi kata sandi baru',
                      controller: confirmation,
                      enabled: !model.busy,
                      autofillHints: const [AutofillHints.newPassword],
                      action: TextInputAction.done,
                      onSubmitted: (_) => submit(),
                    ),
                    if (model.error != null)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 16),
                        child: Text(
                          model.error!,
                          semanticsLabel: 'Perubahan gagal: ${model.error}',
                        ),
                      ),
                    const SizedBox(height: 24),
                    FilledButton(
                      onPressed: model.busy ? null : submit,
                      child: Text(
                        model.busy
                            ? 'Mengubah kata sandi…'
                            : 'Ubah dan keluar dari seluruh sesi',
                      ),
                    ),
                    if (model.busy)
                      const Padding(
                        padding: EdgeInsets.all(12),
                        child: LinearProgressIndicator(),
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    ),
  );
}

class PasswordChangeAction extends StatelessWidget {
  const PasswordChangeAction({required this.session, super.key});
  final Session session;
  @override
  Widget build(BuildContext context) => IconButton(
    tooltip: 'Ganti kata sandi',
    icon: const Icon(Icons.password),
    onPressed: () => Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => PasswordChangePage(session: session),
      ),
    ),
  );
}
