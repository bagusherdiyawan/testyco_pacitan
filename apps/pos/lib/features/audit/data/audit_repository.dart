import '../../../core/network/api_client.dart';
import '../domain/audit_history.dart';

class AuditRepository {
  const AuditRepository(this.api);
  final ApiClient api;
  Future<AuditHistory> load(
    String organization,
    AuditFilter filter,
    int page,
  ) async {
    final path = Uri(
      path: 'organizations/$organization/audit',
      queryParameters: {...filter.params, 'page': '$page'},
    );
    final r = await api.request('GET', path.toString());
    if ((r['organization'] as Map?)?['id'] != organization ||
        !['organization', 'onboarding'].contains(r['visibility'])) {
      throw const ApiException('Audit bukan milik organisasi yang dipilih.');
    }
    final rows = (r['data'] as List)
        .map((v) => AuditRecord.fromJson(v as Map<String, dynamic>))
        .toList();
    if (rows.any((v) => v.organization != organization)) {
      throw const ApiException('Audit lintas organisasi ditolak.');
    }
    return AuditHistory(
      rows,
      (r['organization'] as Map)['name'] as String,
      r['visibility'] as String,
      r['observed_at'] as String,
      r['current_page'] as int,
      r['last_page'] as int,
    );
  }
}
