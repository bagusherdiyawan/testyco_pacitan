import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../../../core/network/api_client.dart';

class RefundController extends ChangeNotifier {
  RefundController(this.api, this.sale);
  final ApiClient api;
  final String sale;
  Map<String, dynamic>? context;
  String? _candidate, _pending, error;
  bool busy = false, disposed = false;
  bool get uncertain => _pending != null;
  Map<String, dynamic>? get review => _candidate == null && _pending == null
      ? null
      : jsonDecode(_pending ?? _candidate!)['review'] as Map<String, dynamic>;
  bool get editable =>
      !busy && !uncertain && _candidate == null && context != null;
  void changed() {
    if (!disposed) notifyListeners();
  }

  Never invalid() =>
      throw const ApiException('Cakupan atau respons refund tidak valid.');
  Future<void> load() async {
    if (busy || uncertain || _candidate != null) return;
    busy = true;
    error = null;
    context = null;
    changed();
    try {
      final c = await api.request(
        'GET',
        'sales/${Uri.encodeComponent(sale)}/refunds',
      );
      if (c['sale'] is! Map ||
          c['sale']['id'] != sale ||
          c['sale']['store_id'] is! String ||
          c['sale']['version'] is! int ||
          c['sale']['lines'] is! List ||
          c['open_shifts'] is! List ||
          c['refunds'] is! List) {
        invalid();
      }
      for (final l in c['sale']['lines']) {
        if (l['sale_id'] != sale) invalid();
      }
      for (final s in c['open_shifts']) {
        if (s['store_id'] != c['sale']['store_id'] ||
            s['status'] != 'open' ||
            s['version'] is! int) {
          invalid();
        }
      }
      for (final r in c['refunds']) {
        if (r['sale_id'] != sale) invalid();
      }
      context = c;
    } catch (e) {
      error = '$e';
    } finally {
      busy = false;
      changed();
    }
  }

  Future<void> preview(
    String shift,
    String disposition,
    String reference,
    String reason,
    Map<String, int> quantities,
  ) async {
    if (!editable) return;
    final c = jsonDecode(jsonEncode(context)) as Map<String, dynamic>;
    final selected = (c['open_shifts'] as List).where((s) => s['id'] == shift);
    if (selected.length != 1 ||
        !['no_stock', 'quarantine'].contains(disposition) ||
        reference.trim().isEmpty ||
        reference.length > 160 ||
        reason.trim().isEmpty ||
        reason.length > 1800) {
      error = 'Pilih shift, penanganan fisik, referensi dan alasan.';
      changed();
      return;
    }
    final lines = <Map<String, dynamic>>[];
    for (final e in Map<String, int>.from(quantities).entries) {
      if (e.value == 0) continue;
      final found = (c['sale']['lines'] as List).where((l) => l['id'] == e.key);
      if (found.length != 1 ||
          e.value < 1 ||
          e.value > found.single['quantity']) {
        error = 'Jumlah refund tidak valid.';
        changed();
        return;
      }
      lines.add({'sale_line_id': e.key, 'quantity': e.value});
    }
    if (lines.isEmpty) {
      error = 'Isi jumlah refund aktual.';
      changed();
      return;
    }
    final b = {
      'version': c['sale']['version'],
      'shift_id': shift,
      'shift_version': selected.single['version'],
      'stock_disposition': disposition,
      'reference': reference.trim(),
      'reason': reason.trim(),
      'lines': lines,
    };
    busy = true;
    error = null;
    changed();
    try {
      final d = (await api.request(
        'POST',
        'sales/${Uri.encodeComponent(sale)}/refunds/preview',
        b,
      ))['data'];
      if (d is! Map ||
          d['sale_id'] != sale ||
          d['store_id'] != c['sale']['store_id'] ||
          d['version'] != b['version'] ||
          d['shift_id'] != shift ||
          d['shift_version'] != b['shift_version'] ||
          d['stock_disposition'] != disposition ||
          d['reference'] != b['reference'] ||
          d['reason'] != b['reason'] ||
          d['payment_method'] != c['sale']['payment_method'] ||
          d['amount'] is! int ||
          d['amount'] < 0 ||
          d['review_hash'] is! String ||
          d['review_hash'].length != 64 ||
          d['lines'] is! List) {
        invalid();
      }
      final expected =
          lines.map((l) => '${l['sale_line_id']}:${l['quantity']}').toList()
            ..sort();
      final actual =
          (d['lines'] as List)
              .map((l) => '${l['sale_line_id']}:${l['quantity']}')
              .toList()
            ..sort();
      if (jsonEncode(expected) != jsonEncode(actual)) invalid();
      _candidate = jsonEncode({
        'body': {
          ...b,
          'operation_id': const Uuid().v4(),
          'review_hash': d['review_hash'],
          'verified': true,
        },
        'review': d,
      });
    } catch (e) {
      error = '$e';
    } finally {
      busy = false;
      changed();
    }
  }

  void discard() {
    if (busy || uncertain) return;
    _candidate = null;
    error = null;
    changed();
  }

  Future<void> confirm() async {
    if (busy || _candidate == null || uncertain) return;
    _pending = _candidate;
    _candidate = null;
    await _send(false);
  }

  Future<void> retry() async {
    if (!busy && uncertain) await _send(true);
  }

  Future<void> _send(bool wasUnknown) async {
    busy = true;
    error = null;
    changed();
    final p = jsonDecode(_pending!) as Map<String, dynamic>;
    final b = p['body'], r = p['review'];
    try {
      final d = (await api.request(
        'POST',
        'sales/${Uri.encodeComponent(sale)}/refunds',
        Map<String, dynamic>.from(b),
      ))['data'];
      final f = d is Map ? d['refund'] : null;
      if (d is! Map ||
          d['sale_id'] != sale ||
          d['sale_version'] != b['version'] + 1 ||
          f is! Map ||
          f['id'] is! String ||
          f['sale_id'] != sale ||
          f['cash_shift_id'] != b['shift_id'] ||
          f['payment_method'] != r['payment_method'] ||
          f['amount'] != r['amount'] ||
          f['reference'] != b['reference'] ||
          f['stock_disposition'] != b['stock_disposition'] ||
          jsonEncode(f['lines']) != jsonEncode(r['lines'])) {
        invalid();
      }
      _pending = null;
      context = null;
    } catch (e) {
      final unknown =
          e is! ApiException ||
          e.status == 0 ||
          e.status >= 500 ||
          e.status == 408 ||
          e.status == 429;
      if (!wasUnknown && !unknown) _pending = null;
      error = '$e';
    } finally {
      busy = false;
      changed();
    }
    if (_pending == null && error == null) await load();
  }

  @override
  void dispose() {
    disposed = true;
    super.dispose();
  }
}
