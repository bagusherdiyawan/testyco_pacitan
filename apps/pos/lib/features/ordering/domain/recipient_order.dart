class RecipientOrder {
  RecipientOrder.fromJson(Map<String, dynamic> j)
    : storeId = j['store_id'] as String? ?? '',
      id = j['id'] as String,
      destination = j['destination_name'] as String,
      status = j['status'] as String,
      version = j['version'] as int,
      total = j['total_amount'] as int,
      kind = j['kind'] as String? ?? 'wholesale',
      decisionNote = j['decision_note'] as String?,
      fulfillmentEnabled = j['fulfillment_enabled'] as bool? ?? true,
      actions = Map.unmodifiable(
        (j['recipient_actions'] as Map<String, dynamic>?) ?? {},
      ),
      lines = List.unmodifiable(
        (j['lines'] as List).map(
          (l) => RecipientOrderLine.fromJson(l as Map<String, dynamic>),
        ),
      );
  final String id, storeId, destination, status, kind;
  final String? decisionNote;
  final int version, total;
  final bool fulfillmentEnabled;
  final Map<String, dynamic> actions;
  final List<RecipientOrderLine> lines;
  bool allows(String action) => actions[action] == true;
}

class RecipientOrderLine {
  RecipientOrderLine.fromJson(Map<String, dynamic> j)
    : sku = j['sku'] as String,
      localSku = j['local_sku'] as String,
      quantity = j['quantity_packs'] as int,
      packSize = j['pack_size'] as int,
      unitPrice = j['unit_price'] as int;
  final String sku, localSku;
  final int quantity, packSize, unitPrice;
}

class ReceiptShipment {
  ReceiptShipment.fromJson(Map<String, dynamic> j)
    : id = j['id'] as String? ?? '',
      receivedAt = j['received_at'] as String?,
      handler = j['handler'] as String? ?? '',
      receiptNote = j['receipt_note'] as String?,
      lines = List.unmodifiable(
        (j['lines'] as List).map(
          (l) => ReceiptShipmentLine.fromJson(l as Map<String, dynamic>),
        ),
      );
  final String id, handler;
  final String? receivedAt, receiptNote;
  final List<ReceiptShipmentLine> lines;
  bool get canIdentify =>
      id.isNotEmpty &&
      lines.isNotEmpty &&
      lines.every((l) => l.id.isNotEmpty && l.packSize > 0);
}

class ReceiptShipmentLine {
  ReceiptShipmentLine.fromJson(Map<String, dynamic> j)
    : id = j['id'] as String? ?? '',
      batch = j['batch_code'] as String,
      expiry = j['expires_on'] as String? ?? '—',
      sent = j['quantity_packs'] as int,
      packSize = j['pack_size'] as int? ?? 0,
      good = j['good_packs'] as int?,
      damaged = j['damaged_packs'] as int?,
      conditionNote = j['condition_note'] as String?;
  final String id, batch, expiry;
  final int sent, packSize;
  final int? good, damaged;
  final String? conditionNote;
}

class ReceiptLineInput {
  const ReceiptLineInput({
    required this.good,
    required this.damaged,
    required this.note,
  });
  final String good, damaged, note;
}
