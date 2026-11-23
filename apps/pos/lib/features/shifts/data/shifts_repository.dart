import '../../../core/network/api_client.dart';
import '../domain/cash_shift.dart';

class ShiftsRepository {
  const ShiftsRepository(this.api);
  final ApiClient api;
  Future<ShiftPage> list(String store, {int page = 1}) async {
    final r = await api.request('GET', 'stores/$store/shifts?page=$page');
    return ShiftPage(
      (r['data'] as List)
          .map((v) => CashShift(v as Map<String, dynamic>))
          .toList(),
      r['current_page'] as int? ?? page,
      r['last_page'] as int? ?? 1,
    );
  }

  Future<CashShift> detail(String id) async => CashShift(
    (await api.request('GET', 'shifts/$id'))['data'] as Map<String, dynamic>,
  );
  Future<Map<String, dynamic>> record(CashMovementDraft draft) async =>
      (await api.request('POST', draft.path, draft.body))['data']
          as Map<String, dynamic>;

  Future<Map<String, dynamic>> requestMovement(
    String shiftId,
    Map<String, dynamic> body,
  ) async =>
      (await api.request(
            'POST',
            'shifts/$shiftId/cash-movement-requests',
            body,
          ))['data']
          as Map<String, dynamic>;

  Future<Map<String, dynamic>> decideRequest(
    String requestId,
    String action,
    Map<String, dynamic> body,
  ) async =>
      (await api.request(
            'POST',
            'shift-cash-requests/$requestId/$action',
            body,
          ))['data']
          as Map<String, dynamic>;
}
