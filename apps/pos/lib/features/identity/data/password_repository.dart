import '../../../core/network/api_client.dart';

class PasswordRepository {
  const PasswordRepository(this.api);
  final ApiClient api;
  Future<void> change(
    String current,
    String password,
    String confirmation,
  ) async {
    await api.request('POST', 'auth/password', {
      'current_password': current,
      'password': password,
      'password_confirmation': confirmation,
    });
  }
}
