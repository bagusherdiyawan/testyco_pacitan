import 'dart:convert';
import '../../../core/network/api_client.dart';

class ChangeLine {
  ChangeLine.fromJson(Map<String, dynamic> j)
    : id = j['id'] as String,
      sku = j['sku'] as String,
      localSku = j['local_sku'] as String,
      packs = j['quantity_packs'] as int,
      size = j['pack_size'] as int,
      price = j['unit_price'] as int {
    if (id.isEmpty || packs < 1 || size < 1 || price < 0) {
      throw const ApiException('Snapshot baris usulan tidak valid.');
    }
  }
  final String id, sku, localSku;
  final int packs, size, price;
}

class ChangeSnapshot {
  ChangeSnapshot.fromJson(Map<String, dynamic> j)
    : total = j['total_amount'] as int,
      lines = List.unmodifiable(
        (j['lines'] as List).map(
          (v) => ChangeLine.fromJson(v as Map<String, dynamic>),
        ),
      ) {
    if (total < 0 ||
        lines.isEmpty ||
        lines.map((v) => v.id).toSet().length != lines.length) {
      throw const ApiException('Snapshot usulan tidak valid.');
    }
  }
  final int total;
  final List<ChangeLine> lines;
}

class OrderChangeRecord {
  OrderChangeRecord.fromJson(Map<String, dynamic> j)
    : id = j['id'] as String,
      orderId = j['supply_order_id'] as String,
      status = j['status'] as String,
      reason = j['reason'] as String,
      baseVersion = j['base_version'] as int,
      pendingVersion = j['pending_version'] as int,
      requestedBy = j['requested_by'] as int,
      decidedBy = j['decided_by'] as int?,
      note = j['decision_note'] as String?,
      decidedAt = j['decided_at'] as String?,
      before = ChangeSnapshot.fromJson(j['before'] as Map<String, dynamic>),
      after = ChangeSnapshot.fromJson(j['after'] as Map<String, dynamic>) {
    if (id.isEmpty ||
        !['pending', 'approved', 'rejected', 'withdrawn'].contains(status) ||
        baseVersion < 1 ||
        pendingVersion != baseVersion + 1 ||
        (decidedAt != null && DateTime.tryParse(decidedAt!) == null) ||
        before.lines.length != after.lines.length ||
        !before.lines.every(
          (l) => after.lines.any(
            (v) =>
                v.id == l.id &&
                v.sku == l.sku &&
                v.localSku == l.localSku &&
                v.size == l.size &&
                v.price == l.price &&
                v.packs <= l.packs,
          ),
        )) {
      throw const ApiException('Riwayat usulan tidak valid.');
    }
  }
  final String id, orderId, status, reason;
  final String? note, decidedAt;
  final int baseVersion, pendingVersion, requestedBy;
  final int? decidedBy;
  final ChangeSnapshot before, after;
}

class OrderChangeContext {
  OrderChangeContext.fromJson(Map<String, dynamic> j)
    : orderId = (j['order'] as Map)['id'] as String,
      destination = (j['order'] as Map)['destination_name'] as String,
      status = (j['order'] as Map)['status'] as String,
      kind = (j['order'] as Map)['kind'] as String,
      version = (j['order'] as Map)['version'] as int,
      original = ChangeSnapshot.fromJson(j['order'] as Map<String, dynamic>),
      actions = Map.unmodifiable(j['actions'] as Map<String, dynamic>),
      held = j['change_pending'] == true,
      rows = List.unmodifiable(
        (j['data'] as List).map(
          (v) => OrderChangeRecord.fromJson(v as Map<String, dynamic>),
        ),
      ),
      page = j['current_page'] as int,
      lastPage = j['last_page'] as int,
      total = j['total'] as int;
  final String orderId, destination, status, kind;
  final int version, page, lastPage, total;
  final bool held;
  final ChangeSnapshot original;
  final Map<String, dynamic> actions;
  final List<OrderChangeRecord> rows;
  bool allows(String action) => actions[action] == true;
}

class PendingOrderChange {
  PendingOrderChange(
    this.path,
    this.action,
    this.changeId,
    Map<String, dynamic> body,
    Map<String, dynamic> review,
  ) : _body = jsonEncode(body),
      _review = jsonEncode(review);
  final String path, action;
  final String? changeId;
  final String _body, _review;
  Map<String, dynamic> get body => jsonDecode(_body) as Map<String, dynamic>;
  Map<String, dynamic> get review =>
      jsonDecode(_review) as Map<String, dynamic>;
}
