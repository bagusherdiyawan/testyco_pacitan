import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../../../core/network/api_client.dart';
import '../data/order_entry_repository.dart';
import '../domain/order_entry.dart';

class OrderEntryController extends ChangeNotifier {
  OrderEntryController(this.repository, this.store, {this.orderId});
  final OrderEntryRepository repository;
  final String store;
  final String? orderId;
  bool get isAmend => orderId != null;
  int? _amendVersion;
  int compositionEpoch = 0;
  bool orderChanged = false;
  OrderEntryContext? context;
  List<OrderProductChoice> suppliers = [], locals = [];
  final cart = <DraftOrderLine>[];
  OrderEntryPreview? preview;
  OrderSubmission? pending;
  String? _reviewBody, createdId, error, message;
  String mode = 'pickup', note = '', query = '';
  bool fresh = false, busy = false, _disposed = false, _unknown = false;
  bool get canEdit =>
      fresh &&
      !busy &&
      context?.canCreate == true &&
      preview == null &&
      pending == null &&
      createdId == null;
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

  Future<bool> load() => _run(() async {
    fresh = false;
    EditableOrderSeed? seed;
    if (isAmend && pending == null && createdId == null) {
      seed = await repository.seed(orderId!);
      if (seed.store != store || !seed.canAmend) {
        throw const ApiException(
          'Pengajuan tidak lagi dapat diubah oleh akun ini.',
          status: 403,
        );
      }
      if (_amendVersion != null && seed.version != _amendVersion) {
        orderChanged = true;
        throw const ApiException(
          'Versi pengajuan berubah. Mulai ulang dari versi terbaru sebelum mengedit.',
          status: 409,
        );
      }
    }
    final next = await repository.context(store);
    if (!next.canCreate) {
      context = next;
      suppliers = [];
      locals = [];
      fresh = true;
      return;
    }
    final choices = await Future.wait([
      repository.suppliers(store),
      repository.locals(store),
    ]);
    context = next;
    suppliers = choices[0];
    locals = choices[1];
    if (seed != null && _amendVersion == null) {
      cart.clear();
      cart.addAll(seed.lines);
      mode = seed.mode;
      note = seed.note;
      _amendVersion = seed.version;
      compositionEpoch++;
      orderChanged = false;
    }
    fresh = true;
    if (pending == null && createdId == null) {
      preview = null;
      _reviewBody = null;
    }
  });
  Future<bool> restartFromServer() async {
    if (!isAmend || busy || pending != null || createdId != null) {
      return false;
    }
    _amendVersion = null;
    orderChanged = false;
    cart.clear();
    preview = null;
    _reviewBody = null;
    fresh = false;
    return load();
  }

  OrderProductChoice? supplier(String id) =>
      suppliers.where((p) => p.id == id).firstOrNull;
  List<OrderProductChoice> candidates(DraftOrderLine line) {
    final p = supplier(line.supplierId);
    if (p == null) {
      return [];
    }
    return locals
        .where(
          (l) =>
              l.packSize == p.packSize &&
              l.condition == p.condition &&
              (context?.kind != 'internal_transfer' || l.id == p.id),
        )
        .toList();
  }

  List<OrderProductChoice> get filtered => suppliers
      .where((p) => '${p.sku} ${p.name}'.toLowerCase().contains(query))
      .toList();
  void search(String value) {
    query = value.toLowerCase();
    _notify();
  }

  void add(OrderProductChoice product) {
    if (!canEdit) {
      return;
    }
    if (cart.length >= 100) {
      error = 'Maksimal 100 SKU per pengajuan.';
      _notify();
      return;
    }
    if (cart.any((l) => l.supplierId == product.id)) {
      return;
    }
    if (cart.isNotEmpty &&
        supplier(cart.first.supplierId)?.organization != product.organization) {
      error = 'Satu pengajuan harus dari pemasok pusat yang sama.';
      _notify();
      return;
    }
    cart.add(
      DraftOrderLine(
        product.id,
        localId:
            context?.kind == 'internal_transfer' &&
                locals.any((l) => l.id == product.id)
            ? product.id
            : null,
      ),
    );
    error = null;
    _notify();
  }

  void remove(DraftOrderLine line) {
    if (canEdit) {
      cart.remove(line);
      _notify();
    }
  }

  void quantity(DraftOrderLine line, String value) {
    if (canEdit) {
      line.quantity = value;
      _notify();
    }
  }

