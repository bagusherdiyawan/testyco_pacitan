import 'dart:convert';

class IntakeProduct {
  const IntakeProduct(
    this.id,
    this.sku,
    this.name,
    this.condition,
    this.packSize,
  );
  factory IntakeProduct.fromJson(Map<String, dynamic> j) => IntakeProduct(
    j['id'] as String,
    j['sku'] as String,
    j['name'] as String,
    j['condition'] as String,
    j['pack_size'] as int,
  );
  final String id, sku, name, condition;
  final int packSize;
}

class IntakeCatalogue {
  const IntakeCatalogue(this.storeName, this.products);
  final String storeName;
  final List<IntakeProduct> products;
}

class IntakeReview {
  IntakeReview(Map<String, dynamic> body, Map<String, dynamic> snapshot)
    : _body = jsonEncode(body),
      _snapshot = jsonEncode(snapshot);
  final String _body, _snapshot;
  Map<String, dynamic> get body => jsonDecode(_body) as Map<String, dynamic>;
  Map<String, dynamic> get snapshot =>
      jsonDecode(_snapshot) as Map<String, dynamic>;
}

class PendingIntake {
  PendingIntake(this.path, Map<String, dynamic> body)
    : _body = jsonEncode(body);
  final String path, _body;
  Map<String, dynamic> get body => jsonDecode(_body) as Map<String, dynamic>;
}
