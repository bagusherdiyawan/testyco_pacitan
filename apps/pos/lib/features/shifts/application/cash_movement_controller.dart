import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../../../core/network/api_client.dart';
import '../data/shifts_repository.dart';
import '../domain/cash_shift.dart';

class CashMovementController extends ChangeNotifier {
  CashMovementController(this.repository, this.storeId, this.shiftId);
  final ShiftsRepository repository;
  final String storeId, shiftId;
  CashShift? shift;
  CashMovementDraft? pending;
  Map<String, dynamic>? savedMovement;
  bool busy = false, fresh = false, _disposed = false;
  String? error;
  int formEpoch = 0;
  bool get editable =>
      !busy && fresh && pending == null && shift?.canRecord == true;
  void _notify() {
    if (!_disposed) notifyListeners();
  }

  Future<void> _read() async {
    final next = await repository.detail(shiftId);
    if (next.id != shiftId || next.storeId != storeId) {
      throw const ApiException('Shift bukan milik toko yang dipilih.');
    }
    shift = next;
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

  CashMovementDraft prepare(
    String kind,
    String amountText,
    String category,
    String reason,
  ) {
    if (!editable) {
      throw const ApiException(
        'Muat ulang shift dan periksa izin sebelum mencatat kas.',
      );
    }
    final amount = int.tryParse(amountText);
    if (!['in', 'out'].contains(kind) ||
        amount == null ||
        amount < 1 ||
        amount > 1000000000000) {
      throw const ApiException(
        'Isi nilai rupiah bulat positif, maksimal Rp 1 triliun.',
      );
    }
    if (category.trim().isEmpty ||
        category.trim().length > 80 ||
        reason.trim().isEmpty ||
        reason.trim().length > 2000) {
      throw const ApiException(
        'Isi kategori (maksimal 80 karakter) dan alasan (maksimal 2000 karakter).',
      );
    }
    if (kind == 'out' && amount > shift!.expected) {
      throw const ApiException('Kas keluar melebihi kas yang diharapkan.');
    }
    return CashMovementDraft(shiftId, {
      'operation_id': const Uuid().v4(),
      'version': shift!.version,
      'kind': kind,
      'amount': amount,
      'category': category.trim(),
      'reason': reason.trim(),
    });
  }

  Future<void> confirm(CashMovementDraft draft) async {
    if (!editable ||
        draft.shiftId != shiftId ||
        draft.body['version'] != shift!.version) {
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
    error = null;
    savedMovement = null;
    _notify();
    try {
      final result = await repository.record(pending!);
      savedMovement = result['movement'] as Map<String, dynamic>;
      pending = null;
      // A replay can return an old balance: always fetch current detail separately.
      await _read();
    } catch (e) {
      final uncertain =
          e is! ApiException ||
          e.status == 0 ||
          e.status >= 500 ||
          e.status == 408 ||
          e.status == 429;
      // A later denial cannot establish whether the earlier unknown request committed.
      if (!wasUnknown && !uncertain) pending = null;
      error = savedMovement != null
          ? 'Kas tersimpan. Detail terbaru gagal dimuat; muat ulang sebelum tindakan berikutnya. $e'
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
