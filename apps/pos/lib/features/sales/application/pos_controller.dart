import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../../../core/network/api_client.dart';
import '../../catalog/domain/catalog_product.dart';
import '../domain/cart_entry.dart';
import '../data/pos_repository.dart';

class PosController extends ChangeNotifier {
  PosController(this.repository, this.store);
  final PosRepository repository;
  final String store;
  final cart = <String, CartEntry>{};
  List<CatalogProduct> products = [];
  List<Map<String, dynamic>> batches = [];
  Map<String, dynamic>? context, intent, receipt, closedShift;
  Map<String, dynamic>? _openRequest,
      _createRequest,
      _payRequest,
      _closeRequest;
  bool busy = false, _disposed = false;
  String? error;
  String query = '';
  Map<String, dynamic>? get shift => context?['shift'] as Map<String, dynamic>?;
  bool get cartLocked => busy || intent != null || _createRequest != null;
  bool get creatingUncertain => _createRequest != null;
  bool get closingUncertain => _closeRequest != null;
  bool get openingUncertain => _openRequest != null;
  bool get paymentUncertain => _payRequest != null;
  List<Map<String, dynamic>> get pending =>
      ((context?['pending_intents'] as List?) ?? [])
          .cast<Map<String, dynamic>>();
  void _notify() {
    if (!_disposed) {
      notifyListeners();
    }
  }

  bool _uncertain(Object e) =>
      e is ApiException && (e.status == 0 || e.status >= 500);
  Future<void> _perform(Future<void> Function() task) async {
    if (busy) {
      return;
    }
    busy = true;
    error = null;
    _notify();
    try {
      await task();
    } catch (e) {
      error = e.toString();
    } finally {
      busy = false;
      _notify();
    }
  }

  Future<void> _refresh() async {
    final nextContext = await repository.context(store);
    if ((nextContext['store'] as Map)['active'] == true) {
      final results = await Future.wait<dynamic>([
        repository.products(store),
        repository.batches(store),
      ]);
      products = results[0] as List<CatalogProduct>;
      batches = results[1] as List<Map<String, dynamic>>;
    } else {
      products = [];
      batches = [];
    }
    context = nextContext;
    for (final entry in cart.values) {
      if (entry.stock != null &&
          !choices(entry).any((c) => c['key'] == entry.stock)) {
        entry.stock = null;
      }
    }
    if (shift != null) {
      _openRequest = null;
    }
  }

  Future<void> load() => _perform(() async {
    await _refresh();
    if (intent != null) {
      final result = await repository.intent(intent!['id'] as String);
      final sale = result['sale'] as Map<String, dynamic>?;
      final draft = result['intent'] as Map<String, dynamic>;
      if (sale != null) {
        receipt = sale;
        intent = null;
        _payRequest = null;
        cart.clear();
      } else if (draft['status'] != 'pending') {
        intent = null;
        _payRequest = null;
        cart.clear();
      } else {
        intent = draft;
      }
    }
  });
  void search(String value) {
    query = value.toLowerCase();
    _notify();
  }

  List<CatalogProduct> get filtered => products
      .where((p) => '${p.name} ${p.sku}'.toLowerCase().contains(query))
      .toList();
  void add(CatalogProduct product) {
    if (busy || intent != null || _createRequest != null) {
      return;
    }
    cart.putIfAbsent(product.id, () => CartEntry(product));
    _notify();
  }

  void remove(String id) {
    if (!busy && _createRequest == null) {
      cart.remove(id);
      _notify();
    }
  }

  void unit(CartEntry entry, String value) {
    if (busy || _createRequest != null) {
      return;
    }
    entry.unit = value;
    entry.stock = null;
    _notify();
  }

  void quantity(CartEntry entry, String value) {
    if (busy || _createRequest != null) {
      return;
    }
    entry.quantity = int.tryParse(value) ?? 0;
    _notify();
  }

  void stock(CartEntry entry, String? value) {
    if (busy || _createRequest != null) {
      return;
    }
    entry.stock = value;
    _notify();
  }

  List<Map<String, dynamic>> choices(CartEntry entry) {
    final choices = <Map<String, dynamic>>[];
    for (final b in batches) {
      if (b['product_id'] != entry.product.id ||
          b['condition'] != entry.product.condition) {
        continue;
      }
      for (final bucket in ['usable', 'opened', 'ready']) {
        if (entry.unit == 'pack'
            ? (bucket != 'usable' || b['pack_size'] != entry.product.packSize)
            : bucket == 'usable') {
          continue;
        }
        final pieces = b['${bucket}_pieces'] as int? ?? 0;
        if (pieces <= 0) {
          continue;
        }
        choices.add({
          'key': '${b['id']}:$bucket',
          'batch_id': b['id'],
          'bucket': bucket,
          'label': '${b['batch_code']} · $bucket · $pieces biji',
          'pieces': pieces,
          'expiry': b['expires_on'] ?? '—',
        });
      }
    }
    return choices;
  }

