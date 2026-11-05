import 'dart:convert';
import 'admin_acknowledgement.dart';
import 'package:uuid/uuid.dart';
import '../../../core/network/api_client.dart';

/// Page-memory intent. Unknown results retain the exact request and actor.
class AdminMutation {
  AdminMutation(this.api, this.path, Map<String, dynamic> values, this.accept)
    : actor = api.identity?['id'],
      frozen = jsonEncode({...values, 'operation_id': const Uuid().v4()});
  final ApiClient api;
  final String path, frozen;
  final Object? actor;
  final bool Function(Map<String, dynamic>) accept;
  bool uncertain = false;
  Future<void> send() async {
    if (!api.authenticated || api.identity?['id'] != actor) {
      throw const ApiException(
        'Akun berubah. Masuk kembali dan periksa riwayat.',
        status: 401,
      );
    }
    try {
      final result = await api.request(
        'POST',
        path,
        Map<String, dynamic>.from(jsonDecode(frozen)),
      );
      if (!accept(result) ||
          !adminAcknowledgement(
            path,
            Map<String, dynamic>.from(jsonDecode(frozen)),
            result,
          )) {
        throw const FormatException('Konfirmasi server tidak cocok.');
      }
    } on ApiException catch (e) {
      if (e.status == 0 || e.status >= 500 || [408, 429].contains(e.status)) {
        uncertain = true;
      }
      rethrow;
    } catch (_) {
      uncertain = true;
      rethrow;
    }
  }
}

bool documentAck(
  Map<String, dynamic> response, {
  required String scopeKey,
  required String scope,
  String? id,
  int? version,
  String? status,
}) {
  final row = response['data'];
  return row is Map &&
      row['id'] is String &&
      row[scopeKey] == scope &&
      (id == null || row['id'] == id) &&
      row['version'] is int &&
      (version == null || row['version'] == version) &&
      (status == null || row['status'] == status);
}
