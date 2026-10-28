class StockBatch {
  const StockBatch({
    required this.sku,
    required this.name,
    required this.code,
    required this.expiry,
    required this.packSize,
    required this.usable,
    required this.quarantined,
  });
  final String sku, name, code, expiry;
  final int packSize, usable, quarantined;
  factory StockBatch.fromJson(Map<String, dynamic> j) => StockBatch(
    sku: j['sku'] as String,
    name: j['name'] as String,
    code: j['batch_code'] as String,
    expiry: j['expires_on'] as String,
    packSize: j['pack_size'] as int,
    usable: j['usable_pieces'] as int,
    quarantined: j['quarantined_pieces'] as int,
  );
}