  Future<void> open(String device, String opening) => _perform(() async {
    final cash = int.tryParse(opening);
    if (_openRequest == null &&
        (cash == null || cash < 0 || device.trim().isEmpty)) {
      throw const ApiException('Isi nama terminal dan kas awal yang valid.');
    }
    _openRequest ??= {
      'operation_id': const Uuid().v4(),
      'device_name': device.trim(),
      'opening_cash': cash,
    };
    try {
      await repository.post('stores/$store/shifts', _openRequest!);
      _openRequest = null;
      await _refresh();
    } catch (e) {
      if (!_uncertain(e)) {
        _openRequest = null;
      }
      rethrow;
    }
  });
  Future<void> review() => _perform(() async {
    if (shift == null) {
      throw const ApiException('Buka shift terlebih dahulu.');
    }
    if (_createRequest == null) {
      if (pending.isNotEmpty) {
        throw const ApiException(
          'Pulihkan atau batalkan draft lama sebelum membuat pembayaran baru.',
        );
      }
      if (cart.isEmpty) {
        throw const ApiException('Keranjang masih kosong.');
      }
      final lines = <Map<String, dynamic>>[];
      for (final entry in cart.values) {
        if (entry.quantity < 1 ||
            entry.quantity > 10000 ||
            entry.stock == null) {
          throw const ApiException(
            'Isi jumlah dan pilih batch untuk setiap SKU.',
          );
        }
        final selected = choices(
          entry,
        ).where((c) => c['key'] == entry.stock).firstOrNull;
        if (selected == null ||
            (selected['pieces'] as int) <
                entry.quantity * entry.piecesPerUnit) {
          throw const ApiException(
            'Stok pilihan tidak cukup. Muat ulang atau pilih batch lain.',
          );
        }
        lines.add({
          'product_id': entry.product.id,
          'unit': entry.unit,
          'quantity': entry.quantity,
          'allocations': [
            {
              'batch_id': selected['batch_id'],
              'bucket': selected['bucket'],
              'quantity': entry.quantity,
            },
          ],
        });
      }
      _createRequest = {
        'operation_id': const Uuid().v4(),
        'shift_id': shift!['id'],
        'lines': lines,
      };
    }
    try {
      intent = await repository.post(
        'stores/$store/sales/intents',
        _createRequest!,
      );
      _createRequest = null;
      _payRequest = null;
      await _refresh();
    } catch (e) {
      if (!_uncertain(e)) {
        _createRequest = null;
      }
      rethrow;
    }
  });
  Future<void> recover(String id) => _perform(() async {
    final result = await repository.intent(id);
    receipt = result['sale'] as Map<String, dynamic>?;
    intent = receipt == null ? result['intent'] as Map<String, dynamic> : null;
    _createRequest = null;
    _payRequest = null;
    cart.clear();
    await _refresh();
  });
  Future<void> showReceipt(String id) => _perform(() async {
    receipt = await repository.receipt(id);
    intent = null;
    _payRequest = null;
    cart.clear();
  });
  Future<void> pay(String cashText) => _perform(() async {
    if (intent == null) {
      return;
    }
    final cash = int.tryParse(cashText);
    if (_payRequest == null &&
        (cash == null || cash < (intent!['total_amount'] as int))) {
      throw const ApiException('Tunai diterima belum mencukupi.');
    }
    _payRequest ??= {
      'operation_id': const Uuid().v4(),
      'payment_method': 'cash',
      'cash_received': cash,
    };
    try {
      receipt = await repository.post(
        'sales/intents/${intent!['id']}/pay',
        _payRequest!,
      );
      intent = null;
      _payRequest = null;
      cart.clear();
      await _refresh();
    } catch (e) {
      if (!_uncertain(e)) {
        _payRequest = null;
      }
      rethrow;
    }
  });
  Future<void> cancel(String reason) => _perform(() async {
    if (intent == null) {
      return;
    }
    if (reason.trim().isEmpty) {
      throw const ApiException('Alasan pembatalan wajib diisi.');
    }
    final id = intent!['id'];
    await repository.post('sales/intents/$id/cancel', {
      'operation_id': const Uuid().v4(),
      'reason': reason.trim(),
    });
    intent = null;
    _payRequest = null;
    cart.clear();
    await _refresh();
  });
  Future<void> close(String counted, String note) => _perform(() async {
    if (shift == null) {
      return;
    }
    final cash = int.tryParse(counted);
    if (_closeRequest == null && (cash == null || cash < 0)) {
      throw const ApiException('Isi kas fisik hasil hitungan.');
    }
    _closeRequest ??= {
      'operation_id': const Uuid().v4(),
      'version': shift!['version'],
      'counted_cash': cash,
      'closing_note': note.trim(),
    };
    final id = shift!['id'];
    try {
      closedShift = await repository.post('shifts/$id/close', _closeRequest!);
      _closeRequest = null;
      await _refresh();
    } catch (e) {
      if (!_uncertain(e)) {
        _closeRequest = null;
      }
      rethrow;
    }
  });
  void newSale() {
    if (busy) {
      return;
    }
    receipt = null;
    closedShift = null;
    error = null;
    _notify();
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}
