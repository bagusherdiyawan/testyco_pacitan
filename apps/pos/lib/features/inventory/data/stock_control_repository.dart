import '../../../core/network/api_client.dart';
import '../../../core/network/paged_records.dart';
import '../domain/stock_batch.dart';
import '../domain/stock_control.dart';

class StockControlRepository {
  const StockControlRepository(this.api);
  final ApiClient api;
  String _store(String store) =>
      'stores/${Uri.encodeComponent(store)}/inventory';
  Future<StockControlContext> context(String store) async =>
      StockControlContext.fromJson(
        (await api.request('GET', '${_store(store)}/control-context'))['data']
            as Map<String, dynamic>,
      );
  Future<List<StockBatch>> batches(String store) async => (await PagedRecords(
    api,
  ).load('${_store(store)}/batches')).map(StockBatch.fromJson).toList();
  Future<List<StockCount>> counts(String store) async => (await PagedRecords(
    api,
  ).load('${_store(store)}/counts')).map(StockCount.fromJson).toList();
  Future<List<StockProcess>> processes(String store) async =>
      (await PagedRecords(
        api,
      ).load('${_store(store)}/processes')).map(StockProcess.fromJson).toList();
  Future<void> post(String path, Map<String, dynamic> body) async {
    await api.request('POST', path, body);
  }
}
