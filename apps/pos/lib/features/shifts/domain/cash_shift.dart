import 'dart:convert';

class CashShift {
  CashShift(Map<String, dynamic> json)
    : _json = jsonDecode(jsonEncode(json)) as Map<String, dynamic>;
  final Map<String, dynamic> _json;
  String get id => _json['id'] as String;
  String get storeId => _json['store_id'] as String;
  String get cashier => _json['cashier_name'] as String? ?? '—';
  String get device => _json['device_name'] as String? ?? '—';
  String get status => _json['status'] as String;
  String get openedAt => _json['opened_at'] as String? ?? '—';
  int get version => _json['version'] as int;
  int get opening => _json['opening_cash'] as int;
  int get sales => _json['cash_sales'] as int;
  int get cashIn => _json['cash_in'] as int;
  int get cashOut => _json['cash_out'] as int;
  int get expected => _json['expected_cash'] as int;
  int? get counted => _json['counted_cash'] as int?;
  int? get difference => _json['difference_cash'] as int?;
  String? get closingNote => _json['closing_note'] as String?;
  bool get canRecord =>
      status == 'open' && _json['can_record_movement'] == true;
  List<Map<String, dynamic>> get movements =>
      ((_json['movements'] as List?) ?? []).cast<Map<String, dynamic>>();
}

/// Encoded once so callers cannot change a pending operation through nested maps.
class CashMovementDraft {
  CashMovementDraft(this.shiftId, Map<String, dynamic> body)
    : _encoded = jsonEncode(body);
  final String shiftId, _encoded;
  String get path => 'shifts/$shiftId/cash-movements';
  Map<String, dynamic> get body => jsonDecode(_encoded) as Map<String, dynamic>;
}

class ShiftPage {
  const ShiftPage(this.rows, this.page, this.lastPage);
  final List<CashShift> rows;
  final int page, lastPage;
}
