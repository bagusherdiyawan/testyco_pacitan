import 'package:flutter/foundation.dart';
import '../../../core/network/api_client.dart';
import '../data/password_repository.dart';
import 'session.dart';

class PasswordChangeController extends ChangeNotifier {
  PasswordChangeController(this.session)
    : repository = PasswordRepository(session.api);
  final Session session;
  final PasswordRepository repository;
  bool busy = false, _disposed = false;
  String? error;
  void _notify() {
    if (!_disposed) notifyListeners();
  }

  Future<void> submit(
    String current,
    String password,
    String confirmation,
  ) async {
    if (busy || !session.authenticated) return;
    error = null;
    if (current.isEmpty ||
        password.runes.length < 12 ||
        password != confirmation) {
      error =
          'Isi sandi saat ini, sandi baru minimal 12 karakter, dan konfirmasi yang sama.';
      _notify();
      return;
    }
    busy = true;
    _notify();
    try {
      await repository.change(current, password, confirmation);
      session.clear(
        message:
            'Kata sandi berhasil diubah. Seluruh sesi dicabut. Silakan masuk kembali.',
      );
    } catch (e) {
      if (e is ApiException && [400, 403, 422, 429].contains(e.status)) {
        error = switch (e.status) {
          403 => 'Perubahan kata sandi tidak diizinkan untuk sesi ini.',
          429 => 'Terlalu banyak permintaan. Tunggu sebelum mencoba kembali.',
          _ =>
            'Periksa sandi saat ini dan aturan sandi baru. Perubahan belum diterima.',
        };
      } else if (e is ApiException && e.status == 401) {
        session.clear(
          message: 'Sesi berakhir. Masuk kembali sebelum mengganti kata sandi.',
        );
      } else {
        session.clear(
          message:
              'Hasil perubahan kata sandi belum dapat dipastikan. Masuk kembali untuk memeriksa akun. Perubahan tidak diulang otomatis.',
        );
      }
    } finally {
      busy = false;
      _notify();
    }
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}
