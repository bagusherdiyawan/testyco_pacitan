import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/ordering/data/order_changes_repository.dart';
import 'package:testyco_pos/features/ordering/application/order_changes_controller.dart';
import 'package:testyco_pos/features/ordering/presentation/order_changes_page.dart';
import 'package:testyco_pos/features/sales/application/pos_controller.dart';
import 'package:testyco_pos/features/sales/data/pos_repository.dart';
import 'package:testyco_pos/features/sales/presentation/pos_page.dart';

Map<String, dynamic> oldLine({int q = 20}) => {
  'id': 'L1',
  'sku': 'OLD8',
  'local_sku': 'LOCAL8',
  'quantity_packs': q,
  'pack_size': 8,
  'unit_price': 18000,
};
Map<String, dynamic> newLine() => {
  'id': 'L2',
  'sku': 'NEW8',
  'local_sku': 'LOCAL-NEW8',
  'quantity_packs': 3,
  'pack_size': 8,
  'unit_price': 24000,
};

class RevisionServer {
  int writes = 0, version = 5;
  bool pending = false, lose = false;
  final posts = <String>[];
  Map<String, dynamic> get before => {
    'lines': [oldLine()],
    'total_amount': 360000,
  };
  Map<String, dynamic> get after => {
    'lines': [oldLine(q: 8), newLine()],
    'total_amount': 216000,
  };
  Map<String, dynamic> get record => {
    'id': 'R1',
    'kind': 'revision',
    'supply_order_id': 'O1',
    'status': 'pending',
    'reason': 'Change needs',
    'base_version': 5,
    'pending_version': 6,
    'requested_by': 2,
    'before': before,
    'after': after,
  };
  Map<String, dynamic> context() => {
    'order': {
      'id': 'O1',
      'destination_name': 'A1',
      'status': 'partially_received',
      'kind': 'wholesale',
      'version': version,
      ...before,
    },
    'data': pending ? [record] : [],
    'actions': {
      'propose': !pending,
      'approve': false,
      'reject': false,
      'withdraw': pending,
    },
    'change_pending': pending,
    'sent_packs': {'L1': 8},
    'supplier_products': [
      {'id': 'P2', 'sku': 'NEW8', 'pack_size': 8},
    ],
    'local_products': [
      {'id': 'LP2', 'sku': 'LOCAL-NEW8', 'pack_size': 8},
    ],
    'current_page': 1,
    'last_page': 1,
    'total': pending ? 1 : 0,
  };
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example.test/api/v1/'),
    client: MockClient((r) async {
      if (r.method == 'GET') return http.Response(jsonEncode(context()), 200);
      if (r.url.path.endsWith('/preview')) {
        return http.Response(
          jsonEncode({
            'data': {
              'order_id': 'O1',
              'version': version,
              'before': before,
              'after': after,
              'review_hash': 'a' * 64,
            },
          }),
          200,
        );
      }
      posts.add(r.body);
      final b = jsonDecode(r.body);
      if (!pending) {
        writes++;
        pending = true;
        version++;
      }
      if (lose) {
        lose = false;
        throw http.ClientException('Committed, response lost');
      }
      return http.Response(
        jsonEncode({
          'data': {
            'order': {'id': 'O1', 'version': b['version'] + 1},
            'revision': record,
          },
        }),
        201,
      );
    }),
  );
}

