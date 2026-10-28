import '../../../core/network/api_client.dart';
import '../../../core/network/paged_records.dart';
import '../domain/stock_batch.dart';

class InventoryRepository {
  const InventoryRepository(this.api);
  final ApiClient api;
  Future<List<StockBatch>> load(String store) async =>
      (await PagedRecords(api).load(
        'stores/${Uri.encodeComponent(store)}/inventory/batches',
      )).map(StockBatch.fromJson).toList();
}
