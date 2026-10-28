import '../../../core/network/api_client.dart';
import '../domain/catalog_product.dart';

class CatalogRepository {
  const CatalogRepository(this.api);
  final ApiClient api;
  Future<List<CatalogProduct>> load(String store) async {
    final items = <CatalogProduct>[];
    String? path = 'stores/${Uri.encodeComponent(store)}/catalog';
    while (path != null) {
      final response = await api.request('GET', path);
      items.addAll(
        (response['data'] as List).map(
          (j) => CatalogProduct.fromJson(j as Map<String, dynamic>),
        ),
      );
      final next = response['next_page_url'] as String?;
      final page = next == null
          ? null
          : Uri.parse(next).queryParameters['page'];
      path = page == null
          ? null
          : 'stores/${Uri.encodeComponent(store)}/catalog?page=$page';
    }
    return items;
  }
}
