import '../../../core/network/api_client.dart';
import '../../../core/network/paged_records.dart';
import '../domain/order_entry.dart';

class OrderEntryRepository {
  const OrderEntryRepository(this.api);
  final ApiClient api;
  String _store(String id) => 'stores/${Uri.encodeComponent(id)}';
  Future<OrderEntryContext> context(String store) async =>
      OrderEntryContext.fromJson(
        (await api.request(
              'GET',
              '${_store(store)}/operations-context',
            ))['data']
            as Map<String, dynamic>,
      );
  Future<List<OrderProductChoice>> suppliers(String store) async =>
      (await PagedRecords(api).load(
        '${_store(store)}/supply-catalog',
      )).map(OrderProductChoice.fromJson).toList();
  Future<List<OrderProductChoice>> locals(String store) async =>
      (await PagedRecords(api).load(
        '${_store(store)}/catalog',
      )).map(OrderProductChoice.fromJson).toList();
  Future<OrderEntryPreview> preview(
    String store,
    Map<String, dynamic> body,
  ) async => OrderEntryPreview.fromJson(
    (await api.request('POST', '${_store(store)}/orders/preview', body))['data']
        as Map<String, dynamic>,
  );
  Future<String> create(String store, Map<String, dynamic> body) async =>
      (await api.request('POST', '${_store(store)}/orders', body))['data']['id']
          as String;
  Future<EditableOrderSeed> seed(String id) async => EditableOrderSeed.fromJson(
    (await api.request('GET', 'orders/${Uri.encodeComponent(id)}'))['data']
        as Map<String, dynamic>,
  );
  Future<OrderEntryPreview> previewAmend(
    String id,
    Map<String, dynamic> body,
  ) async => OrderEntryPreview.fromJson(
    (await api.request(
          'POST',
          'orders/${Uri.encodeComponent(id)}/preview',
          body,
        ))['data']
        as Map<String, dynamic>,
  );
  Future<String> amend(String id, Map<String, dynamic> body) async =>
      (await api.request(
            'PUT',
            'orders/${Uri.encodeComponent(id)}',
            body,
          ))['data']['id']
          as String;
}
