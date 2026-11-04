import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/sales/application/refund_controller.dart';
import 'package:testyco_pos/features/sales/presentation/refund_page.dart';
import 'package:testyco_pos/features/fulfillment/application/shipment_case_controller.dart';
import 'package:testyco_pos/features/fulfillment/data/shipment_case_repository.dart';
import 'package:testyco_pos/features/fulfillment/presentation/shipment_case_page.dart';
import 'cash_pos_test.dart' show FakeCashServer, readyController;
import 'shipment_case_test.dart' show CaseServer;

class DigitalServer extends FakeCashServer {
  String method = 'cash', reference = '';
  int status = 0;
  @override
  Map<String, dynamic> get receipt => {
    ...super.receipt,
    'payment_method': method,
    'payment_reference': reference,
    'cash_received': method == 'cash' ? 20000 : 0,
    'change_amount': method == 'cash' ? 8000 : 0,
  };
  @override
  Future<http.Response> handle(http.Request r) async {
    if (r.url.path.endsWith('/pay')) {
      final b = jsonDecode(r.body);
      payBodies.add(Map<String, dynamic>.from(b));
      if (status != 0) return response({}, status);
      method = b['payment_method'];
      reference = b['payment_reference'] ?? '';
      paid = true;
      payments++;
      return response({'data': receipt});
    }
    return super.handle(r);
  }
}

class RefundServer {
  int version = 1, status = 0, writes = 0, reads = 0;
  bool wrong = false;
  final bodies = <String>[];
  Map<String, dynamic> context() => {
    'sale': {
      'id': 'SALE',
      'store_id': 'STORE',
      'version': version,
      'number': 'POS-1',
      'payment_method': 'qris_manual',
      'lines': [
        {
          'id': 'L1',
          'sale_id': 'SALE',
          'sku': 'OLD',
          'unit': 'piece',
          'quantity': 3,
        },
      ],
    },
    'refunds': [],
    'open_shifts': [
      {
        'id': 'SHIFT',
        'store_id': 'STORE',
        'status': 'open',
        'version': 4,
        'device_name': 'Terminal',
        'cashier_name': 'Cashier',
      },
    ],
  };
  Map<String, dynamic> review(Map b) => {
    'sale_id': 'SALE',
    'store_id': 'STORE',
    'version': b['version'],
    'shift_id': b['shift_id'],
    'shift_version': b['shift_version'],
    'payment_method': 'qris_manual',
    'amount': 2666,
    'stock_disposition': b['stock_disposition'],
    'reference': b['reference'],
    'reason': b['reason'],
    'review_hash': 'a' * 64,
    'lines': [
      {
        'sale_line_id': 'L1',
        'sku': 'OLD',
        'quantity': b['lines'][0]['quantity'],
        'unit': 'piece',
        'amount': 2666,
        'allocations': [
          {'batch_code': 'LOT1', 'pieces': 1},
        ],
      },
    ],
  };
  late final api = ApiClient(
    baseUrl: Uri.parse('https://example.test/api/v1/'),
    client: MockClient((r) async {
      if (r.method == 'GET') {
        reads++;
        final c = context();
        if (wrong) c['sale']['id'] = 'OTHER';
        return http.Response(jsonEncode(c), 200);
      }
      final b = jsonDecode(r.body);
      if (r.url.path.endsWith('/preview')) {
        return http.Response(jsonEncode({'data': review(b)}), 200);
      }
      bodies.add(r.body);
      if (status != 0) return http.Response('{}', status);
      writes++;
      version = 2;
      return http.Response(
        jsonEncode({
          'data': {
            'sale_id': 'SALE',
            'sale_version': 2,
            'refund': {
              'id': 'REF1',
              'sale_id': 'SALE',
              'cash_shift_id': 'SHIFT',
              'payment_method': 'qris_manual',
              'reference': b['reference'],
              'amount': 2666,
              'stock_disposition': b['stock_disposition'],
              'lines': review(b)['lines'],
            },
          },
        }),
        201,
      );
    }),
  );
}

class ReturnServer {
  final source = CaseServer()
    ..supplier = true
    ..replacement = true
    ..version = 3;
  int status = 0;
  final posts = <String>[];
  Map<String, dynamic> convert(Map<String, dynamic> c) =>
      jsonDecode(
            jsonEncode(c)
                .replaceAll('replacement_shipment_id', 'return_shipment_id')
                .replaceAll('replacement_sent_packs', 'return_sent_packs')
                .replaceAll('replacement_good_packs', 'return_received_packs')
                .replaceAll('supplier_product_id', 'allocation_product_id')
                .replaceAll('usable_pieces', 'quarantined_pieces')
                .replaceAll('"type":"replacement"', '"type":"return"'),
          )
          as Map<String, dynamic>;
  late final api = ApiClient(
    baseUrl: Uri.parse('https://example.test/api/v1/'),
    client: MockClient((r) async {
      if (r.method == 'GET') {
        return http.Response(
          jsonEncode(convert(source.replacementContext(r.url.queryParameters))),
          200,
        );
      }
      posts.add(r.body);
      if (status != 0) return http.Response('{}', status);
      source.shipped = true;
      source.version = 4;
      return http.Response(
        jsonEncode({
          'data': {
            'order_id': 'O1',
            'shipment_id': 'H1',
            'case_id': 'C1',
            'case_version': 4,
            'return': convert(source.replacementRow()),
          },
        }),
        201,
      );
    }),
  );
  ShipmentCaseRepository get repository =>
      ShipmentCaseRepository(api, 'O1', 'H1', returns: true);
}

