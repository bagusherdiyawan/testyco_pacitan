import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../../../core/network/api_client.dart';
import '../data/warehouse_intake_repository.dart';
import '../domain/warehouse_intake.dart';

class WarehouseIntakeController extends ChangeNotifier {
  WarehouseIntakeController(this.repository, this.store);
  final WarehouseIntakeRepository repository;
  final String store;
  IntakeCatalogue? catalogue;
  IntakeReview? review;
  PendingIntake? pending;
  String? savedId, error;
  bool busy = false, fresh = false, _disposed = false;
  bool get editable =>
      !busy && fresh && review == null && pending == null && savedId == null;
  void _notify() {
    if (!_disposed) notifyListeners();
  }

  Future<void> load() async {
    if (busy || pending != null || savedId != null) return;
    busy = true;
    fresh = false;
    review = null;
    error = null;
    _notify();
    try {
      catalogue = await repository.catalogue(store);
      fresh = true;
    } catch (e) {
      error = '$e';
    } finally {
      busy = false;
      _notify();
    }
  }

  bool edit() {
    if (busy || pending != null || savedId != null) return false;
    review = null;
    error = null;
    _notify();
    return true;
  }

  Future<void> preview(
    String? product,
    String batch,
    String expiry,
    String quantity,
    String note,
  ) async {
    if (!editable) return;
    final count = RegExp(r'^[0-9]+$').hasMatch(quantity.trim())
        ? int.tryParse(quantity.trim())
        : null;
    final date = DateTime.tryParse(expiry.trim());
    if (!catalogue!.products.any((p) => p.id == product) ||
        batch.trim().isEmpty ||
        batch.trim().length > 80 ||
        !RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(expiry.trim()) ||
        date == null ||
        '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}' !=
            expiry.trim() ||
        count == null ||
        count < 1 ||
        count > 10000 ||
        note.trim().isEmpty ||
        note.trim().length > 2000) {
      error =
          'Pilih SKU, isi batch (maksimal 80 karakter), tanggal YYYY-MM-DD yang valid, 1–10000 kemasan dan catatan pemeriksaan (maksimal 2000 karakter).';
      _notify();
      return;
    }
    final body = {
      'product_id': product,
      'batch_code': batch.trim(),
      'expires_on': expiry.trim(),
      'quantity_packs': count,
      'note': note.trim(),
    };
    busy = true;
    error = null;
    _notify();
    try {
      final snapshot = await repository.preview(store, body);
      if (snapshot['store_id'] != store ||
          snapshot['product_id'] != product ||
          snapshot['review_hash'] is! String) {
        throw const ApiException('Respons tinjauan penerimaan tidak sesuai.');
      }
      review = IntakeReview(body, snapshot);
    } catch (e) {
      fresh = false;
      error = '$e';
    } finally {
      busy = false;
      _notify();
    }
  }

  Future<void> confirm() async {
    if (busy || pending != null || review == null || savedId != null) return;
    pending = PendingIntake(repository.path(store), {
      ...review!.body,
      'review_hash': review!.snapshot['review_hash'],
      'operation_id': const Uuid().v4(),
    });
    await _send(wasUnknown: false);
  }

  Future<void> retry() async {
    if (busy || pending == null) return;
    await _send(wasUnknown: true);
  }

  Future<void> _send({required bool wasUnknown}) async {
    busy = true;
    error = null;
    _notify();
    try {
      final result = await repository.record(pending!);
      if (result['id'] is! String ||
          result['store_id'] != store ||
          result['product_id'] != review!.body['product_id']) {
        throw const ApiException('Respons penerimaan belum dapat dipastikan.');
      }
      savedId = result['id'] as String;
      pending = null;
      fresh = false;
      // Never label replayed batch balances as current; the stock page reloads separately.
    } catch (e) {
      final uncertain =
          e is! ApiException ||
          e.status == 0 ||
          e.status >= 500 ||
          e.status == 408 ||
          e.status == 429;
      if (!wasUnknown && !uncertain) {
        pending = null;
        review = null;
        fresh = false;
      }
      error = '$e';
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
