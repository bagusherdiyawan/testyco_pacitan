class StockBatch {
  const StockBatch({
    required this.sku,
    required this.name,
    required this.code,
    required this.expiry,
    required this.packSize,
    required this.usable,
    required this.quarantined,
    this.opened = 0,
    this.ready = 0,
    this.waste = 0,
    this.revision = 0,
  });
  final String sku, name, code, expiry;
  final int packSize, usable, quarantined, opened, ready, waste, revision;
  int get available => usable + opened + ready;
  factory StockBatch.fromJson(Map<String, dynamic> j) => StockBatch(
    sku: j['sku'] as String,
    name: j['name'] as String,
    code: j['batch_code'] as String,
    expiry: j['expires_on'] as String,
    packSize: j['pack_size'] as int,
    usable: j['usable_pieces'] as int,
    quarantined: j['quarantined_pieces'] as int,
    opened: j['opened_pieces'] as int? ?? 0,
    ready: j['ready_pieces'] as int? ?? 0,
    waste: j['waste_pieces'] as int? ?? 0,
    revision: j['stock_revision'] as int? ?? 0,
  );
}
