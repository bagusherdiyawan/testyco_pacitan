import 'package:flutter/foundation.dart';
import 'dart:async';
import '../../../core/storage/device_state.dart';
import '../../../core/network/api_client.dart';

class Session extends ChangeNotifier {
  Session(this.api, {this.deviceState}) {
    api.onUnauthorized = clear;
  }
  final ApiClient api;
  final DeviceState? deviceState;
  bool offlineOnly = false;
  String get _key => 'session:${api.baseUrl}';
  Future<void> save() async {
    final data = api.savedSession;
    if (data == null) return;
    try {
      await deviceState?.write(_key, data);
    } catch (_) {
      api.clearToken();
      name = null;
      notifyListeners();
      rethrow;
    }
  }

  Future<void> restore() async {
    final value = await deviceState?.read(_key);
    if (value == null || !api.restoreSession(value)) {
      await deviceState?.delete(_key);
      return;
    }
    name = api.identity!['name'];
    try {
      final current = await api.request('GET', 'auth/me');
      if (current['data'] is! Map ||
          current['data']['id'] != api.identity!['id']) {
        throw const ApiException('Identitas sesi tidak cocok.');
      }
      api.identity = Map<String, dynamic>.from(current['data']);
      offlineOnly = false;
    } catch (e) {
      if (e is ApiException && (e.status == 0 || e.status >= 500)) {
        offlineOnly = true;
        notice =
            'Koneksi belum tersedia. Hanya transaksi offline dalam izin tersimpan; sesi belum diverifikasi ulang.';
      } else {
        clear();
      }
    }
    notifyListeners();
  }

  Future<void> completeMfa(String code) async {
    name = await api.completeMfa(code);
    offlineOnly = false;
    notice = null;
    await save();
    notifyListeners();
  }

  String? name;
  String? notice;
  bool get authenticated => api.authenticated && name != null;
  Future<void> login(String email, String password) async {
    name = null;
    api.clearToken();
    await deviceState?.delete(_key);
    name = await api.login(email, password);
    offlineOnly = false;
    await save();
    notice = null;
    notifyListeners();
  }

  Future<void> logout() async {
    try {
      await api.logout();
    } finally {
      try {
        await deviceState?.delete(_key);
      } finally {
        clear();
      }
    }
  }

  void clear({String? message}) {
    notice = message;
    name = null;
    offlineOnly = false;
    if (deviceState != null) {
      unawaited(
        deviceState!.delete(_key).catchError((Object e) {
          notice = 'Penyimpanan sesi lokal perlu diperiksa.';
        }),
      );
    }
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
