import '../../../core/network/api_client.dart';
import '../domain/batch_expiry.dart';
import '../domain/stock_batch.dart';

class BatchExpiryRepository {
  const BatchExpiryRepository(this.api);
  final ApiClient api;
  Future<BatchExpiryPage> load(
    String store,
    BatchExpiryFilter filter, {
    int page = 1,
  }) async {
    if (page < 1) throw const ApiException('Halaman batch tidak valid.');
    final path = Uri.parse(
      'stores/${Uri.encodeComponent(store)}/inventory/batches',
    ).replace(queryParameters: {...filter.params, 'page': '$page'});
    final r = await api.request('GET', path.toString());
    final scope = r['store'], data = r['data'], time = r['observed_at'];
    if (scope is! Map ||
        scope['id'] != store ||
        scope['name'] is! String ||
        scope['active'] is! bool ||
        data is! List ||
        data.length > 100 ||
        r['current_page'] != page ||
        r['last_page'] is! int ||
        (r['last_page'] as int) < 1 ||
        r['total'] is! int ||
        (r['total'] as int) < 0 ||
        r['per_page'] != 100 ||
        time is! String ||
        DateTime.tryParse(time) == null ||
        !RegExp(r'(Z|[+-][0-9]{2}:[0-9]{2})$').hasMatch(time)) {
      throw const ApiException('Cakupan atau metadata batch API tidak valid.');
    }
    final rows = <StockBatch>[];
    for (final raw in data) {
      if (raw is! Map<String, dynamic> || raw['store_id'] != store) {
        throw const ApiException('Batch bukan milik toko yang dipilih.');
      }
      final b = StockBatch.fromJson(raw);
      if (b.id.isEmpty ||
          b.packSize < 1 ||
          !BatchExpiryFilter.isDay(b.expiry) ||
          [
            b.usable,
            b.opened,
            b.ready,
            b.quarantined,
            b.waste,
            b.revision,
          ].any((v) => v < 0)) {
        throw const ApiException('Snapshot batch API tidak valid.');
      }
      rows.add(b);
    }
    if (rows.map((b) => b.id).toSet().length != rows.length) {
      throw const ApiException('Identitas batch API duplikat.');
    }
    return BatchExpiryPage(
      rows: rows,
      storeName: scope['name'] as String,
      active: scope['active'] as bool,
      observedAt: time,
      page: page,
      lastPage: r['last_page'] as int,
      total: r['total'] as int,
    );
  }
}
