import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/fulfillment/data/shipment_case_repository.dart';
import 'package:testyco_pos/features/fulfillment/application/shipment_case_controller.dart';
import 'package:testyco_pos/features/fulfillment/presentation/shipment_case_page.dart';

class CaseServer {
  bool exists = false, replacement = false, supplier = false, lose = false;
  int version = 1, postStatus = 0, writes = 0, reads = 0;
  String? wrong;
  final posts = <String>[], paths = <String>[];
  final memo = <String, Map<String, dynamic>>{};
  Map<String, dynamic>? plan;
  Map<String, dynamic> get snapshot => {
    'received_at': '2026-11-03T01:00:00Z',
    'received_by': 2,
    'receipt_note': 'Actual',
    'problem_packs': 3,
    'lines': [
      {
        'shipment_line_id': 'L1',
        'order_line_id': 'OL1',
        'sku': 'OLD8',
        'local_sku': 'LOCAL8',
        'batch_code': 'LOT1',
        'pack_size': 8,
        'sent_packs': 20,
        'good_packs': 17,
        'damaged_packs': 2,
        'missing_packs': 1,
      },
    ],
  };
  Map<String, dynamic> get caseData => {
    'id': 'C1',
    'supply_order_id': 'O1',
    'shipment_id': 'H1',
    'version': version,
    'status': plan == null
        ? 'reported'
        : plan!['status'] == 'confirmed'
        ? 'plan_confirmed'
        : 'awaiting_confirmation',
    'reason': 'Physical discrepancy',
  };
  Map<String, dynamic> planRow(String status) => {
    'id': 'R1',
    'shipment_discrepancy_id': 'C1',
    'status': status,
    'type': 'replacement',
    'proposal': 'Replace physical issue',
    'proposed_by': 1,
  };
  Map<String, dynamic> context(Map<String, String> query) => {
    'order': {'id': wrong == 'order' ? 'FOREIGN' : 'O1', 'version': 5},
    'shipment': {'id': 'H1', 'supply_order_id': 'O1'},
    'case': exists ? caseData : null,
    'snapshot': snapshot,
    'actions': {
      'report': !exists && !supplier,
      'note': exists,
      'propose_resolution': exists && supplier && plan == null,
      'confirm_resolution': exists && !supplier && plan?['status'] == 'pending',
      'reject_resolution': exists && !supplier && plan?['status'] == 'pending',
      'withdraw_resolution': exists && supplier && plan?['status'] == 'pending',
    },
    'data': [],
    'current_page': int.parse(query['page'] ?? '1'),
    'per_page': 50,
    'total': 0,
    'last_page': 1,
    'resolutions': {
      'data': plan?['status'] == 'confirmed' ? [plan] : [],
      'current_page': int.parse(query['resolutions_page'] ?? '1'),
      'per_page': 50,
      'total': 0,
      'last_page': 1,
    },
    'pending_resolution': plan?['status'] == 'pending' ? plan : null,
  };
  Map<String, dynamic> replacementRow({bool received = false}) => {
    'id': 'X1',
    'case_id': 'C1',
    'resolution_id': 'R1',
    'source_store_id': 'S1',
    'handler': 'Courier',
    'received_at': received ? '2026-11-03T01:00:00Z' : null,
    'can_receive': !supplier && !received,
    'lines': [
      {
        'id': 'XL1',
        'replacement_shipment_id': 'X1',
        'original_shipment_line_id': 'L1',
        'inventory_batch_id': 'B1',
        'local_sku': 'LOCAL8',
        'sku': 'OLD8',
        'batch_code': 'LOT2',
        'quantity_packs': 3,
        'pack_size': 8,
        'good_packs': received ? 3 : null,
        'damaged_packs': received ? 0 : null,
        'condition_note': received ? 'Checked' : null,
      },
    ],
  };
  bool shipped = false, received = false;
  Map<String, dynamic> replacementContext(Map<String, String> q) => {
    'order_id': 'O1',
    'shipment_id': 'H1',
    'case_id': 'C1',
    'case_version': version,
    'source_store_id': 'S1',
    'resolution': planRow('confirmed'),
    'quota': [
      {
        'shipment_line_id': 'L1',
        'order_line_id': 'OL1',
        'supplier_product_id': 'P1',
        'sku': 'OLD8',
        'local_sku': 'LOCAL8',
        'pack_size': 8,
        'limit_packs': 3,
        'remaining_packs': shipped ? 0 : 3,
        'replacement_sent_packs': shipped ? 3 : 0,
        'replacement_good_packs': received ? 3 : 0,
      },
    ],
    'batches': supplier
        ? [
            {
              'id': 'B1',
              'store_id': wrong == 'batch' ? 'FOREIGN' : 'S1',
              'product_id': 'P1',
              'pack_size': 8,
              'batch_code': 'LOT2',
              'usable_pieces': 24,
            },
          ]
        : [],
    'data': shipped ? [replacementRow(received: received)] : [],
    'current_page': int.parse(q['page'] ?? '1'),
    'last_page': 1,
    'per_page': 50,
    'total': shipped ? 1 : 0,
    'can_dispatch': supplier && !shipped,
    'execution_status': received
        ? 'fulfilled'
        : shipped
        ? 'in_transit'
        : 'ready',
  };
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example.test/api/v1/'),
    client: MockClient((r) async {
      paths.add(r.url.path);
      if (r.method == 'GET') {
        reads++;
        return http.Response(
          jsonEncode(
            replacement
                ? replacementContext(r.url.queryParameters)
                : context(r.url.queryParameters),
          ),
          200,
        );
      }
      posts.add(r.body);
      if (postStatus > 0) {
        return http.Response(jsonEncode({'message': 'Denied'}), postStatus);
      }
      final b = jsonDecode(r.body) as Map<String, dynamic>;
      final key = b['operation_id'] as String;
      Map<String, dynamic>? result = memo[key];
      if (result == null) {
        writes++;
        version = b['order_version'] != null ? 1 : version + 1;
        exists = true;
        final action = r.url.path.split('/').last;
        result = {'order_id': 'O1', 'shipment_id': 'H1'};
        if (replacement) {
          if (action == 'receive') {
            received = true;
          } else {
            shipped = true;
          }
          result.addAll({
            'case_id': 'C1',
            'case_version': version,
            'replacement': replacementRow(received: received),
          });
        } else {
          if (action == 'resolutions') {
            plan = planRow('pending');
            plan!['proposal'] = b['proposal'];
            plan!['type'] = b['type'];
          }
          if (['confirm', 'reject', 'withdraw'].contains(action)) {
            plan = planRow(
              {
                'confirm': 'confirmed',
                'reject': 'rejected',
                'withdraw': 'withdrawn',
              }[action]!,
            );
          }
          result.addAll({
            'case': {
              ...caseData,
              'version': b['order_version'] != null ? 1 : version,
              'reason': b['reason'] ?? 'Physical discrepancy',
              'snapshot': snapshot,
            },
            if (action == 'notes')
              'note': {
                'id': 'N1',
                'shipment_discrepancy_id': 'C1',
                'message': b['message'],
                'case_version': version,
              },
            if (plan != null) 'resolution': plan,
          });
        }
        memo[key] = jsonDecode(jsonEncode(result));
      }
      if (lose) {
        lose = false;
        throw http.ClientException('Lost after commit');
      }
      if (wrong == 'ack') {
        result = jsonDecode(jsonEncode(result));
        result!['order_id'] = 'FOREIGN';
      }
      return http.Response(jsonEncode({'data': result}), 200);
    }),
  );
  ShipmentCaseRepository get repository =>
      ShipmentCaseRepository(api, 'O1', 'H1', replacements: replacement);
}