void main() {
  test(
    'Noncash requires verification and freezes method/reference/amount through denial',
    () async {
      final s = DigitalServer();
      final m = await readyController(s);
      addTearDown(m.dispose);
      await m.pay(
        '',
        method: 'qris_manual',
        reference: 'QR1',
        verifiedAmount: '12000',
      );
      expect(s.payBodies, isEmpty);
      s.status = 503;
      await m.pay(
        '',
        method: 'qris_manual',
        reference: 'QR1',
        verified: true,
        verifiedAmount: '12000',
      );
      expect(m.paymentUncertain, isTrue);
      s.status = 403;
      await m.pay('999999', method: 'cash');
      expect(m.paymentUncertain, isTrue);
      s.status = 0;
      await m.pay('999999', method: 'cash');
      expect(s.payBodies[0], s.payBodies[1]);
      expect(s.payBodies[1], s.payBodies[2]);
      expect(m.receipt!['payment_method'], 'qris_manual');
      expect(s.payments, 1);
    },
  );
  test(
    'Refund preview freezes fields and request survives 429 then denial',
    () async {
      final s = RefundServer();
      final m = RefundController(s.api, 'SALE');
      addTearDown(m.dispose);
      await m.load();
      final q = {'L1': 1};
      await m.preview('SHIFT', 'quarantine', 'REF1', 'Checked', q);
      q['L1'] = 99;
      expect(s.writes, 0);
      expect(m.review!['lines'][0]['quantity'], 1);
      s.status = 429;
      await m.confirm();
      expect(m.uncertain, isTrue);
      final before = s.reads;
      await m.load();
      m.discard();
      expect(s.reads, before);
      expect(m.uncertain, isTrue);
      s.status = 403;
      await m.retry();
      expect(m.uncertain, isTrue);
      s.status = 0;
      await m.retry();
      expect(m.uncertain, isFalse);
      expect(s.bodies.toSet().length, 1);
      expect(s.writes, 1);
      expect(m.context!['sale']['version'], 2);
    },
  );
  test(
    'Foreign refund context and invalid quantities never enable submission',
    () async {
      final s = RefundServer()..wrong = true;
      final m = RefundController(s.api, 'SALE');
      addTearDown(m.dispose);
      await m.load();
      expect(m.context, isNull);
      expect(m.editable, isFalse);
      s.wrong = false;
      await m.load();
      await m.preview('FOREIGN', 'quarantine', 'R', 'Why', {'L1': 1});
      expect(m.review, isNull);
      await m.preview('SHIFT', 'quarantine', 'R', 'Why', {'L1': 4});
      expect(m.review, isNull);
      expect(s.bodies, isEmpty);
    },
  );
  test(
    'Return uses local quarantine and frozen destination across retry',
    () async {
      final s = ReturnServer();
      final m = ShipmentCaseController(s.repository);
      addTearDown(m.dispose);
      await m.load();
      expect(m.fresh, isTrue);
      final op = m.prepare('dispatch', {
        'handler': 'Courier',
        'lines': [
          {
            'original_shipment_line_id': 'L1',
            'allocations': [
              {'batch_id': 'B1', 'quantity_packs': 3},
            ],
          },
        ],
      });
      expect(op.body['destination_bucket'], 'quarantined');
      s.status = 503;
      await m.confirm(op);
      expect(m.pending, isNotNull);
      s.status = 403;
      await m.retry();
      expect(m.pending, isNotNull);
      s.status = 0;
      await m.retry();
      expect(m.pending, isNull);
      expect(s.posts.toSet().length, 1);
      expect(s.posts.first, contains('destination_bucket'));
    },
  );
  for (final width in [390.0, 1200.0]) {
    testWidgets('Refund form fits $width', (tester) async {
      tester.view.physicalSize = Size(width, 1200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final s = RefundServer();
      await tester.pumpWidget(
        MaterialApp(
          home: RefundPage(api: s.api, sale: 'SALE'),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Refund penjualan'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
    testWidgets('Return quarantine page fits $width', (tester) async {
      tester.view.physicalSize = Size(width, 1200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final s = ReturnServer();
      await tester.pumpWidget(
        MaterialApp(home: ShipmentCasePage(repository: s.repository)),
      );
      await tester.pumpAndSettle();
      expect(find.text('Retur karantina'), findsOneWidget);
      expect(find.text('Kirim retur karantina'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
