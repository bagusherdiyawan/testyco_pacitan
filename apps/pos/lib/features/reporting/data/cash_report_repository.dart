import '../../../core/network/api_client.dart';
import '../domain/cash_report.dart';

class CashReportRepository {
  const CashReportRepository(this.api);
  final ApiClient api;
  Future<CashReport> load(
    String organization,
    CashReportPeriod period, {
    String store = '',
    int page = 1,
  }) async {
    final base =
        'organizations/${Uri.encodeComponent(organization)}/reports/cash-sales';
    final query = {...period.query, if (store.isNotEmpty) 'store_id': store};
    final results = await Future.wait([
      api.request('GET', Uri(path: base, queryParameters: query).toString()),
      api.request(
        'GET',
        Uri(
          path: '$base/products',
          queryParameters: {...query, 'page': '$page'},
        ).toString(),
      ),
    ]);
    return CashReport(results[0]['data'] as Map<String, dynamic>, results[1]);
  }
}
