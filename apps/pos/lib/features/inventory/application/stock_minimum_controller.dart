import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../../../core/network/api_client.dart';
import '../data/inventory_repository.dart';
import '../domain/stock_minimum.dart';

class MinimumChange {
  MinimumChange(this.path, Map<String, dynamic> body)
    : _encoded = jsonEncode(body);
  final String path, _encoded;
  Map<String, dynamic> get body => jsonDecode(_encoded) as Map<String, dynamic>;
}

class StockMinimumController extends ChangeNotifier {
  StockMinimumController(this.repository, this.storeId, this.productId);
  final InventoryRepository repository;
  final String storeId, productId;
  StockMinimumDetail? detail;
  MinimumChange? pending;
  bool busy = false, fresh = false, saved = false, _disposed = false;
  String? error;
  int formEpoch = 0;
  bool get editable =>
      !busy &&
      fresh &&
      pending == null &&
      detail?.canManage == true &&
      detail!.row.version != null &&
      detail!.row.version! >= 0 &&
      detail!.row.version! <= 1000000000;
  String get path => 'stores/$storeId/inventory/minimums/$productId';
  void _notify() {
    if (!_disposed) notifyListeners();
  }

  Future<void> _read() async {
    final next = await repository.minimumDetail(storeId, productId);
    if (next.storeId != storeId || next.row.productId != productId) {
      throw const ApiException('SKU bukan milik toko yang dipilih.');
    }
    detail = next;
    fresh = true;
    formEpoch++;
  }

  Future<void> load() async {
    if (busy || pending != null) return;
    busy = true;
    fresh = false;
    error = null;
    _notify();
    try {
      await _read();
    } catch (e) {
      error = '$e';
    } finally {
      busy = false;
      _notify();
    }
  }

  MinimumChange prepare(bool enabled, String text, String reason) {
    if (!editable) {
      throw const ApiException(
        'Muat ulang konfigurasi dan periksa izin sebelum mengubah ambang.',
      );
    }
    final minimum = RegExp(r'^[0-9]+$').hasMatch(text.trim())
        ? int.tryParse(text.trim())
        : null;
    if (enabled && (minimum == null || minimum < 0 || minimum > 1000000000)) {
      throw const ApiException('Isi ambang biji bulat 0 sampai 1.000.000.000.');
    }
    if (reason.trim().isEmpty || reason.trim().length > 2000) {
      throw const ApiException('Alasan wajib diisi, maksimal 2000 karakter.');
    }
    return MinimumChange(path, {
      'operation_id': const Uuid().v4(),
      'version': detail!.row.version,
      'minimum_pieces': enabled ? minimum : null,
      'reason': reason.trim(),
    });
  }

  Future<void> confirm(MinimumChange draft) async {
    if (!editable ||
        draft.path != path ||
        draft.body['version'] != detail!.row.version) {
      return;
    }
    pending = draft;
    await _send(wasUnknown: false);
  }

  Future<void> retry() async {
    if (busy || pending == null) return;
    await _send(wasUnknown: true);
  }

  Future<void> _send({required bool wasUnknown}) async {
    busy = true;
    fresh = false;
    saved = false;
    error = null;
    _notify();
    try {
      await repository.configureMinimum(pending!.path, pending!.body);
      saved = true;
      pending = null;
      // Replays return the original configuration, not necessarily the latest version.
      await _read();
    } catch (e) {
      final uncertain =
          e is! ApiException ||
          e.status == 0 ||
          e.status >= 500 ||
          e.status == 408 ||
          e.status == 429;
      if (!wasUnknown && !uncertain) pending = null;
      error = saved
          ? 'Ambang tersimpan. Detail terbaru gagal dimuat; muat ulang sebelum mengubah lagi. $e'
          : '$e';
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
