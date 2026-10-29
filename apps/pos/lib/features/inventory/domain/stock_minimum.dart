class StockMinimum {
  const StockMinimum({
    this.productId = '',
    this.version,
    required this.sku,
    required this.name,
    required this.available,
    required this.usable,
    required this.opened,
    required this.ready,
    required this.minimum,
    required this.shortfall,
    required this.isLow,
  });
  factory StockMinimum.fromJson(Map<String, dynamic> j) => StockMinimum(
    productId: j['product_id'] as String? ?? '',
    version: j['version'] as int?,
    sku: j['sku'] as String,
    name: j['name'] as String,
    available: j['available_pieces'] as int,
    usable: j['usable_pieces'] as int,
    opened: j['opened_pieces'] as int,
    ready: j['ready_pieces'] as int,
    minimum: j['minimum_pieces'] as int?,
    shortfall: j['shortfall_pieces'] as int,
    isLow: j['is_low'] as bool,
  );
  final String sku, name, productId;
  final int? version;
  final int available, usable, opened, ready, shortfall;
  final int? minimum;
  final bool isLow;
}

class StockMinimumReport {
  const StockMinimumReport({
    required this.rows,
    required this.storeId,
    required this.canManage,
    required this.observedAt,
    this.page = 1,
    this.lastPage = 1,
  });
  final List<StockMinimum> rows;
  final String storeId, observedAt;
  final bool canManage;
  final int page, lastPage;
}

class StockMinimumDetail {
  const StockMinimumDetail(
    this.row,
    this.storeId,
    this.canManage,
    this.observedAt,
  );
  final StockMinimum row;
  final String storeId, observedAt;
  final bool canManage;
}
