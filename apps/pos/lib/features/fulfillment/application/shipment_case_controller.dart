import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../../../core/network/api_client.dart';
import '../data/shipment_case_repository.dart';

class ShipmentCaseController extends ChangeNotifier {
  ShipmentCaseController(this.repository);
  final ShipmentCaseRepository repository;
  Map<String, dynamic>? context;
  CaseOperation? pending, _reviewed;
  bool busy = false, fresh = false, disposed = false, saved = false;
  String? error;
  int epoch = 0;
  bool get editable => fresh && !busy && pending == null;
  void changed() {
    if (!disposed) notifyListeners();
  }

  Future<void> load({int page = 1, int plansPage = 1}) async {
    if (busy || pending != null) return;
    busy = true;
    fresh = false;
    context = null;
    _reviewed = null;
    error = null;
    changed();
    try {
      context = await repository.load(page: page, plansPage: plansPage);
      fresh = true;
      epoch++;
    } catch (e) {
      error = '$e';
    } finally {
      busy = false;
      changed();
    }
  }

  CaseOperation prepare(
    String action,
    Map<String, dynamic> fields, {
    String? subject,
  }) {
    if (!editable) {
      throw const ApiException(
        'Muat ulang atau selesaikan request yang belum pasti.',
      );
    }
    final allowed = <String, List<String>>{
      'report': ['reason', 'reference'],
      'note': ['message', 'reference'],
      'propose': ['type', 'proposal', 'reference'],
      'confirm': ['reason'],
      'reject': ['reason'],
      'withdraw': ['reason'],
      'dispatch': ['handler', 'note', 'lines'],
      'receive': ['note', 'lines'],
    }[action];
    if (allowed == null || fields.keys.any((k) => !allowed.contains(k))) {
      throw const ApiException('Field tindakan tidak valid.');
    }
    final c = context!;
    if (!repository.physical) {
      final capability =
          {
            'propose': 'propose_resolution',
            'confirm': 'confirm_resolution',
            'reject': 'reject_resolution',
            'withdraw': 'withdraw_resolution',
          }[action] ??
          action;
      if (c['actions'][capability] != true) {
        throw const ApiException('Tindakan tidak diizinkan.');
      }
      for (final key in ['reason', 'message', 'proposal']) {
        if (fields.containsKey(key) &&
            (fields[key] is! String ||
                (fields[key] as String).trim().isEmpty ||
                (fields[key] as String).length > 2000)) {
          throw const ApiException('Isi teks wajib, maksimal 2000 karakter.');
        }
      }
      if (['confirm', 'reject', 'withdraw'].contains(action) &&
          (subject == null || c['pending_resolution']?['id'] != subject)) {
        throw const ApiException('Rencana yang ditinjau berubah.');
      }
    } else if (action == 'dispatch') {
      if (c['can_dispatch'] != true ||
          fields['handler'] is! String ||
          (fields['handler'] as String).trim().isEmpty ||
          fields['lines'] is! List ||
          (fields['lines'] as List).isEmpty) {
        throw const ApiException(
          'Isi petugas dan minimal satu pengiriman yang diizinkan.',
        );
      }
      for (final l in fields['lines']) {
        final matches = (c['quota'] as List).where(
          (q) => q['shipment_line_id'] == l['original_shipment_line_id'],
        );
        if (matches.length != 1) {
          throw const ApiException('Baris pengganti tidak valid.');
        }
        final q = matches.single;
        var total = 0;
        for (final a in l['allocations']) {
          final batches = (c['batches'] as List).where(
            (b) =>
                b['id'] == a['batch_id'] &&
                b['product_id'] ==
                    q[repository.returns
                        ? 'allocation_product_id'
                        : 'supplier_product_id'] &&
                b['pack_size'] == q['pack_size'],
          );
          if (a['quantity_packs'] is! int ||
              a['quantity_packs'] < 1 ||
              batches.length != 1 ||
              a['quantity_packs'] * q['pack_size'] >
                  batches.single[repository.returns
                      ? 'quarantined_pieces'
                      : 'usable_pieces']) {
            throw const ApiException('Periksa batch/jumlah fisik.');
          }
          total += a['quantity_packs'] as int;
        }
        if (total > q['remaining_packs']) {
          throw const ApiException('Jumlah melebihi sisa kuota pengganti.');
        }
      }
    } else if (action == 'receive') {
      final rows = (c['data'] as List).where(
        (s) => s['id'] == subject && s['can_receive'] == true,
      );
      if (rows.length != 1 ||
          fields['lines'] is! List ||
          (fields['lines'] as List).length !=
              (rows.single['lines'] as List).length) {
        throw const ApiException(
          'Konfirmasikan semua baris kiriman yang diizinkan.',
        );
      }
      final ids = <String>{};
      for (final l in fields['lines']) {
        final matches = (rows.single['lines'] as List).where(
          (s) =>
              s['id'] ==
              l[repository.returns ? 'return_line_id' : 'replacement_line_id'],
        );
        final key = repository.returns
            ? 'return_line_id'
            : 'replacement_line_id';
        final good = l[repository.returns ? 'received_packs' : 'good_packs'];
        final damaged = repository.returns ? 0 : l['damaged_packs'];
        if (matches.length != 1 ||
            !ids.add(l[key]) ||
            good is! int ||
            damaged is! int ||
            good < 0 ||
            damaged < 0 ||
            good + damaged > matches.single['quantity_packs'] ||
            (good != matches.single['quantity_packs'] &&
                (l['condition_note'] ?? '').trim().isEmpty)) {
          throw const ApiException(
            'Periksa jumlah aktual dan catatan selisih.',
          );
        }
      }
    } else {
      throw const ApiException('Tindakan tidak valid.');
    }
    final suffix = {
      'report': '',
      'note': '/notes',
      'propose': '/resolutions',
      'confirm': '/resolutions/$subject/confirm',
      'reject': '/resolutions/$subject/reject',
      'withdraw': '/resolutions/$subject/withdraw',
      'dispatch': '',
      'receive': '/$subject/receive',
    }[action];
    if (suffix == null) throw const ApiException('Tindakan tidak valid.');
    return _reviewed = CaseOperation(
      '${repository.path}$suffix',
      action,
      {
        'operation_id': const Uuid().v4(),
        if (action == 'report')
          'order_version': c['order']['version']
        else
          'version': repository.physical
              ? c['case_version']
              : c['case']['version'],
        ...fields,
        if (repository.returns && action == 'dispatch')
          'destination_bucket': 'quarantined',
      },
      c,
      subject: subject,
    );
  }

  Future<void> confirm(CaseOperation op) async {
    if (!editable || !identical(op, _reviewed)) return;
    pending = op;
    _reviewed = null;
    await send(false);
  }

  Future<void> retry() async {
    if (!busy && pending != null) await send(true);
  }

  Future<void> send(bool wasUnknown) async {
    busy = true;
    fresh = false;
    saved = false;
    error = null;
    changed();
    try {
      await repository.submit(pending!);
      saved = true;
      pending = null;
      context = null;
      context = await repository.load();
      fresh = true;
      epoch++;
    } catch (e) {
      final unknown =
          e is! ApiException ||
          e.status == 0 ||
          e.status >= 500 ||
          e.status == 408 ||
          e.status == 429;
      if (!wasUnknown && !unknown) pending = null;
      error = saved
          ? 'Tersimpan, tetapi detail terbaru belum tersedia. $e'
          : '$e';
    } finally {
      busy = false;
      changed();
    }
  }

  @override
  void dispose() {
    disposed = true;
    super.dispose();
  }
}
