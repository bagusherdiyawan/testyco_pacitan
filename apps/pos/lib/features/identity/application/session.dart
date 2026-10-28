import 'package:flutter/foundation.dart';
import '../../../core/network/api_client.dart';

class Session extends ChangeNotifier {
  Session(this.api) {
    api.onUnauthorized = clear;
  }
  final ApiClient api;
  String? name;
  bool get authenticated => api.authenticated && name != null;
  Future<void> login(String email, String password) async {
    name = await api.login(email, password);
    notifyListeners();
  }

  Future<void> logout() async {
    try {
      await api.logout();
    } finally {
      clear();
    }
  }

  void clear() {
    name = null;
    api.clearToken();
    notifyListeners();
  }

  @override
  void dispose() {
    api.onUnauthorized = null;
    api.close();
    super.dispose();
  }
}
