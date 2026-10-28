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
  Future<List<StockBatch>> load(String store) async =>
      (await PagedRecords(api).load(
        'stores/${Uri.encodeComponent(store)}/inventory/batches',
      )).map(StockBatch.fromJson).toList();
}
