import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../../../core/network/api_client.dart';
import '../data/orders_repository.dart';
import '../domain/recipient_order.dart';

class PendingRecipientOperation {
  PendingRecipientOperation(this.path, Map<String, dynamic> body)
    : _json = jsonEncode(body);
  final String path, _json;
  Map<String, dynamic> get body => jsonDecode(_json) as Map<String, dynamic>;
}

class RecipientOrderController extends ChangeNotifier {
  RecipientOrderController(this.repository, this.id);
  final OrdersRepository repository;
  final String id;
  RecipientOrder? order;
  List<ReceiptShipment> shipments = [];
  ReceiptShipment? selected;
  PendingRecipientOperation? pending;
  bool busy = false, fresh = false, _disposed = false;
  String? error, message;
  bool get canAct => fresh && !busy && pending == null;
  void _notify() {
    if (!_disposed) {
      notifyListeners();
    }
  }

  Future<bool> _run(Future<void> Function() work) async {
    if (busy) {
      return false;
    }
    busy = true;
    error = null;
    message = null;
    _notify();
    try {
      await work();
      return true;
    } catch (e) {
      error = e.toString();
      return false;
    } finally {
      busy = false;
      _notify();
    }
  }

  Future<void> _refresh() async {
    fresh = false;
    selected = null;
    final next = RecipientOrder.fromJson(await repository.details(id));
    final rows = next.fulfillmentEnabled
        ? await repository.shipments(id)
        : <Map<String, dynamic>>[];
    final deliveries = rows
        .map(ReceiptShipment.fromJson)
        .toList(growable: false);
    order = next;
    shipments = deliveries;
    fresh = true;
  }

  Future<bool> load() => _run(_refresh);
  void select(ReceiptShipment shipment) {
    if (!canAct ||
        order?.allows('receive') != true ||
        shipment.receivedAt != null ||
        !shipment.canIdentify ||
        !shipments.contains(shipment)) {
      return;
    }
    selected = shipment;
    error = null;
    message = null;
    _notify();
  }

  void _ready(int version) {
    if (!fresh || pending != null || order == null) {
      throw const ApiException(
        'Muat ulang atau selesaikan retry sebelum tindakan berikutnya.',
      );
    }
    if (order!.version != version) {
      throw const ApiException(
        'Versi yang ditinjau berubah. Muat ulang dan periksa kembali.',
      );
    }
  }

  String _text(String text, {bool required = false}) {
    final result = text.trim();
    if ((required && result.isEmpty) || result.length > 2000) {
      throw const ApiException(
        'Catatan wajib diisi bila diperlukan, maksimal 2000 karakter.',
      );
    }
    return result;
  }

  int _packs(String text) {
    if (!RegExp(r'^[0-9]+$').hasMatch(text.trim())) {
      throw const ApiException(
        'Isi jumlah kemasan baik dan rusak dengan bilangan bulat, termasuk 0.',
      );
    }
    final value = int.tryParse(text.trim());
    if (value == null || value > 10000) {
      throw const ApiException('Jumlah kemasan harus antara 0 dan 10000.');
    }
    return value;
  }

  Future<void> _post(PendingRecipientOperation operation) async {
    pending = operation;
    try {
      await repository.submit(operation.path, operation.body);
    } catch (e) {
      if (e is ApiException && e.status != 0 && e.status < 500) {
        pending = null;
        if ([401, 403, 409].contains(e.status)) {
          fresh = false;
          selected = null;
        }
      }
      rethrow;
    }
    pending = null;
    selected = null;
    message = 'Pencatatan tersimpan di server.';
    try {
      await _refresh();
    } catch (e) {
      throw ApiException(
        'Pencatatan tersimpan, tetapi detail terbaru gagal dimuat. Muat ulang sebelum mencatat lagi. $e',
      );
    }
  }

  Future<bool> retry() => _run(() async {
    final operation = pending;
    if (operation != null) {
      await _post(operation);
    }
  });
  Future<bool> transition(
    String action,
    String reason, {
    required int version,
  }) => _run(() async {
    _ready(version);
    if (!['approve', 'reject', 'cancel'].contains(action) ||
        !order!.allows(action)) {
      throw const ApiException('Tindakan tidak diizinkan untuk pesanan ini.');
    }
    final note = _text(reason, required: action != 'approve');
    await _post(
      PendingRecipientOperation('orders/${Uri.encodeComponent(id)}/$action', {
        'operation_id': const Uuid().v4(),
        'version': version,
        'reason': note,
      }),
    );
  });
  Future<bool> receive(
    ReceiptShipment shipment,
    int version,
    Map<String, ReceiptLineInput> inputs,
    String note,
  ) => _run(() async {
    _ready(version);
    if (!order!.allows('receive') ||
        selected != shipment ||
        !shipments.contains(shipment) ||
        shipment.receivedAt != null ||
        !shipment.canIdentify) {
      throw const ApiException(
        'Pilih kiriman yang belum diterima dari detail terbaru.',
      );
    }
    final ids = shipment.lines.map((l) => l.id).toSet();
    if (ids.length != shipment.lines.length ||
        inputs.length != ids.length ||
        !inputs.keys.every(ids.contains)) {
      throw const ApiException('Isi setiap baris kiriman tepat sekali.');
    }
    final lines = <Map<String, dynamic>>[];
    for (final line in shipment.lines) {
      final input = inputs[line.id]!;
      final good = _packs(input.good), damaged = _packs(input.damaged);
      if (good + damaged > line.sent) {
        throw ApiException(
          'Batch ${line.batch}: jumlah baik + rusak melebihi kiriman.',
        );
      }
      final condition = _text(input.note, required: good != line.sent);
      lines.add({
        'shipment_line_id': line.id,
        'good_packs': good,
        'damaged_packs': damaged,
        'condition_note': condition,
      });
    }
    await _post(
      PendingRecipientOperation(
        'shipments/${Uri.encodeComponent(shipment.id)}/receive',
        {
          'operation_id': const Uuid().v4(),
          'version': version,
          'note': _text(note),
          'lines': lines,
        },
      ),
    );
  });
  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}
