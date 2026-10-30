class LedgerFilterException implements Exception {
  const LedgerFilterException(this.message);
  final String message;
  @override
  String toString() => message;
}

const stockSourceLabels = {
  'warehouse_intake': 'Penerimaan awal gudang',
  'shipment_dispatch': 'Pengiriman',
  'shipment_receipt': 'Penerimaan pesanan',
  'inventory_open': 'Buka kemasan',
  'inventory_cook': 'Pengukusan',
  'stock_count': 'Stok opname',
  'stock_waste': 'Susut disetujui',
  'pos_sale': 'Penjualan POS',
};
const stockBucketLabels = {
  'usable': 'Utuh',
  'opened': 'Beku terbuka',
  'ready': 'Siap jual',
  'quarantined': 'Karantina',
  'waste': 'Susut',
};

class LedgerFilter {
  const LedgerFilter({
    required this.from,
    required this.to,
    this.query = '',
    this.batchId,
    this.source,
    this.bucket,
  });
  final DateTime from, to;
  final String query;
  final String? batchId, source, bucket;
  static String day(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
  static DateTime parseDay(String input) {
    final d = DateTime.tryParse(input);
    if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(input) ||
        d == null ||
        day(d) != input) {
      throw const LedgerFilterException(
        'Isi tanggal kalender YYYY-MM-DD yang valid.',
      );
    }
    return DateTime.utc(d.year, d.month, d.day);
  }

  factory LedgerFilter.form(
    String from,
    String to, {
    String query = '',
    String? batchId,
    String? source,
    String? bucket,
  }) {
    final start = parseDay(from.trim()), end = parseDay(to.trim());
    final days = end.difference(start).inDays + 1;
    if (days < 1 || days > 31 || query.trim().length > 100) {
      throw const LedgerFilterException(
        'Rentang maksimal 31 hari, tanggal akhir tidak mendahului awal, dan pencarian maksimal 100 karakter.',
      );
    }
    return LedgerFilter(
      from: start,
      to: end,
      query: query.trim(),
      batchId: batchId,
      source: source,
      bucket: bucket,
    );
  }
  Map<String, String> get params => {
    'from': '${day(from)}T00:00:00+07:00',
    'to': '${day(to.add(const Duration(days: 1)))}T00:00:00+07:00',
    if (query.isNotEmpty) 'q': query,
    if (batchId != null) 'batch_id': batchId!,
    if (source != null) 'source_type': source!,
    if (bucket != null) 'bucket': bucket!,
  };
  LedgerFilter allBatches() => LedgerFilter(
    from: from,
    to: to,
    query: query,
    source: source,
    bucket: bucket,
  );
}

class StockMovement {
  StockMovement.fromJson(Map<String, dynamic> j)
    : id = j['id'] as String,
      batchId = j['inventory_batch_id'] as String,
      sku = j['sku'] as String,
      name = j['name'] as String,
      batch = j['batch_code'] as String,
      condition = j['condition'] as String,
      packSize = j['pack_size'] as int,
      quantity = j['quantity_pieces'] as int,
      bucket = j['bucket'] as String,
      source = j['source_type'] as String,
      sourceId = j['source_id'] as String,
      actor = j['actor_id'] as int,
      occurredAt = _timestamp(j),
      note = j['note'] as String?;
  static String _timestamp(Map<String, dynamic> j) {
    final raw = (j['occurred_at'] ?? j['created_at']) as String;
    return RegExp(r'(Z|[+-]\d{2}:\d{2})$').hasMatch(raw) ? raw : '${raw}Z';
  }

  final String id,
      batchId,
      sku,
      name,
      batch,
      condition,
      bucket,
      source,
      sourceId,
      occurredAt;
  final String? note;
  final int packSize, quantity, actor;
}

class StockLedgerPage {
  const StockLedgerPage(
    this.rows,
    this.storeName,
    this.observedAt,
    this.page,
    this.lastPage,
  );
  final List<StockMovement> rows;
  final String storeName, observedAt;
  final int page, lastPage;
}
