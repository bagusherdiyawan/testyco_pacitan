import '../../catalog/domain/catalog_product.dart';

class CartEntry {
  CartEntry(this.product);
  final CatalogProduct product;
  String unit = 'piece';
  int quantity = 1;
  String? stock;
  final componentStocks = <String, String>{};
  int get piecesPerUnit => unit == 'pack'
      ? product.packSize
      : unit == 'portion'
      ? product.portionSize
      : 1;
}
