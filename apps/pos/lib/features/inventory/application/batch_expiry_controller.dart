import 'package:flutter/foundation.dart';
import '../data/batch_expiry_repository.dart';
import '../domain/batch_expiry.dart';

class BatchExpiryController extends ChangeNotifier {
  BatchExpiryController(this.repository, this.store);
  final BatchExpiryRepository repository;
  final String store;
  BatchExpiryFilter filter = const BatchExpiryFilter();
  BatchExpiryPage? result;
  bool busy = false, _disposed = false;
  int page = 1;
  String? error;
  void _notify() {
    if (!_disposed) notifyListeners();
  }

  Future<void> load({BatchExpiryFilter? next, int? nextPage}) async {
    if (busy) return;
    if (next != null) {
      filter = next;
      page = 1;
    }
    page = nextPage ?? page;
    busy = true;
    result = null;
    error = null;
    _notify();
    try {
      result = await repository.load(store, filter, page: page);
    } catch (e) {
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