ApiClient bundleApi(List<Map<String, dynamic>> posts) => ApiClient(
  baseUrl: Uri.parse('https://api.example.test/api/v1/'),
  client: MockClient((r) async {
    final root = r.url.path;
    if (root.endsWith('/pos/context')) {
      return http.Response(
        jsonEncode({
          'data': {
            'store': {'id': 'S1', 'active': true},
            'shift': {
              'id': 'SH1',
              'device_name': 'Terminal',
              'expected_cash': 0,
            },
            'pending_intents': [],
            'recent_sales': [],
          },
        }),
        200,
      );
    }
    if (root.endsWith('/catalog')) {
      return http.Response(
        jsonEncode({
          'data': [
            {
              'id': 'P1',
              'sku': 'BUNDLE1',
              'name': 'Paket ayam udang',
              'condition': 'frozen',
              'pack_size': 1,
              'portion_size': 1,
              'retail_price': 12000,
              'bundle_recipe': {
                'id': 'R1',
                'version': 1,
                'components': [
                  {
                    'product_id': 'C1',
                    'sku': 'AYAM',
                    'condition': 'frozen',
                    'pieces': 2,
                  },
                  {
                    'product_id': 'C2',
                    'sku': 'UDANG',
                    'condition': 'frozen',
                    'pieces': 1,
                  },
                ],
              },
            },
          ],
          'last_page': 1,
        }),
        200,
      );
    }
    if (root.endsWith('/batches')) {
      return http.Response(
        jsonEncode({
          'data': [
            {
              'id': 'B1',
              'product_id': 'C1',
              'condition': 'frozen',
              'batch_code': 'LOT1',
              'ready_pieces': 10,
              'opened_pieces': 0,
            },
            {
              'id': 'B2',
              'product_id': 'C2',
              'condition': 'frozen',
              'batch_code': 'LOT2',
              'ready_pieces': 10,
              'opened_pieces': 0,
            },
          ],
          'last_page': 1,
        }),
        200,
      );
    }
    if (r.method == 'POST') {
      posts.add(jsonDecode(r.body));
      return http.Response(
        jsonEncode({
          'data': {
            'id': 'I1',
            'store_id': 'S1',
            'status': 'pending',
            'total_amount': 12000,
            'items': [
              {
                'name': 'Paket ayam udang',
                'quantity': 1,
                'unit': 'piece',
                'unit_price': 12000,
              },
            ],
          },
        }),
        201,
      );
    }
    return http.Response('{}', 404);
  }),
);
void main() {
  test(
    'Advanced revision previews additions and reductions then unknown retry keeps exact hash/UUID',
    () async {
      final s = RevisionServer(),
          m = OrderChangesController(
            OrderChangesRepository(RevisionServer().api, advanced: true),
            'O1',
          );
      m.dispose();
      final c = OrderChangesController(
        OrderChangesRepository(s.api, advanced: true),
        'O1',
      );
      await c.load();
      expect(c.context!.sent['L1'], 8);
      expect(
        () => c.revise({'L1': '7'}, [], 'Invalid'),
        throwsA(isA<ApiException>()),
      );
      final adds = [
        {
          'supplier_product_id': 'P2',
          'local_product_id': 'LP2',
          'quantity_packs': 3,
        },
      ];
      final op = await c.revise({'L1': '8'}, adds, 'Change needs');
      adds[0]['quantity_packs'] = 99;
      expect(op.body['additions'][0]['quantity_packs'], 3);
      expect(op.review['after_total'], 216000);
      expect(s.writes, 0);
      expect(op.body['review_hash'], 'a' * 64);
      s.lose = true;
      await c.confirm(op);
      expect(c.pending, same(op));
      await c.retry();
      expect(c.pending, null);
      expect(s.posts.toSet().length, 1);
      expect(s.writes, 1);
      expect(c.context!.rows.single.after.lines.length, 2);
      c.dispose();
    },
  );
  test(
    'POS bundle builds2 ayam and1 udang allocations, requires every selected component',
    () async {
      final posts = <Map<String, dynamic>>[];
      final c = PosController(PosRepository(bundleApi(posts)), 'S1');
      await c.load();
      expect(c.products.single.isBundle, true);
      c.add(c.products.single);
      await c.review();
      expect(posts, isEmpty);
      final entry = c.cart.values.single;
      c.componentStock(entry, 'C1', 'B1:ready');
      c.componentStock(entry, 'C2', 'B2:ready');
      await c.review();
      expect(posts.single['lines'][0]['unit'], 'piece');
      expect(posts.single['lines'][0]['allocations'][0]['quantity'], 2);
      expect(posts.single['lines'][0]['allocations'][1]['quantity'], 1);
      expect(c.intent!['total_amount'], 12000);
      c.dispose();
    },
  );
  for (final width in [390.0, 1200.0]) {
    testWidgets(
      'Advanced revision entry and bundle component form fit $width',
      (tester) async {
        tester.view.physicalSize = Size(width, 1000);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final s = RevisionServer();
        await tester.pumpWidget(
          MaterialApp(
            home: OrderChangesPage(
              repository: OrderChangesRepository(s.api, advanced: true),
              orderId: 'O1',
            ),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text('Tambah SKU / substitusi'), findsOneWidget);
        expect(tester.takeException(), null);
        await tester.pumpWidget(
          MaterialApp(
            home: Scaffold(
              body: PosPage(
                repository: PosRepository(bundleApi([])),
                storeId: 'S1',
              ),
            ),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text('Paket ayam udang'), findsOneWidget);
        await tester.tap(find.text('Paket ayam udang'));
        await tester.pumpAndSettle();
        expect(tester.takeException(), null);
      },
    );
  }
}
