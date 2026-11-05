import '../../../core/network/api_client.dart';

class AdminRepository {
  AdminRepository(this.api);
  final ApiClient api;
  static bool manages(Map row) =>
      row['active'] == true &&
      ['owner', 'admin', 'partner_lead'].contains(row['role']);
  Future<List<Map<String, dynamic>>> all(
    String path, {
    String? organization,
    bool memberships = false,
  }) async {
    final rows = <Map<String, dynamic>>[];
    var page = 1;
    while (true) {
      final result = await api.request(
        'GET',
        '$path${path.contains('?') ? '&' : '?'}page=$page',
      );
      rows.addAll(pageRows(result, page, organization: organization));
      if (result['next_page_url'] == null) break;
      // Ignore the supplied URL completely. Retain the local route and page.
      if (++page > 200) {
        throw const FormatException(
          'Terlalu banyak pilihan. Persempit data melalui pengelola.',
        );
      }
    }
    return memberships ? rows.where(manages).toList() : rows;
  }

  static List<Map<String, dynamic>> pageRows(
    Map<String, dynamic> result,
    int page, {
    String? organization,
  }) {
    if (result['data'] is! List ||
        result['current_page'] != page ||
        (organization != null && result['organization_id'] != organization)) {
      throw const FormatException(
        'Lingkup atau halaman administrasi tidak cocok.',
      );
    }
    return (result['data'] as List).map((value) {
      if (value is! Map || value['id'] is! String) {
        throw const FormatException('Dokumen administrasi tidak valid.');
      }
      return Map<String, dynamic>.from(value);
    }).toList();
  }
}
