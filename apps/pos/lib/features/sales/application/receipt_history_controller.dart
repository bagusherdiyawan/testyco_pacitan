import 'package:flutter/foundation.dart';
import '../../../core/domain/wib_date_range.dart';
import '../../../core/network/api_client.dart';
import '../data/pos_repository.dart';
import '../domain/receipt_history.dart';

class ReceiptHistoryController extends ChangeNotifier {
  ReceiptHistoryController(this.repository, this.store);
  final PosRepository repository;
  final String store;
  WibDateRange range = WibDateRange.today();
  String query = '';
  int page = 1;
  bool busy = false, _disposed = false;
  String? error;
  ReceiptHistory? result;
  Map<String, dynamic>? receipt;
  String? selectedId;
  void _notify() {
    if (!_disposed) notifyListeners();
  }

  Future<void> load({WibDateRange? next, String? search, int? nextPage}) async {
    if (busy) return;
    if (next != null) {
      range = next;
      query = search ?? '';
      page = 1;
    }
    page = nextPage ?? page;
    selectedId = null;
    await _read();
  }

  Future<void> open(String id) async {
    if (busy) return;
    selectedId = id;
    await _read();
  }

  Future<void> retry() => _read();
  Future<void> _read() async {
    if (busy) return;
    busy = true;
    error = null;
    result = null;
    receipt = null;
    _notify();
    try {
      if (selectedId == null) {
        result = await repository.history(store, range, query, page);
      } else {
        final sale = await repository.receipt(selectedId!);
        if (sale['id'] != selectedId || sale['store_id'] != store) {
          throw const ApiException('Struk bukan milik toko yang dipilih.');
        }
        receipt = sale;
      }
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
