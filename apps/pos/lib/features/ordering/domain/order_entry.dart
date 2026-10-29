import 'dart:convert';

class OrderEntryContext {
  OrderEntryContext.fromJson(Map<String, dynamic> j)
    : name = j['name'] as String,
      address = j['address'] as String? ?? '',
      kind = j['order_kind'] as String? ?? '',
      canCreate =
          j['active'] == true &&
          j['can_order'] == true &&
          ['wholesale', 'internal_transfer'].contains(j['order_kind']);
  final String name, address, kind;
  final bool canCreate;
}

class OrderProductChoice {
  OrderProductChoice.fromJson(Map<String, dynamic> j)
    : id = j['id'] as String,
      organization = j['organization_id'] as String? ?? '',
      sku = j['sku'] as String,
      name = j['name'] as String,
      condition = j['condition'] as String,
      packSize = j['pack_size'] as int;
  final String id, organization, sku, name, condition;
  final int packSize;
}

class DraftOrderLine {
  DraftOrderLine(this.supplierId, {this.localId, this.quantity = ''});
  final String supplierId;
  String? localId;
  String quantity;
}

class OrderEntryPreview {
  OrderEntryPreview.fromJson(Map<String, dynamic> j)
    : hash = j['review_hash'] as String,
      total = j['total_amount'] as int,
      name = j['destination_name'] as String,
      address = j['destination_address'] as String? ?? '',
      kind = j['kind'] as String,
      mode = j['delivery_mode'] as String,
      observedAt = j['observed_at'] as String,
      lines = List.unmodifiable(
        (j['lines'] as List).map(
          (l) => OrderEntryPreviewLine.fromJson(l as Map<String, dynamic>),
        ),
      );
  final String hash, name, address, kind, mode, observedAt;
  final int total;
  final List<OrderEntryPreviewLine> lines;
}

class OrderEntryPreviewLine {
  OrderEntryPreviewLine.fromJson(Map<String, dynamic> j)
    : sku = j['sku'] as String,
      localSku = j['local_sku'] as String,
      quantity = j['quantity_packs'] as int,
      packSize = j['pack_size'] as int,
      price = j['unit_price'] as int;
  final String sku, localSku;
  final int quantity, packSize, price;
}

class OrderSubmission {
  OrderSubmission(Map<String, dynamic> body) : _json = jsonEncode(body);
  final String _json;
  Map<String, dynamic> get body => jsonDecode(_json) as Map<String, dynamic>;
}