void main() {
  test(
    'Report uses parent version then shared note uses case version; rejected roles send no POST',
    () async {
      final s = CaseServer(),
          m = ShipmentCaseController(CaseServer().repository);
      m.dispose();
      final c = ShipmentCaseController(s.repository);
      await c.load();
      final op = c.prepare('report', {'reason': 'Physical discrepancy'});
      expect(op.body['order_version'], 5);
      expect(s.writes, 0);
      await c.confirm(op);
      expect(c.saved, true);
      expect(s.writes, 1);
      final n = c.prepare('note', {'message': 'Follow up'});
      expect(n.body['version'], s.version);
      await c.confirm(n);
      expect(s.writes, 2);
      expect(
        () => c.prepare('propose', {
          'type': 'credit',
          'proposal': 'Not supplier',
        }),
        throwsA(isA<ApiException>()),
      );
      expect(
        () => c.prepare('note', {'message': 'x', 'version': 999}),
        throwsA(isA<ApiException>()),
      );
      c.dispose();
    },
  );
  test(
    'Supplier proposal then recipient confirmation keeps reviewed exact plan identity',
    () async {
      final s = CaseServer()
        ..exists = true
        ..supplier = true;
      final c = ShipmentCaseController(s.repository);
      await c.load();
      final p = c.prepare('propose', {
        'type': 'replacement',
        'proposal': 'Replace physical issue',
      });
      await c.confirm(p);
      expect(s.plan!['status'], 'pending');
      s.supplier = false;
      await c.load();
      expect(
        () => c.prepare('confirm', {}, subject: 'OTHER'),
        throwsA(isA<ApiException>()),
      );
      await c.confirm(c.prepare('confirm', {}, subject: 'R1'));
      expect(s.plan!['status'], 'confirmed');
      c.dispose();
    },
  );
  test(
    'Unknown committed note survives denial and retains deep immutable body and blocks refresh',
    () async {
      final s = CaseServer()..exists = true;
      final c = ShipmentCaseController(s.repository);
      await c.load();
      final op = c.prepare('note', {'message': 'Original'});
      final original = jsonEncode(op.body);
      s.lose = true;
      await c.confirm(op);
      expect(c.pending, same(op));
      op.body['message'] = 'Changed';
      expect(jsonEncode(op.body), original);
      final reads = s.reads;
      await c.load();
      expect(s.reads, reads);
      s.postStatus = 403;
      await c.retry();
      expect(c.pending, same(op));
      s.postStatus = 0;
      await c.retry();
      expect(c.pending, null);
      expect(s.writes, 1);
      expect(s.posts.toSet().length, 1);
      c.dispose();
    },
  );
  test(
    'Malformed ACK stays uncertain, 429 stays pending and initial409 releases',
    () async {
      for (final status in [0, 429, 409]) {
        final s = CaseServer()
          ..exists = true
          ..postStatus = status;
        if (status == 0) s.wrong = 'ack';
        final c = ShipmentCaseController(s.repository);
        await c.load();
        await c.confirm(c.prepare('note', {'message': 'Check'}));
        expect(c.pending != null, status != 409);
        c.dispose();
      }
    },
  );
  test(
    'Context scope invalid and refreshed candidate cannot be submitted',
    () async {
      final s = CaseServer()..wrong = 'order';
      final c = ShipmentCaseController(s.repository);
      await c.load();
      expect(c.fresh, false);
      s.wrong = null;
      await c.load();
      final op = c.prepare('report', {'reason': 'Physical discrepancy'});
      await c.load();
      await c.confirm(op);
      expect(s.writes, 0);
      c.dispose();
    },
  );
  test(
    'Replacement dispatch and receipt preserve quotas and exact payload on unknown',
    () async {
      final s = CaseServer()
        ..replacement = true
        ..supplier = true
        ..version = 3;
      final c = ShipmentCaseController(s.repository);
      await c.load();
      final rows = [
        {
          'original_shipment_line_id': 'L1',
          'allocations': [
            {'batch_id': 'B1', 'quantity_packs': 3},
          ],
        },
      ];
      final op = c.prepare('dispatch', {'handler': 'Courier', 'lines': rows});
      rows[0]['allocations'] = [
        {'batch_id': 'B1', 'quantity_packs': 99},
      ];
      expect(op.body['lines'][0]['allocations'][0]['quantity_packs'], 3);
      s.lose = true;
      await c.confirm(op);
      expect(c.pending, same(op));
      await c.retry();
      expect(s.writes, 1);
      expect(c.context!['quota'][0]['remaining_packs'], 0);
      s.supplier = false;
      await c.load();
      await c.confirm(
        c.prepare('receive', {
          'lines': [
            {
              'replacement_line_id': 'XL1',
              'good_packs': 3,
              'damaged_packs': 0,
              'condition_note': 'Checked',
            },
          ],
        }, subject: 'X1'),
      );
      expect(c.context!['execution_status'], 'fulfilled');
      expect(s.writes, 2);
      c.dispose();
    },
  );
  test(
    'Wrong replacement batch scope and over-quota quantities denied before POST',
    () async {
      final s = CaseServer()
        ..replacement = true
        ..supplier = true
        ..wrong = 'batch';
      final c = ShipmentCaseController(s.repository);
      await c.load();
      expect(c.fresh, false);
      s.wrong = null;
      await c.load();
      expect(
        () => c.prepare('dispatch', {
          'handler': 'Courier',
          'lines': [
            {
              'original_shipment_line_id': 'L1',
              'allocations': [
                {'batch_id': 'B1', 'quantity_packs': 4},
              ],
            },
          ],
        }),
        throwsA(isA<ApiException>()),
      );
      expect(s.writes, 0);
      c.dispose();
    },
  );
  for (final width in [390.0, 1200.0]) {
    testWidgets(
      'Report review then unknown retry preserves one write at $width',
      (tester) async {
        tester.view.physicalSize = Size(width, 1000);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final s = CaseServer()..lose = true;
        await tester.pumpWidget(
          MaterialApp(home: ShipmentCasePage(repository: s.repository)),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Laporkan selisih'));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.byKey(const ValueKey('reason')),
          'Physical discrepancy',
        );
        await tester.tap(find.text('Tinjau sebelum kirim'));
        await tester.pumpAndSettle();
        expect(s.writes, 0);
        await tester.tap(find.text('Konfirmasi pencatatan'));
        await tester.pumpAndSettle();
        expect(s.writes, 1);
        expect(find.text('Ulangi request yang sama'), findsOneWidget);
        final refresh = tester.widget<IconButton>(
          find.byWidgetPredicate(
            (w) => w is IconButton && w.tooltip == 'Muat ulang',
          ),
        );
        expect(refresh.onPressed, null);
        await tester.ensureVisible(find.text('Ulangi request yang sama'));
        await tester.tap(find.text('Ulangi request yang sama'));
        await tester.pumpAndSettle();
        expect(s.writes, 1);
        expect(s.posts.toSet().length, 1);
        expect(find.text('Tambah catatan'), findsOneWidget);
        expect(tester.takeException(), null);
      },
    );
  }
  for (final width in [390.0, 1200.0]) {
    testWidgets('Case and replacement cards fit mobile/tablet $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 1000);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final s = CaseServer()
        ..exists = true
        ..plan = CaseServer().planRow('confirmed');
      await tester.pumpWidget(
        MaterialApp(
          home: MediaQuery(
            data: MediaQueryData(
              size: Size(width, 1000),
              textScaler: TextScaler.linear(1.5),
            ),
            child: ShipmentCasePage(repository: s.repository),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.textContaining('Selisih 3 kemasan'), findsOneWidget);
      expect(tester.takeException(), null);
      final button = find.text('Buka pengiriman dan penerimaan pengganti');
      await tester.scrollUntilVisible(button, 200);
      await tester.tap(button);
      s.replacement = true;
      await tester.pumpAndSettle();
      expect(tester.takeException(), null);
    });
  }
}
