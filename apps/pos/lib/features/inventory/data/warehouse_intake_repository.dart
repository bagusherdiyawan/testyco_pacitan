import '../../../core/network/api_client.dart';
import '../domain/warehouse_intake.dart';

class WarehouseIntakeRepository {
  const WarehouseIntakeRepository(this.api);
  final ApiClient api;
  String path(String store) => 'stores/$store/inventory/intakes';
  Future<IntakeCatalogue> catalogue(String store) async {
    final products = <IntakeProduct>[];
    var page = 1;
    var name = '';
    while (true) {
      final r = await api.request(
        'GET',
        'stores/$store/inventory/intake-products?page=$page',
      );
      final source = r['store'] as Map?;
      if (source?['id'] != store ||
          source?['active'] != true ||
          r['can_intake'] != true) {
        throw const ApiException(
          'Penerimaan gudang tidak diizinkan untuk toko ini.',
        );
      }
      name = source!['name'] as String;
      products.addAll(
        (r['data'] as List).map(
          (v) => IntakeProduct.fromJson(v as Map<String, dynamic>),
        ),
      );
      if (page >= (r['last_page'] as int? ?? 1)) break;
      page++;
    }
    return IntakeCatalogue(name, products);
  }

  Future<Map<String, dynamic>> preview(
    String store,
    Map<String, dynamic> body,
  ) async =>
      (await api.request('POST', '${path(store)}/preview', body))['data']
          as Map<String, dynamic>;
  Future<Map<String, dynamic>> record(PendingIntake operation) async =>
      (await api.request('POST', operation.path, operation.body))['data']
          as Map<String, dynamic>;
}
