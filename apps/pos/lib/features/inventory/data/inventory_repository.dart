import '../../../core/network/api_client.dart';
import '../../../core/network/paged_records.dart';
import '../domain/stock_batch.dart';
import '../domain/stock_minimum.dart';

class InventoryRepository {
  const InventoryRepository(this.api);
  final ApiClient api;
  Future<List<StockMinimum>> minimums(
    String store, {
    required bool lowOnly,
  }) async => (await PagedRecords(api).load(
    'stores/${Uri.encodeComponent(store)}/inventory/minimums?low_only=${lowOnly ? 1 : 0}',
  )).map(StockMinimum.fromJson).toList();
  Future<StockMinimumReport> minimumPage(
    String store, {
    required bool lowOnly,
    int page = 1,
    String query = '',
  }) async {
    final path = Uri(
      path: 'stores/$store/inventory/minimums',
      queryParameters: {
        'low_only': lowOnly ? '1' : '0',
        'page': '$page',
        if (query.trim().isNotEmpty) 'q': query.trim(),
      },
    );
    final r = await api.request('GET', path.toString());
    return StockMinimumReport(
      rows: (r['data'] as List)
          .map((v) => StockMinimum.fromJson(v as Map<String, dynamic>))
          .toList(),
      storeId: (r['store'] as Map?)?['id'] as String? ?? '',
      canManage: r['can_manage'] == true,
      observedAt: r['observed_at'] as String? ?? '',
      page: r['current_page'] as int? ?? page,
      lastPage: r['last_page'] as int? ?? 1,
    );
  }

  Future<StockMinimumDetail> minimumDetail(String store, String product) async {
    final r = await api.request(
      'GET',
      'stores/$store/inventory/minimums/$product',
    );
    return StockMinimumDetail(
      StockMinimum.fromJson(r['data'] as Map<String, dynamic>),
      (r['store'] as Map?)?['id'] as String? ?? '',
      r['can_manage'] == true,
      r['observed_at'] as String? ?? '',
    );
  }

  Future<Map<String, dynamic>> configureMinimum(
    String path,
    Map<String, dynamic> body,
  ) async =>
      (await api.request('POST', path, body))['data'] as Map<String, dynamic>;
  Future<List<StockBatch>> load(String store) async =>
      (await PagedRecords(api).load(
        'stores/${Uri.encodeComponent(store)}/inventory/batches',
      )).map(StockBatch.fromJson).toList();
}
