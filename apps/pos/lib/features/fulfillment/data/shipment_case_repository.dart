import 'dart:convert';
import '../../../core/network/api_client.dart';

class CaseOperation {
  CaseOperation(
    this.path,
    this.action,
    Map<String, dynamic> body,
    Map<String, dynamic> review, {
    this.subject,
  }) : _body = jsonEncode(body),
       _review = jsonEncode(review);
  final String path, action;
  final String? subject;
  final String _body, _review;
  Map<String, dynamic> get body => jsonDecode(_body) as Map<String, dynamic>;
  Map<String, dynamic> get review =>
      jsonDecode(_review) as Map<String, dynamic>;
}

class ShipmentCaseRepository {
  const ShipmentCaseRepository(
    this.api,
    this.order,
    this.shipment, {
    this.replacements = false,
    this.returns = false,
  });
  final ApiClient api;
  final String order, shipment;
  final bool replacements, returns;
  bool get physical => replacements || returns;
  String get path =>
      'orders/${Uri.encodeComponent(order)}/shipments/${Uri.encodeComponent(shipment)}/discrepancy${returns
          ? '/returns'
          : replacements
          ? '/replacements'
          : ''}';
  Never invalid() =>
      throw const ApiException('Cakupan/format kasus kiriman tidak valid.');
  Future<Map<String, dynamic>> load({int page = 1, int plansPage = 1}) async {
    final c = await api.request(
      'GET',
      '$path?page=$page${physical ? '' : '&resolutions_page=$plansPage'}',
    );
    if (c['current_page'] != page ||
        c['per_page'] != 50 ||
        c['data'] is! List ||
        c['total'] is! int ||
        (c['total'] as int) < 0 ||
        c['last_page'] is! int) {
      invalid();
    }
    if (physical) {
      if (c['order_id'] != order ||
          c['shipment_id'] != shipment ||
          c['case_id'] is! String ||
          c['case_version'] is! int ||
          c['quota'] is! List ||
          c['batches'] is! List ||
          c['can_dispatch'] is! bool ||
          c['resolution'] is! Map ||
          c['resolution']['shipment_discrepancy_id'] != c['case_id'] ||
          c['resolution']['status'] != 'confirmed' ||
          c['resolution']['type'] != (returns ? 'return' : 'replacement')) {
        invalid();
      }
      final ids = <String>{};
      for (final q in c['quota']) {
        if (q is! Map ||
            q['shipment_line_id'] is! String ||
            !ids.add(q['shipment_line_id']) ||
            q['limit_packs'] is! int ||
            q['remaining_packs'] is! int ||
            q['remaining_packs'] < 0 ||
            q['remaining_packs'] > q['limit_packs']) {
          invalid();
        }
      }
      for (final b in c['batches']) {
        if (b is! Map ||
            b['store_id'] != c['source_store_id'] ||
            !(c['quota'] as List).any(
              (q) =>
                  q[returns
                          ? 'allocation_product_id'
                          : 'supplier_product_id'] ==
                      b['product_id'] &&
                  q['pack_size'] == b['pack_size'],
            )) {
          invalid();
        }
      }
      for (final row in c['data']) {
        if (row is! Map ||
            row['case_id'] != c['case_id'] ||
            row['resolution_id'] != c['resolution']['id'] ||
            row['source_store_id'] != c['source_store_id'] ||
            row['can_receive'] is! bool ||
            row['lines'] is! List) {
          invalid();
        }
        for (final l in row['lines']) {
          if (l[returns ? 'return_shipment_id' : 'replacement_shipment_id'] !=
                  row['id'] ||
              !ids.contains(l['original_shipment_line_id'])) {
            invalid();
          }
        }
      }
    } else {
      if (c['order'] is! Map ||
          c['order']['id'] != order ||
          c['order']['version'] is! int ||
          c['shipment'] is! Map ||
          c['shipment']['id'] != shipment ||
          c['shipment']['supply_order_id'] != order ||
          c['actions'] is! Map ||
          c['resolutions'] is! Map ||
          c['resolutions']['current_page'] != plansPage ||
          c['resolutions']['per_page'] != 50 ||
          c['resolutions']['data'] is! List) {
        invalid();
      }
      final k = c['case'];
      if (k != null &&
          (k is! Map ||
              k['supply_order_id'] != order ||
              k['shipment_id'] != shipment ||
              k['version'] is! int ||
              k['id'] is! String)) {
        invalid();
      }
      for (final row in [
        ...c['data'],
        ...c['resolutions']['data'],
        if (c['pending_resolution'] != null) c['pending_resolution'],
      ]) {
        if (k == null ||
            row is! Map ||
            row['shipment_discrepancy_id'] != k['id']) {
          invalid();
        }
      }
    }
    return c;
  }

  Future<void> submit(CaseOperation op) async {
    final d = (await api.request('POST', op.path, op.body))['data'];
    final r = op.review, b = op.body;
    if (d is! Map || d['order_id'] != order || d['shipment_id'] != shipment) {
      invalid();
    }
    if (physical) {
      final s = d[returns ? 'return' : 'replacement'];
      if (d['case_id'] != r['case_id'] ||
          d['case_version'] != b['version'] + 1 ||
          s is! Map ||
          s['id'] is! String ||
          s['case_id'] != r['case_id'] ||
          s['resolution_id'] != r['resolution']['id'] ||
          s['source_store_id'] != r['source_store_id'] ||
          s['lines'] is! List) {
        invalid();
      }
      final expected = <String>[], actual = <String>[];
      if (op.action == 'dispatch') {
        if (s['handler'] != b['handler'] || s['received_at'] != null) invalid();
        for (final l in b['lines']) {
          for (final a in l['allocations']) {
            expected.add(
              jsonEncode([
                l['original_shipment_line_id'],
                a['batch_id'],
                a['quantity_packs'],
              ]),
            );
          }
        }
        for (final l in s['lines']) {
          actual.add(
            jsonEncode([
              l['original_shipment_line_id'],
              l['inventory_batch_id'],
              l['quantity_packs'],
            ]),
          );
        }
      } else {
        if (s['id'] != op.subject || s['received_at'] is! String) invalid();
        for (final l in b['lines']) {
          expected.add(
            jsonEncode([
              l[returns ? 'return_line_id' : 'replacement_line_id'],
              if (returns)
                l['received_packs']
              else ...[
                l['good_packs'],
                l['damaged_packs'],
              ],
              l['condition_note'],
            ]),
          );
        }
        for (final l in s['lines']) {
          actual.add(
            jsonEncode([
              l['id'],
              if (returns)
                l['received_packs']
              else ...[
                l['good_packs'],
                l['damaged_packs'],
              ],
              l['condition_note'],
            ]),
          );
        }
      }
      expected.sort();
      actual.sort();
      if (jsonEncode(expected) != jsonEncode(actual)) invalid();
    } else {
      final k = d['case'];
      if (k is! Map ||
          k['id'] is! String ||
          k['supply_order_id'] != order ||
          k['shipment_id'] != shipment ||
          k['version'] != (op.action == 'report' ? 1 : b['version'] + 1) ||
          (r['case'] != null && k['id'] != r['case']['id'])) {
        invalid();
      }
      if (op.action == 'report' &&
          (k['reason'] != b['reason'] ||
              jsonEncode(k['snapshot']) != jsonEncode(r['snapshot']))) {
        invalid();
      }
      if (op.action == 'note' &&
          (d['note'] is! Map ||
              d['note']['shipment_discrepancy_id'] != k['id'] ||
              d['note']['message'] != b['message'] ||
              d['note']['case_version'] != k['version'])) {
        invalid();
      }
      if (!['report', 'note'].contains(op.action)) {
        final plan = d['resolution'];
        final status = {
          'propose': 'pending',
          'confirm': 'confirmed',
          'reject': 'rejected',
          'withdraw': 'withdrawn',
        }[op.action];
        if (plan is! Map ||
            plan['shipment_discrepancy_id'] != k['id'] ||
            plan['status'] != status ||
            plan['id'] is! String ||
            (op.subject != null && plan['id'] != op.subject) ||
            (op.action == 'propose' &&
                (plan['proposal'] != b['proposal'] ||
                    plan['type'] != b['type']))) {
          invalid();
        }
      }
    }
  }
}
