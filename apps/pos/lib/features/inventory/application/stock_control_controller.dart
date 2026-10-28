import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../../../core/network/api_client.dart';
import '../data/stock_control_repository.dart';
import '../domain/stock_batch.dart';
import '../domain/stock_control.dart';

class PendingStockOperation {
  PendingStockOperation(this.path, Map<String, dynamic> body)
    : body = Map.unmodifiable(body);
  final String path;
  final Map<String, dynamic> body;
}

class StockControlController extends ChangeNotifier {
  StockControlController(this.repository, this.store);
  final StockControlRepository repository;
  final String store;
  StockControlContext? context;
  List<StockBatch> batches = [];
  List<StockCount> counts = [];
  List<StockProcess> processes = [];
  StockBatch? selected;
  PendingStockOperation? pending;
  bool busy = false, fresh = false, _disposed = false;
  String? error, message;
  bool get canAct => fresh && !busy && pending == null;
  void _notify() {
    if (!_disposed) {
      notifyListeners();
    }
  }

  Future<bool> _run(Future<void> Function() task) async {
    if (busy) {
      return false;
    }
    busy = true;
    error = null;
    message = null;
    _notify();
    try {
      await task();
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
    final next = await repository.context(store);
    final values = await Future.wait<dynamic>([
      repository.batches(store),
      repository.counts(store),
      repository.processes(store),
    ]);
    context = next;
    batches = values[0] as List<StockBatch>;
    counts = values[1] as List<StockCount>;
    processes = values[2] as List<StockProcess>;
    selected = null; // A new observation requires selecting and checking again.
    fresh = true;
  }

  Future<bool> load() => _run(_refresh);
  void select(StockBatch batch) {
    if (!canAct ||
        !batches.any((b) => b.id == batch.id && b.revision == batch.revision)) {
      return;
    }
    selected = batch;
    error = null;
    message = null;
    _notify();
  }

  int _integer(String text, String label, {int min = 0, int max = 1000000000}) {
    if (!RegExp(r'^\d+$').hasMatch(text.trim())) {
      throw ApiException('$label harus bilangan bulat.');
    }
    final value = int.tryParse(text.trim());
    if (value == null || value < min || value > max) {
      throw ApiException('$label di luar batas yang diizinkan.');
    }
    return value;
  }

  void _ready() {
    if (!fresh || pending != null) {
      throw const ApiException(
        'Muat ulang atau selesaikan retry operasi yang belum diketahui.',
      );
    }
  }

  StockBatch _batch() {
    _ready();
    final b = selected;
    if (b == null) {
      throw const ApiException('Pilih snapshot batch terlebih dahulu.');
    }
    return b;
  }

  String _reason(String reason) {
    final value = reason.trim();
    if (value.isEmpty || value.length > 2000) {
      throw const ApiException('Alasan wajib diisi, maksimal 2000 karakter.');
    }
    return value;
  }

  Future<void> _post(PendingStockOperation operation) async {
    pending = operation;
    try {
      await repository.post(operation.path, operation.body);
    } catch (e) {
      if (e is ApiException && e.status != 0 && e.status < 500) {
        pending = null;
        if (e.status == 409 || e.status == 403 || e.status == 401) {
          fresh = false;
          selected = null;
        }
      }
      rethrow;
    }
    pending = null;
    selected = null;
    message = 'Operasi tersimpan di server.';
    try {
      await _refresh();
    } catch (e) {
      throw ApiException(
        'Operasi tersimpan, tetapi saldo terbaru gagal dimuat. Muat ulang sebelum tindakan berikutnya. $e',
      );
    }
  }

  Future<bool> retry() => _run(() async {
    final operation = pending;
    if (operation != null) {
      await _post(operation);
    }
  });
  Future<bool> process({
    required String kind,
    required String quantity,
    required String output,
    required String reason,
  }) => _run(() async {
    final b = _batch();
    if (context?.canProcess != true || b.condition != 'frozen') {
      throw const ApiException(
        'Batch atau akses tidak mengizinkan pengolahan.',
      );
    }
    if (kind != 'open' && kind != 'cook') {
      throw const ApiException('Pilih buka atau kukus.');
    }
    final input = _integer(
      quantity,
      kind == 'open' ? 'Jumlah kemasan' : 'Input biji',
      min: 1,
      max: kind == 'open' ? 10000 : 100000000,
    );
    final out = kind == 'cook'
        ? _integer(output, 'Hasil aktual', max: 100000000)
        : 0;
    if (kind == 'open'
        ? input * b.packSize > b.usable
        : input > b.opened || out > input) {
      throw const ApiException('Stok tidak cukup atau hasil melebihi input.');
    }
    await _post(
      PendingStockOperation(
        'stores/${Uri.encodeComponent(store)}/inventory/processes',
        {
          'operation_id': const Uuid().v4(),
          'batch_id': b.id,
          'expected_revision': b.revision,
          'kind': kind,
          'reason': _reason(reason),
          if (kind == 'open') 'quantity_packs': input,
          if (kind == 'cook') ...{'input_pieces': input, 'output_pieces': out},
        },
      ),
    );
  });
  Future<bool> propose({
    required String purpose,
    required String bucket,
    required String counted,
    required String reason,
  }) => _run(() async {
    final b = _batch();
    if (context?.canPropose != true ||
        !['stocktake', 'waste'].contains(purpose)) {
      throw const ApiException('Pengajuan tidak diizinkan.');
    }
    final expected = switch (bucket) {
      'usable' => b.usable,
      'opened' => b.opened,
      'ready' => b.ready,
      'quarantined' => b.quarantined,
      _ => throw const ApiException('Bucket tidak dapat dihitung.'),
    };
    final physical = _integer(counted, 'Jumlah fisik tersisa');
    if (bucket == 'usable' && physical % b.packSize != 0) {
      throw const ApiException(
        'Hitungan utuh harus kelipatan isi kemasan historis.',
      );
    }
    if (purpose == 'waste' && physical >= expected) {
      throw const ApiException(
        'Susut harus mengurangi saldo bucket yang dihitung.',
      );
    }
    await _post(
      PendingStockOperation(
        'stores/${Uri.encodeComponent(store)}/inventory/counts',
        {
          'operation_id': const Uuid().v4(),
          'batch_id': b.id,
          'expected_revision': b.revision,
          'purpose': purpose,
          'bucket': bucket,
          'counted_pieces': physical,
          'reason': _reason(reason),
        },
      ),
    );
  });
  Future<bool> review(StockCount count, String action, String note) => _run(
    () async {
      _ready();
      if (!counts.any((c) => c.id == count.id && c.version == count.version) ||
          count.status != 'pending') {
        throw const ApiException('Versi pengajuan berubah. Muat ulang.');
      }
      final allowed = switch (action) {
        'approve' => count.canReview && !count.isStale,
        'reject' => count.canReview,
        'cancel' => count.canCancel,
        _ => false,
      };
      if (!allowed) {
        throw const ApiException(
          'Tindakan ditolak untuk pengajuan atau snapshot ini.',
        );
      }
      await _post(
        PendingStockOperation(
          'inventory/counts/${Uri.encodeComponent(count.id)}/$action',
          {
            'operation_id': const Uuid().v4(),
            'version': count.version,
            'review_note': _reason(note),
          },
        ),
      );
    },
  );
  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}
