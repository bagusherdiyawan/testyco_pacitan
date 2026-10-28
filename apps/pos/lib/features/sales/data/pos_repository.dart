import '../../../core/network/api_client.dart';
import '../../../core/network/paged_records.dart';
import '../../catalog/domain/catalog_product.dart';

class PosRepository {
  const PosRepository(this.api);
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
}
