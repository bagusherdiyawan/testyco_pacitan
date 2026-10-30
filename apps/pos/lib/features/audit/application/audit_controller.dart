import 'package:flutter/foundation.dart';
import '../../../core/domain/wib_date_range.dart';
import '../data/audit_repository.dart';
import '../domain/audit_history.dart';

class AuditController extends ChangeNotifier {
  AuditController(this.repository, this.organization);
  final AuditRepository repository;
  final String organization;
  AuditFilter filter = AuditFilter(WibDateRange.today());
  AuditHistory? result;
  String? error;
  int page = 1;
  bool busy = false, _disposed = false;
  void _notify() {
    if (!_disposed) notifyListeners();
  }

  Future<void> load({AuditFilter? next, int? nextPage}) async {
    if (busy) return;
    if (next != null) {
      filter = next;
      page = 1;
    }
    page = nextPage ?? page;
    busy = true;
    result = null;
    error = null;
    _notify();
    try {
      result = await repository.load(organization, filter, page);
    } catch (e) {
      error = '$e';
    } finally {
      busy = false;
      _notify();
    }
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}
