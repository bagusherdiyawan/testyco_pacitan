import '../../../core/domain/wib_date_range.dart';
import '../../../core/storage/device_state.dart';
import '../domain/receipt_history.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/paged_records.dart';
import '../../catalog/domain/catalog_product.dart';

class PosRepository {
  const PosRepository(this.api, {this.deviceState});
  final DeviceState? deviceState;
  final ApiClient api;
  Future<Map<String, dynamic>> context(String store) async =>
      (await api.request('GET', 'stores/$store/pos/context'))['data']
          as Map<String, dynamic>;
  Future<List<CatalogProduct>> products(String store) async =>
      (await PagedRecords(
        api,
      ).load('stores/$store/catalog')).map(CatalogProduct.fromJson).toList();
  Future<List<Map<String, dynamic>>> batches(String store) =>
      PagedRecords(api).load('stores/$store/inventory/batches');
  Future<Map<String, dynamic>> post(
    String path,
    Map<String, dynamic> body,
  ) async =>
      (await api.request('POST', path, body))['data'] as Map<String, dynamic>;
  Future<Map<String, dynamic>> intent(String id) async =>
      (await api.request('GET', 'sales/intents/$id'))['data']
          as Map<String, dynamic>;
  Future<Map<String, dynamic>> receipt(String id) async =>
      (await api.request('GET', 'sales/$id'))['data'] as Map<String, dynamic>;
  Future<ReceiptHistory> history(
    String store,
    WibDateRange range,
    String query,
    int page,
  ) async {
    final path = Uri(
      path: 'stores/$store/sales',
      queryParameters: {
        ...range.params,
        if (query.isNotEmpty) 'q': query,
        'page': '$page',
      },
    );
    final r = await api.request('GET', path.toString());
    if ((r['store'] as Map?)?['id'] != store ||
        !['own', 'store'].contains(r['scope'])) {
      throw const ApiException('Riwayat bukan milik toko yang dipilih.');
    }
    final rows = (r['data'] as List).cast<Map<String, dynamic>>();
    if (rows.any((row) => row['store_id'] != store)) {
      throw const ApiException('Struk bukan milik toko yang dipilih.');
    }
    return ReceiptHistory(
      rows,
      (r['store'] as Map)['name'] as String,
      r['scope'] as String,
      r['current_page'] as int,
      r['last_page'] as int,
    );
  }
}
