import 'dart:convert';

class WarehouseContext {
  WarehouseContext(Map<String, dynamic> json)
    : _data = jsonDecode(jsonEncode(json)) as Map<String, dynamic>;
  final Map<String, dynamic> _data;
  Map<String, dynamic> get order => _data['order'] as Map<String, dynamic>;
  Map<String, dynamic> get source => _data['source'] as Map<String, dynamic>;
  String get id => order['id'] as String;
  int get version => order['version'] as int;
  bool get canProcess => _data['can_process'] == true;
  bool get canDispatch => _data['can_dispatch'] == true;
  List<Map<String, dynamic>> get lines =>
      (order['lines'] as List).cast<Map<String, dynamic>>();
  List<Map<String, dynamic>> get batches =>
      ((_data['batches'] as List?) ?? []).cast<Map<String, dynamic>>();
  List<Map<String, dynamic>> get shipments =>
      ((_data['shipments'] as List?) ?? []).cast<Map<String, dynamic>>();
  String get observedAt => _data['observed_at'] as String? ?? '';
  List<Map<String, dynamic>> choices(Map<String, dynamic> line) => batches
      .where(
        (b) =>
            b['product_id'] == line['supplier_product_id'] &&
            b['pack_size'] == line['pack_size'],
      )
      .toList();
}

class WarehouseOperation {
  WarehouseOperation(
    this.path,
    Map<String, dynamic> body,
    Map<String, dynamic> review,
  ) : _body = jsonEncode(body),
      _review = jsonEncode(review);
  final String path, _body, _review;
  Map<String, dynamic> get body => jsonDecode(_body) as Map<String, dynamic>;
  Map<String, dynamic> get review =>
      jsonDecode(_review) as Map<String, dynamic>;
}

class WarehouseInbox {
  WarehouseInbox(this.rows, this.source, this.page, this.lastPage);
  final List<Map<String, dynamic>> rows;
  final Map<String, dynamic> source;
  final int page, lastPage;
}
