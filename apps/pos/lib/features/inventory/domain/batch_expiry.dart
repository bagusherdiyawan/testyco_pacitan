import '../../../core/network/api_client.dart';
import 'stock_batch.dart';

class BatchExpiryFilter {
  const BatchExpiryFilter._(this.from, this.to, this.query, this.withBalance);
  const BatchExpiryFilter() : this._('', '', '', false);
  final String from, to, query;
  final bool withBalance;
  static bool isDay(String raw) {
    if (!RegExp(r'^[0-9]{4}-[0-9]{2}-[0-9]{2}$').hasMatch(raw)) return false;
    final y = int.parse(raw.substring(0, 4)),
        m = int.parse(raw.substring(5, 7)),
        d = int.parse(raw.substring(8, 10));
    final date = DateTime.utc(y, m, d);
    return y >= 1 && date.year == y && date.month == m && date.day == d;
  }

  factory BatchExpiryFilter.form(
    String from,
    String to, {
    String query = '',
    bool withBalance = false,
  }) {
    final start = from.trim(), end = to.trim(), search = query.trim();
    if ((start.isNotEmpty && !isDay(start)) ||
        (end.isNotEmpty && !isDay(end))) {
      throw const ApiException(
        'Isi tanggal kemasan YYYY-MM-DD yang valid, atau kosongkan batasnya.',
      );
    }
    if (start.isNotEmpty && end.isNotEmpty && start.compareTo(end) > 0) {
      throw const ApiException(
        'Tanggal akhir tidak boleh mendahului tanggal awal.',
      );
    }
    if (search.length > 100) {
      throw const ApiException('Pencarian maksimal 100 karakter.');
    }
    return BatchExpiryFilter._(start, end, search, withBalance);
  }
  Map<String, String> get params => {
    if (from.isNotEmpty) 'expires_from': from,
    if (to.isNotEmpty) 'expires_to': to,
    if (query.isNotEmpty) 'q': query,
    'with_balance': withBalance ? '1' : '0',
  };
}

class BatchExpiryPage {
  BatchExpiryPage({
    required List<StockBatch> rows,
    required this.storeName,
    required this.active,
    required this.observedAt,
    required this.page,
    required this.lastPage,
    required this.total,
  }) : rows = List.unmodifiable(rows);
  final List<StockBatch> rows;
  final String storeName, observedAt;
  final bool active;
  final int page, lastPage, total;
}
