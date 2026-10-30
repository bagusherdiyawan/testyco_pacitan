import '../../../core/network/api_client.dart';
import '../domain/warehouse_context.dart';

class WarehouseRepository {
  const WarehouseRepository(this.api);
  final ApiClient api;
  Future<WarehouseInbox> inbox(String store, {int page = 1}) async {
    final r = await api.request(
      'GET',
      'stores/$store/fulfillment/orders?page=$page',
    );
    if ((r['source'] as Map)['id'] != store) {
      throw const ApiException('Gudang tidak sesuai toko yang dipilih.');
    }
    return WarehouseInbox(
      (r['data'] as List).cast<Map<String, dynamic>>(),
      r['source'] as Map<String, dynamic>,
      r['current_page'] as int? ?? page,
      r['last_page'] as int? ?? 1,
    );
  }

  Future<WarehouseContext> context(String store, String order) async =>
      WarehouseContext(
        (await api.request(
              'GET',
              'stores/$store/fulfillment/orders/$order',
            ))['data']
            as Map<String, dynamic>,
      );
  Future<void> submit(WarehouseOperation operation) async {
    await api.request('POST', operation.path, operation.body);
  }
}
