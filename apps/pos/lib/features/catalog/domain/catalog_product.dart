class CatalogProduct {
  const CatalogProduct({
    required this.id,
    required this.sku,
    required this.name,
    required this.packSize,
    required this.portionSize,
    required this.retailPrice,
    required this.condition,
    this.recipe,
    this.hasImage = false,
  });
  final String id, sku, name, condition;
  final bool hasImage;
  final Map<String, dynamic>? recipe;
  bool get isBundle => recipe != null;
  List<Map<String, dynamic>> get components =>
      ((recipe?['components'] as List?) ?? []).cast<Map<String, dynamic>>();
  final int packSize, portionSize, retailPrice;
  factory CatalogProduct.fromJson(Map<String, dynamic> j) => CatalogProduct(
    id: j['id'] as String,
    sku: j['sku'] as String,
    name: j['name'] as String,
    packSize: j['pack_size'] as int,
    portionSize: j['portion_size'] as int,
    retailPrice: j['retail_price'] as int,
    condition: j['condition'] as String,
    recipe: j['bundle_recipe'] as Map<String, dynamic>?,
    hasImage: j['has_image'] == true,
  );
}
