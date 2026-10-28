class StockMinimum {
  const StockMinimum({
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
  final String sku, name;
  final int available, usable, opened, ready, shortfall;
  final int? minimum;
  final bool isLow;
}
