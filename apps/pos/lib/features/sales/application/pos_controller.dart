import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../../../core/network/api_client.dart';
import '../../catalog/domain/catalog_product.dart';
import '../domain/cart_entry.dart';
import '../data/offline_repository.dart';
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
  String? error, offlineBlockerMessage;
  String query = '';
  String? serviceType;
  Map<String, dynamic>? get shift => context?['shift'] as Map<String, dynamic>?;
  bool get canManage => context?['can_manage'] == true;
  Map<String, dynamic>? get blockingOpenShift =>
      context?['blocking_open_shift'] as Map<String, dynamic>?;
  Map<String, dynamic>? get handover =>
      context?['handover'] as Map<String, dynamic>?;
  List<Map<String, dynamic>> get pendingCashRequests =>
      ((context?['pending_cash_requests'] as List?) ?? [])
          .cast<Map<String, dynamic>>();
  bool get consumablesEnabled => context?['consumables_enabled'] == true;
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
      e is! ApiException ||
      e.status == 0 ||
      e.status >= 500 ||
      e.status == 408 ||
      e.status == 429;
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
    offlineBlockerMessage = await _checkLocalOfflineBlocker();
  }

  Future<String?> _checkLocalOfflineBlocker() async {
    final ds = repository.deviceState;
    final activeShift = shift;
    if (ds == null ||
        activeShift == null ||
        repository.api.identity?['id'] is! int) {
      return null;
    }
    try {
      final state = await OfflineRepository(repository.api, ds).load();
      final shiftId = activeShift['id'];
      final permits = (state['permits'] as Map).values.where(
        (p) => p['closed_at'] == null && p['cash_shift_id'] == shiftId,
      );
      final outbox = (state['outbox'] as Map).values.where(
        (o) =>
            o['status'] != 'accepted' &&
            o['status'] != 'voided' &&
            o['permit_id'] != null &&
            (state['permits'] as Map)[o['permit_id']]?['cash_shift_id'] ==
                shiftId,
      );
      if (outbox.isNotEmpty) {
        return 'Ada ${outbox.length} transaksi offline yang belum tersinkron/selesai konflik. Buka menu Offline dan sinkronkan dahulu sebelum tutup shift.';
      }
      if (permits.isNotEmpty) {
        return 'Izin offline masih aktif. Buka menu Offline lalu akhiri izin offline secara online sebelum tutup shift.';
      }
    } catch (_) {
      // If encrypted state is inaccessible for another identity, API still enforces close blockers.
    }
    return null;
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
  void _autoSelectStock(CartEntry entry) {
    if (entry.product.isBundle || entry.stock != null) {
      return;
    }
    final available = choices(entry);
    if (available.isEmpty) {
      return;
    }
    available.sort((a, b) {
      final exp = '${a['expiry']}'.compareTo('${b['expiry']}');
      if (exp != 0) return exp;
      if (a['bucket'] == b['bucket']) return 0;
      if (a['bucket'] == 'ready') return -1;
      if (b['bucket'] == 'ready') return 1;
      return 0;
    });
    entry.stock = available.first['key'] as String;
  }

  void add(CatalogProduct product) {
    if (busy || intent != null || _createRequest != null) {
      return;
    }
    final existing = cart[product.id];
    if (existing != null) {
      existing.quantity += 1;
      _autoSelectStock(existing);
    } else {
      final entry = CartEntry(product);
      _autoSelectStock(entry);
      cart[product.id] = entry;
    }
    _notify();
  }

  void increment(CartEntry entry) {
    if (busy || _createRequest != null) {
      return;
    }
    entry.quantity += 1;
    _notify();
  }

  void decrement(CartEntry entry) {
    if (busy || _createRequest != null) {
      return;
    }
    if (entry.quantity <= 1) {
      cart.remove(entry.product.id);
    } else {
      entry.quantity -= 1;
    }
    _notify();
  }

  int get totalCartUnits =>
      cart.values.fold(0, (sum, e) => sum + (e.quantity > 0 ? e.quantity : 0));

  int get estimatedSubtotal => cart.values.fold(0, (sum, e) {
    final qty = e.quantity > 0 ? e.quantity : 0;
    final multiplier = e.unit == 'pack'
        ? e.product.packSize
        : (e.unit == 'portion' ? e.product.portionSize : 1);
    return sum + (e.product.retailPrice * qty * multiplier);
  });

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
    _autoSelectStock(entry);
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

  List<Map<String, dynamic>> componentChoices(
    Map<String, dynamic> component,
  ) => [
    for (final b in batches)
      if (b['product_id'] == component['product_id'] &&
          b['condition'] == component['condition'])
        for (final bucket in ['opened', 'ready'])
          if ((b['${bucket}_pieces'] as int? ?? 0) > 0)
            {
              'key': '${b['id']}:$bucket',
              'batch_id': b['id'],
              'bucket': bucket,
              'pieces': b['${bucket}_pieces'],
              'label':
                  '${b['batch_code']} · $bucket · ${b['${bucket}_pieces']} biji',
            },
  ];
  void componentStock(CartEntry entry, String component, String? value) {
    if (cartLocked) return;
    if (value == null) {
      entry.componentStocks.remove(component);
    } else {
      entry.componentStocks[component] = value;
    }
    _notify();
  }

  void setServiceType(String? value) {
    if (cartLocked) return;
    serviceType = value;
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

  Future<void> open(
    String device,
    String opening, {
    String openingNote = '',
  }) => _perform(() async {
    final cash = int.tryParse(opening);
    if (_openRequest == null &&
        (cash == null || cash < 0 || device.trim().isEmpty)) {
      throw const ApiException('Isi nama terminal dan kas awal yang valid.');
    }
    final expectedOpening = handover?['expected_opening_cash'] as int?;
    if (_openRequest == null &&
        expectedOpening != null &&
        cash != expectedOpening &&
        openingNote.trim().isEmpty) {
      throw const ApiException(
        'Kas awal berbeda dari sisa modal shift sebelumnya. Wajib isi catatan serah terima.',
      );
    }
    _openRequest ??= {
      'operation_id': const Uuid().v4(),
      'device_name': device.trim(),
      'opening_cash': cash,
      if (openingNote.trim().isNotEmpty) 'opening_note': openingNote.trim(),
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
  Future<void> review({String voucherCode = ''}) => _perform(() async {
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
      if (consumablesEnabled && serviceType == null) {
        throw const ApiException(
          'Pilih jenis penjualan (Makan di tempat / Bawa pulang).',
        );
      }
      final lines = <Map<String, dynamic>>[];
      for (final entry in cart.values) {
        if (entry.product.isBundle) {
          if (entry.quantity < 1 || entry.quantity > 10000) {
            throw const ApiException('Isi jumlah paket1–10000.');
          }
          final current = products
              .where((p) => p.id == entry.product.id)
              .firstOrNull;
          if (current?.recipe?['version'] != entry.product.recipe!['version']) {
            throw const ApiException(
              'Resep berubah. Hapus paket dan pilih lagi setelah memuat ulang.',
            );
          }
          final allocations = <Map<String, dynamic>>[];
          for (final component in entry.product.components) {
            final selected = componentChoices(component)
                .where(
                  (b) =>
                      b['key'] ==
                      entry.componentStocks[component['product_id']],
                )
                .firstOrNull;
            final pieces = (component['pieces'] as int) * entry.quantity;
            if (selected == null || (selected['pieces'] as int) < pieces) {
              throw const ApiException(
                'Pilih stok cukup untuk semua komponen paket.',
              );
            }
            allocations.add({
              'component_product_id': component['product_id'],
              'batch_id': selected['batch_id'],
              'bucket': selected['bucket'],
              'quantity': pieces,
            });
          }
          lines.add({
            'product_id': entry.product.id,
            'unit': 'piece',
            'quantity': entry.quantity,
            'allocations': allocations,
          });
          continue;
        }
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
        if (voucherCode.trim().isNotEmpty) 'voucher_code': voucherCode.trim(),
        if (serviceType != null) 'service_type': serviceType,
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
  Future<void> pay(
    String cashText, {
    String method = 'cash',
    String reference = '',
    bool verified = false,
    String verifiedAmount = '',
  }) => _perform(() async {
    if (intent == null) return;
    final wasUnknown = _payRequest != null;
    final cash = int.tryParse(cashText);
    if (!wasUnknown) {
      if (!['cash', 'qris_manual', 'bank_transfer'].contains(method)) {
        throw const ApiException('Metode tidak valid.');
      }
      if (method == 'cash' &&
          (cash == null || cash < (intent!['total_amount'] as int))) {
        throw const ApiException('Tunai diterima belum mencukupi.');
      }
      if (method != 'cash' &&
          (!verified ||
              reference.trim().isEmpty ||
              int.tryParse(verifiedAmount) != intent!['total_amount'])) {
        throw const ApiException(
          'Periksa referensi, nominal persis dan konfirmasi verifikasi pembayaran aktual.',
        );
      }
      _payRequest = {
        'operation_id': const Uuid().v4(),
        'payment_method': method,
        if (method == 'cash')
          'cash_received': cash
        else ...{
          'payment_reference': reference.trim(),
          'verified': true,
          'verified_amount': int.parse(verifiedAmount),
        },
      };
    }
    try {
      final d = await repository.post(
        'sales/intents/${intent!['id']}/pay',
        _payRequest!,
      );
      final p = _payRequest!;
      if (d['id'] is! String ||
          d['sale_intent_id'] != intent!['id'] ||
          d['store_id'] != store ||
          d['total_amount'] != intent!['total_amount'] ||
          d['payment_method'] != p['payment_method'] ||
          (p['payment_method'] == 'cash'
              ? d['cash_received'] != p['cash_received']
              : d['payment_reference'] != p['payment_reference'])) {
        throw const ApiException('ACK pembayaran tidak sesuai.');
      }
      receipt = d;
      intent = null;
      _payRequest = null;
      cart.clear();
      await _refresh();
    } catch (e) {
      if (!wasUnknown && !_uncertain(e)) _payRequest = null;
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
  Future<void> close(
    String counted,
    String note, {
    String retainedText = '',
  }) => _perform(() async {
    if (shift == null) {
      return;
    }
    final blocker = await _checkLocalOfflineBlocker();
    if (blocker != null) {
      offlineBlockerMessage = blocker;
      throw ApiException(blocker);
    }
    final cash = int.tryParse(counted);
    if (_closeRequest == null && (cash == null || cash < 0)) {
      throw const ApiException('Isi kas fisik hasil hitungan.');
    }
    int? retained;
    if (_closeRequest == null && retainedText.trim().isNotEmpty) {
      retained = int.tryParse(retainedText.trim());
      if (retained == null || retained < 0 || retained > cash!) {
        throw const ApiException(
          'Sisa modal di laci harus berupa angka valid dan tidak melebihi kas fisik.',
        );
      }
    }
    _closeRequest ??= {
      'operation_id': const Uuid().v4(),
      'version': shift!['version'],
      'counted_cash': cash,
      if (retained != null) 'retained_cash': retained,
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