  void local(DraftOrderLine line, String? value) {
    if (canEdit) {
      line.localId = value;
      _notify();
    }
  }

  void delivery(String value) {
    if (canEdit) {
      mode = value;
      _notify();
    }
  }

  void setNote(String value) {
    if (canEdit) {
      note = value;
    }
  }

  void edit() {
    if (!busy && pending == null && createdId == null) {
      preview = null;
      _reviewBody = null;
      _notify();
    }
  }

  Map<String, dynamic> _body() {
    if (!fresh ||
        context?.canCreate != true ||
        preview != null ||
        pending != null ||
        createdId != null) {
      throw const ApiException(
        'Muat ulang dan tinjau izin sebelum pengajuan baru.',
      );
    }
    if (cart.isEmpty || cart.length > 100) {
      throw const ApiException('Pilih 1 sampai 100 SKU.');
    }
    if (!['pickup', 'delivery'].contains(mode)) {
      throw const ApiException('Pilih ambil atau antar.');
    }
    if (mode == 'delivery' && context!.address.trim().isEmpty) {
      throw const ApiException(
        'Alamat toko wajib diisi melalui web sebelum pengiriman.',
      );
    }
    if (note.trim().length > 2000) {
      throw const ApiException('Catatan maksimal 2000 karakter.');
    }
    final lines = <Map<String, dynamic>>[];
    final localIds = <String>{};
    final centers = <String>{};
    for (final line in cart) {
      final product = supplier(line.supplierId);
      final quantity = int.tryParse(line.quantity.trim());
      if (product == null ||
          !RegExp(r'^[0-9]+$').hasMatch(line.quantity.trim()) ||
          quantity == null ||
          quantity < 1 ||
          quantity > 10000) {
        throw const ApiException(
          'Periksa SKU aktif dan jumlah kemasan (1–10000).',
        );
      }
      if (line.localId == null ||
          !candidates(line).any((l) => l.id == line.localId)) {
        throw const ApiException(
          'Pilih SKU lokal aktif dengan isi kemasan dan kondisi yang cocok.',
        );
      }
      if (!localIds.add(line.localId!)) {
        throw const ApiException(
          'SKU lokal tidak boleh diulang dalam pengajuan.',
        );
      }
      centers.add(product.organization);
      lines.add({
        'supplier_product_id': line.supplierId,
        'local_product_id': line.localId,
        'quantity_packs': quantity,
      });
    }
    if (centers.length != 1) {
      throw const ApiException('Satu pengajuan harus dari satu pemasok pusat.');
    }
    return {'delivery_mode': mode, 'note': note.trim(), 'lines': lines};
  }

  Future<bool> review() => _run(() async {
    final body = {..._body(), if (isAmend) 'version': _amendVersion};
    final result = isAmend
        ? await repository.previewAmend(orderId!, body)
        : await repository.preview(store, body);
    preview = result;
    _reviewBody = jsonEncode(body);
  });
  Future<void> _send(OrderSubmission submission) async {
    try {
      createdId = isAmend
          ? await repository.amend(orderId!, submission.body)
          : await repository.create(store, submission.body);
    } catch (e) {
      final uncertain = e is! ApiException || e.status == 0 || e.status >= 500;
      if (uncertain) {
        _unknown = true;
      } else if (!_unknown) {
        pending = null;
        preview = null;
        _reviewBody = null;
        if (e.status == 409 || e.status == 403 || e.status == 401) {
          fresh = false;
        }
      }
      rethrow;
    }
    pending = null;
    _unknown = false;
    message = isAmend
        ? 'Perubahan pengajuan tersimpan. Periksa detail untuk status terbaru.'
        : 'Pengajuan tersimpan. Periksa detail untuk status terbaru.';
  }

  Future<bool> confirm() => _run(() async {
    if (createdId != null) {
      return;
    }
    if (pending == null && (!fresh || context?.canCreate != true)) {
      throw const ApiException(
        'Muat ulang izin dan tinjauan sebelum mengirim.',
      );
    }
    if (preview == null || _reviewBody == null) {
      throw const ApiException('Tinjau pratinjau server terlebih dahulu.');
    }
    pending ??= OrderSubmission({
      ...jsonDecode(_reviewBody!) as Map<String, dynamic>,
      'review_hash': preview!.hash,
      'operation_id': const Uuid().v4(),
    });
    await _send(pending!);
  });
  Future<bool> retry() => _run(() async {
    final request = pending;
    if (request != null) {
      await _send(request);
    }
  });
  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}
