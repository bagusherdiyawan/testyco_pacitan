import '../../../core/network/api_client.dart';
import '../domain/order_change.dart';

class OrderChangesRepository {
  const OrderChangesRepository(this.api, {this.advanced = false});
  final bool advanced;
  final ApiClient api;
  String path(String id) =>
      'orders/${Uri.encodeComponent(id)}/${advanced ? 'revisions' : 'changes'}';
  Future<OrderChangeContext> context(String id, {int page = 1}) async {
    final result = OrderChangeContext.fromJson(
      await api.request('GET', '${path(id)}?page=$page'),
    );
    if (result.rows.any(
          (r) => r.kind != (advanced ? 'revision' : 'reduction'),
        ) ||
        result.orderId != id ||
        result.rows.any((v) => v.orderId != id) ||
        result.page != page ||
        result.lastPage < 1 ||
        result.total < 0 ||
        result.version < 1 ||
        result.rows.map((v) => v.id).toSet().length != result.rows.length) {
      throw const ApiException('Cakupan riwayat usulan tidak sesuai pesanan.');
    }
    return result;
  }

  Future<Map<String, dynamic>> preview(
    String id,
    Map<String, dynamic> body,
  ) async =>
      (await api.request('POST', '${path(id)}/preview', body))['data']
          as Map<String, dynamic>;

  Future<void> submit(String id, PendingOrderChange operation) async {
    final result = (await api.request(
      'POST',
      operation.path,
      operation.body,
    ))['data'];
    if (result is! Map<String, dynamic>) {
      throw const ApiException('Respons pencatatan belum dapat dipastikan.');
    }
    final order = result['order'],
        change = result[advanced ? 'revision' : 'change'];
    if (order is! Map ||
        change is! Map ||
        order['id'] != id ||
        order['version'] != (operation.body['version'] as int) + 1 ||
        change['supply_order_id'] != id ||
        change['status'] != operation.action ||
        change['id'] is! String ||
        (change['id'] as String).isEmpty ||
        (operation.changeId != null && change['id'] != operation.changeId)) {
      throw const ApiException('Respons pencatatan belum dapat dipastikan.');
    }
  }
}
