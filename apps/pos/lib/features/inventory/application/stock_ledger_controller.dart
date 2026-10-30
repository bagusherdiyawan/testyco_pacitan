import 'package:flutter/foundation.dart';
import '../data/stock_ledger_repository.dart';
import '../domain/stock_ledger.dart';

class StockLedgerController extends ChangeNotifier {
  StockLedgerController(this.repository, this.store, {String? batchId}) {
    final now = DateTime.now().toUtc().add(const Duration(hours: 7));
    final day = DateTime.utc(now.year, now.month, now.day);
    filter = LedgerFilter(from: day, to: day, batchId: batchId);
  }
  final StockLedgerRepository repository;
  final String store;
  late LedgerFilter filter;
  StockLedgerPage? result;
  bool busy = false, _disposed = false;
  String? error;
  int page = 1;
  void _notify() {
    if (!_disposed) notifyListeners();
  }

  Future<void> load({LedgerFilter? next, int? nextPage}) async {
    if (busy) return;
    if (next != null) {
      filter = next;
      page = 1;
    }
    page = nextPage ?? page;
    busy = true;
    error = null;
    result = null;
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
