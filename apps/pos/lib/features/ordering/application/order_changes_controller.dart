import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../../../core/network/api_client.dart';
import '../data/order_changes_repository.dart';
import '../domain/order_change.dart';

class OrderChangesController extends ChangeNotifier {
  OrderChangesController(this.repository, this.id);
  final OrderChangesRepository repository;
  final String id;
  OrderChangeContext? context;
  PendingOrderChange? pending, _reviewed;
  bool busy = false, fresh = false, saved = false, _disposed = false;
  int epoch = 0;
  String? error;
  bool get editable => fresh && !busy && pending == null;
  void _notify() {
    if (!_disposed) notifyListeners();
  }

  Future<void> _read(int page) async {
    final next = await repository.context(id, page: page);
    context = next;
    fresh = true;
    epoch++;
  }

  Future<void> load({int page = 1}) async {
    if (busy || pending != null) return;
    busy = true;
    fresh = false;
    context = null;
    error = null;
    _reviewed = null;
    _notify();
    try {
      await _read(page);
    } catch (e) {
      error = '$e';
    } finally {
      busy = false;
      _notify();
    }
  }

  String _reason(String text, {bool required = true}) {
    final note = text.trim();
    if ((required && note.isEmpty) || note.length > 2000) {
      throw const ApiException('Isi alasan, maksimal 2000 karakter.');
    }
    return note;
  }

  void _ready(String action) {
    if (!editable || context?.allows(action) != true) {
      throw const ApiException(
        'Tindakan tidak diizinkan. Muat ulang atau selesaikan retry.',
      );
    }
  }

  Map<String, dynamic> _baseReview(String title, String reason) => {
    'title': title,
    'destination': context!.destination,
    'version': context!.version,
    'kind': context!.kind,
    'reason': reason,
  };
  Map<String, dynamic> _line(ChangeLine before, int packs) => {
    'sku': before.sku,
    'local_sku': before.localSku,
    'size': before.size,
    'price': before.price,
    'before': before.packs,
    'after': packs,
  };
  PendingOrderChange propose(Map<String, String> quantities, String reason) {
    _ready('propose');
    final c = context!, note = _reason(reason);
    if (quantities.length != c.original.lines.length ||
        !c.original.lines.every((l) => quantities.containsKey(l.id))) {
      throw const ApiException('Isi semua baris pesanan tepat sekali.');
    }
    final lines = <Map<String, dynamic>>[], review = <Map<String, dynamic>>[];
    var reduced = false, total = 0;
    for (final l in c.original.lines) {
      final raw = quantities[l.id]!.trim();
      final q = RegExp(r'^[0-9]+$').hasMatch(raw) ? int.tryParse(raw) : null;
      if (q == null || q < 1 || q > l.packs || q > 10000) {
        throw const ApiException(
          'Jumlah harus bilangan bulat minimal 1 dan tidak melebihi jumlah semula.',
        );
      }
      reduced = reduced || q < l.packs;
      total += q * l.price;
      lines.add({'order_line_id': l.id, 'quantity_packs': q});
      review.add(_line(l, q));
    }
    if (!reduced) {
      throw const ApiException('Setidaknya satu baris harus berkurang.');
    }
    return _reviewed = PendingOrderChange(
      repository.path(id),
      'pending',
      null,
      {
        'operation_id': const Uuid().v4(),
        'version': c.version,
        'reason': note,
        'lines': lines,
      },
      {
        ..._baseReview('Tinjau usulan pengurangan', note),
        'lines': review,
        'before_total': c.original.total,
        'after_total': total,
      },
    );
  }

  PendingOrderChange decide(
    OrderChangeRecord row,
    String action,
    String reason,
  ) {
    if (!['approve', 'reject', 'withdraw'].contains(action)) {
      throw const ApiException('Keputusan tidak valid.');
    }
    _ready(action);
    final c = context!;
    if (!c.rows.contains(row) ||
        row.status != 'pending' ||
        row.pendingVersion != c.version) {
      throw const ApiException(
        'Usulan atau versi berubah. Muat ulang dan tinjau kembali.',
      );
    }
    final note = _reason(reason, required: action != 'approve');
    final title = {
      'approve': 'Setujui jumlah revisi',
      'reject': 'Tolak usulan',
      'withdraw': 'Tarik usulan',
    }[action]!;
    return _reviewed = PendingOrderChange(
      '${repository.path(id)}/${Uri.encodeComponent(row.id)}/$action',
      {
        'approve': 'approved',
        'reject': 'rejected',
        'withdraw': 'withdrawn',
      }[action]!,
      row.id,
      {'operation_id': const Uuid().v4(), 'version': c.version, 'reason': note},
      {
        ..._baseReview(title, note),
        'proposal_reason': row.reason,
        'lines': [
          for (final l in row.before.lines)
            _line(l, row.after.lines.firstWhere((v) => v.id == l.id).packs),
        ],
        'before_total': row.before.total,
        'after_total': row.after.total,
      },
    );
  }

  Future<void> confirm(PendingOrderChange operation) async {
    if (!editable ||
        !identical(_reviewed, operation) ||
        operation.body['version'] != context!.version) {
      return;
    }
    pending = operation;
    _reviewed = null;
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
      await repository.submit(id, pending!);
      saved = true;
      pending = null;
      context = null;
      await _read(1);
    } catch (e) {
      final unknown =
          e is! ApiException ||
          e.status == 0 ||
          e.status >= 500 ||
          e.status == 408 ||
          e.status == 429;
      if (!wasUnknown && !unknown) pending = null;
      error = saved
          ? 'Pencatatan tersimpan, tetapi detail terbaru gagal dimuat. Muat ulang sebelum tindakan berikutnya. $e'
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
