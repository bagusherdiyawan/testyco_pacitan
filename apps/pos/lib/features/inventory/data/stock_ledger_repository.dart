import '../../../core/network/api_client.dart';
import '../domain/stock_ledger.dart';

class StockLedgerRepository {
  const StockLedgerRepository(this.api);
  final ApiClient api;
  Future<StockLedgerPage> load(
    String store,
    LedgerFilter filter, {
    int page = 1,
  }) async {
    final path = Uri(
      path: 'stores/$store/inventory/ledger',
      queryParameters: {...filter.params, 'page': '$page'},
    );
    final r = await api.request('GET', path.toString());
    if ((r['store'] as Map?)?['id'] != store) {
      throw const ApiException('Riwayat bukan milik toko yang dipilih.');
    }
    return StockLedgerPage(
      (r['data'] as List)
          .map((v) => StockMovement.fromJson(v as Map<String, dynamic>))
          .toList(),
      (r['store'] as Map)['name'] as String,
      r['observed_at'] as String,
      r['current_page'] as int? ?? page,
      r['last_page'] as int? ?? 1,
    );
  }
}
