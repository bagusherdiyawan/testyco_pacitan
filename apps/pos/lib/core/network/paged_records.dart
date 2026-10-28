import 'api_client.dart';

class PagedRecords {
  const PagedRecords(this.api);
  final ApiClient api;
  Future<List<Map<String, dynamic>>> load(String path) async {
    final rows = <Map<String, dynamic>>[];
    var page = 1;
    while (true) {
      final relative = Uri.parse(path);
      final result = await api.request(
        'GET',
        relative
            .replace(
              queryParameters: {
                ...relative.queryParameters,
                if (page > 1) 'page': '$page',
              },
            )
            .toString(),
      );
      rows.addAll((result['data'] as List).cast<Map<String, dynamic>>());
      if (page >= (result['last_page'] as int? ?? 1)) {
        break;
      }
      page++;
    }
    return rows;
  }
}
