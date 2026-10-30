import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../../../core/network/api_client.dart';
import '../data/warehouse_repository.dart';
import '../domain/warehouse_context.dart';

class WarehouseController extends ChangeNotifier {
  WarehouseController(this.repository, this.store, this.orderId);
  final WarehouseRepository repository;
  final String store, orderId;
  WarehouseContext? context;
  WarehouseOperation? pending;
  final quantities = <String, String>{};
  bool busy = false, fresh = false, saved = false, _disposed = false;
  String? error;
  int epoch = 0;
  bool get editable => !busy && fresh && pending == null;
  String key(Map<String, dynamic> line, Map<String, dynamic> batch) =>
      '${line['id']}:${batch['id']}';
  void _notify() {
    if (!_disposed) notifyListeners();
  }

  Future<void> _read() async {
    final next = await repository.context(store, orderId);
    if (next.id != orderId || next.source['id'] != store) {
      throw const ApiException('Pesanan atau gudang tidak sesuai.');
    }
    context = next;
    fresh = true;
    quantities.clear();
    epoch++;
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

  void quantity(String key, String text) {
    if (!editable) return;
    quantities[key] = text;
  }

  Map<String, dynamic> _review(String title) => {
    'title': title,
    'order': orderId,
    'source': context!.source['name'],
    'destination': context!.order['destination_name'],
    'mode': context!.order['delivery_mode'],
    'version': context!.version,
  };
  WarehouseOperation process() {
    if (!editable || context?.canProcess != true) {
      throw const ApiException(
        'Pemilihan gudang tidak diizinkan. Muat ulang pesanan.',
      );
    }
    return WarehouseOperation('orders/$orderId/process', {
      'operation_id': const Uuid().v4(),
      'version': context!.version,
      'source_store_id': store,
    }, _review('Gunakan gudang sumber'));
  }

  WarehouseOperation dispatch(String handler, String note) {
    if (!editable || context?.canDispatch != true) {
      throw const ApiException(
        'Pengiriman tidak diizinkan. Muat ulang pesanan.',
      );
    }
    if (handler.trim().isEmpty ||
        handler.trim().length > 120 ||
        note.trim().length > 2000) {
      throw const ApiException(
        'Isi petugas (maksimal 120 karakter) dan catatan maksimal 2000 karakter.',
      );
    }
    final lines = <Map<String, dynamic>>[], review = <Map<String, dynamic>>[];
    final used = <String, int>{};
    for (final line in context!.lines) {
      final allocations = <Map<String, dynamic>>[];
      var total = 0;
      for (final batch in context!.choices(line)) {
        final text = (quantities[key(line, batch)] ?? '').trim();
        if (text.isEmpty) continue;
        final count = RegExp(r'^[0-9]+$').hasMatch(text)
            ? int.tryParse(text)
            : null;
        if (count == null || count < 0 || count > 10000) {
          throw const ApiException(
            'Isi kemasan bulat 0–10000; kosong/0 berarti tidak dipilih.',
          );
        }
        if (count == 0) continue;
        final id = batch['id'] as String, size = line['pack_size'] as int;
        used[id] = (used[id] ?? 0) + count * size;
        if (used[id]! > (batch['usable_pieces'] as int)) {
          throw const ApiException(
            'Jumlah melampaui stok utuh batch yang ditinjau.',
          );
        }
        total += count;
        allocations.add({'batch_id': id, 'quantity_packs': count});
        review.add({
          'sku': line['sku'],
          'batch': batch['batch_code'],
          'expiry': batch['expires_on'],
          'packs': count,
          'size': size,
        });
      }
      if (allocations.length > 100) {
        throw const ApiException(
          'Maksimal 100 batch per item dalam satu kiriman.',
        );
      }
      if (total > (line['remaining_packs'] as int? ?? 0)) {
        throw const ApiException('Jumlah kirim melampaui sisa pesanan.');
      }
      if (allocations.isNotEmpty) {
        lines.add({'order_line_id': line['id'], 'allocations': allocations});
      }
    }
    if (lines.isEmpty) {
      throw const ApiException(
        'Pilih sedikitnya satu batch dan jumlah kemasan yang benar-benar dikirim.',
      );
    }
    return WarehouseOperation(
      'orders/$orderId/shipments',
      {
        'operation_id': const Uuid().v4(),
        'version': context!.version,
        'handler': handler.trim(),
        'note': note.trim(),
        'lines': lines,
      },
      {
        ..._review('Konfirmasi pengiriman'),
        'handler': handler.trim(),
        'note': note.trim(),
        'lines': review,
      },
    );
  }

  Future<void> confirm(WarehouseOperation operation) async {
    if (!editable || operation.body['version'] != context?.version) return;
    final processPath = 'orders/$orderId/process',
        dispatchPath = 'orders/$orderId/shipments';
    if (operation.path == processPath
        ? context?.canProcess != true
        : operation.path != dispatchPath || context?.canDispatch != true) {
      return;
    }
    pending = operation;
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
      await repository.submit(pending!);
      saved = true;
      pending = null;
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
          ? 'Pencatatan tersimpan. Detail terbaru gagal dimuat; muat ulang sebelum tindakan berikutnya. $e'
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
