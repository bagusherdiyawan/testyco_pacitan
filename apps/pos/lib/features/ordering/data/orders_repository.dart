import '../../../core/network/api_client.dart';
import '../../../core/network/paged_records.dart';

class OrdersRepository {
  const OrdersRepository(this.api);
  final ApiClient api;
  Future<List<Map<String, dynamic>>> load(String store) =>
      PagedRecords(api).load('stores/${Uri.encodeComponent(store)}/orders');
  Future<Map<String, dynamic>> details(String id) async =>
      (await api.request('GET', 'orders/${Uri.encodeComponent(id)}'))['data']
          as Map<String, dynamic>;
  Future<List<Map<String, dynamic>>> shipments(String id) async =>
      ((await api.request(
                'GET',
                'orders/${Uri.encodeComponent(id)}/shipments',
              ))['data']
              as List)
          .cast<Map<String, dynamic>>();
}
