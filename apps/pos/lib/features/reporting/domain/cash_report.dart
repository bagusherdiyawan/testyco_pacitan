class CashReportPeriod {
  CashReportPeriod(this.from, this.to);
  final DateTime from, to;
  static String day(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
  Map<String, String> get query => {
    'from': '${day(from)}T00:00:00+07:00',
    'to': '${day(DateTime.utc(to.year, to.month, to.day + 1))}T00:00:00+07:00',
  };
  String get label => '${day(from)} — ${day(to)} WIB';
}

class CashMetrics {
  const CashMetrics({
    required this.count,
    required this.gross,
    required this.discount,
    required this.net,
  });
  factory CashMetrics.fromJson(Map<String, dynamic> j) => CashMetrics(
    count: j['transaction_count'] as int,
    gross: j['gross_sales'] as int,
    discount: j['discount_amount'] as int,
    net: j['net_sales'] as int,
  );
  final int count, gross, discount, net;
}

class ReportStore {
  const ReportStore({
    required this.id,
    required this.name,
    required this.active,
  });
  factory ReportStore.fromJson(Map<String, dynamic> j) => ReportStore(
    id: j['id'] as String,
    name: j['name'] as String,
    active: j['active'] as bool,
  );
  final String id, name;
  final bool active;
}

class StoreSales {
  StoreSales.fromJson(Map<String, dynamic> j)
    : name = j['store_name'] as String,
      active = j['active'] as bool,
      metrics = CashMetrics.fromJson(j);
  final String name;
  final bool active;
  final CashMetrics metrics;
}

class ProductSales {
  ProductSales.fromJson(Map<String, dynamic> j)
    : sku = j['sku'] as String,
      name = j['name'] as String,
      condition = j['condition'] as String,
      pieces = j['sold_pieces'] as int,
      gross = j['gross_sales'] as int,
      count = j['transaction_count'] as int;
  final String sku, name, condition;
  final int pieces, gross, count;
}

class CashReport {
  CashReport(Map<String, dynamic> summary, Map<String, dynamic> products)
    : name =
          (summary['organization'] as Map<String, dynamic>)['name'] as String,
      observedAt = summary['observed_at'] as String,
      productObservedAt = products['observed_at'] as String,
      metrics = CashMetrics.fromJson(summary['totals'] as Map<String, dynamic>),
      stores = (summary['available_stores'] as List)
          .map((j) => ReportStore.fromJson(j as Map<String, dynamic>))
          .toList(),
      byStore = (summary['by_store'] as List)
          .map((j) => StoreSales.fromJson(j as Map<String, dynamic>))
          .toList(),
      products = (products['data'] as List)
          .map((j) => ProductSales.fromJson(j as Map<String, dynamic>))
          .toList(),
      page = products['current_page'] as int,
      lastPage = products['last_page'] as int;
  final String name, observedAt, productObservedAt;
  final CashMetrics metrics;
  final List<ReportStore> stores;
  final List<StoreSales> byStore;
  final List<ProductSales> products;
  final int page, lastPage;
}
