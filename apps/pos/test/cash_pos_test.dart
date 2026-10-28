import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/sales/application/pos_controller.dart';
import 'package:testyco_pos/features/sales/data/pos_repository.dart';
import 'package:testyco_pos/features/sales/presentation/pos_page.dart';

class FakeCashServer {
  bool opened = false,
      paid = false,
      losePaymentResponse = false,
      depleted = false;
  int payments = 0, version = 1;
  final payBodies = <Map<String, dynamic>>[];
  Map<String, dynamic> get shift => {
    'id': 'SHIFT',
    'device_name': 'Terminal 1',
    'status': 'open',
    'version': version,
    'opening_cash': 100000,
    'expected_cash': paid ? 112000 : 100000,
    'cash_sales': paid ? 12000 : 0,
  };
  Map<String, dynamic> get draft => {
    'id': 'INTENT',
    'status': paid ? 'paid' : 'pending',
    'store_id': 'STORE',
    'total_amount': 12000,
    'items': [
      {
        'product_id': 'PRODUCT',
        'sku': 'AYAM',
        'name': 'Ayam',
        'quantity': 4,
        'unit': 'piece',
        'pieces_per_unit': 1,
        'unit_price': 3000,
        'line_total': 12000,
      },
    ],
  };
  Map<String, dynamic> get receipt => {
    'id': 'SALE',
    'number': 'POS-123',
    'store_id': 'STORE',
    'store_name': 'Toko Pacitan',
    'cashier_name': 'Kasir',
    'sold_at': '2026-10-28T03:00:00Z',
    'total_amount': 12000,
    'cash_received': 20000,
    'change_amount': 8000,
    'lines': draft['items'],
  };
  bool hasDraft = false;
  http.Response response(Object body, [int code = 200]) => http.Response(
    jsonEncode(body),
    code,
    headers: {'content-type': 'application/json; charset=utf-8'},
  );
  Future<http.Response> handle(http.Request r) async {
    final path = r.url.path;
    final body = r.body.isEmpty
        ? <String, dynamic>{}
        : jsonDecode(r.body) as Map<String, dynamic>;
    if (path.endsWith('/pos/context')) {
      return response({
        'data': {
          'store': {'id': 'STORE', 'active': true, 'name': 'Toko Pacitan'},
          'shift': opened ? shift : null,
          'pending_intents': hasDraft && !paid
              ? [
                  {'id': 'INTENT', 'total_amount': 12000},
                ]
              : [],
          'recent_sales': paid
              ? [
                  {'id': 'SALE', 'number': 'POS-123', 'total_amount': 12000},
                ]
              : [],
        },
      });
    }
    if (path.endsWith('/catalog')) {
      return response({
        'data': [
          {
            'id': 'PRODUCT',
            'sku': 'AYAM',
            'name': 'Ayam',
            'condition': 'frozen',
            'pack_size': 10,
            'portion_size': 4,
            'retail_price': 3000,
          },
        ],
      });
    }
    if (path.endsWith('/inventory/batches')) {
      return response({
        'data': [
          {
            'id': 'BATCH',
            'product_id': 'PRODUCT',
            'condition': 'frozen',
            'batch_code': 'LOT',
            'pack_size': 10,
            'usable_pieces': 60,
            'opened_pieces': 10,
            'ready_pieces': depleted
                ? 0
                : paid
                ? 26
                : 30,
          },
        ],
      });
    }
    if (path.endsWith('/shifts') && r.method == 'POST') {
      opened = true;
      return response({'data': shift}, 201);
    }
    if (path.endsWith('/sales/intents') && r.method == 'POST') {
      expect(body['lines'][0]['quantity'], 4);
      expect(body['lines'][0]['allocations'][0]['bucket'], 'ready');
      expect(body.containsKey('total_amount'), isFalse);
      hasDraft = true;
      return response({'data': draft}, 201);
    }
    if (path.endsWith('/sales/intents/INTENT')) {
      return response({
        'data': {
          'intent': draft,
          'sale': paid ? receipt : null,
          'is_owner': true,
        },
      });
    }
    if (path.endsWith('/pay')) {
      payBodies.add(body);
      if (!paid) {
        paid = true;
        payments++;
        version++;
      }
      if (losePaymentResponse) {
        losePaymentResponse = false;
        throw http.ClientException('response lost after commit');
      }
      return response({'data': receipt});
    }
    if (path.endsWith('/cancel')) {
      hasDraft = false;
      return response({
        'data': {...draft, 'status': 'cancelled'},
      });
    }
    if (path.endsWith('/close')) {
      expect(body['counted_cash'], 112000);
      expect(body['version'], version);
      opened = false;
      return response({
        'data': {
          ...shift,
          'status': 'closed',
          'counted_cash': 112000,
          'difference_cash': 0,
        },
      });
    }
    if (path.endsWith('/sales/SALE')) {
      return response({'data': receipt});
    }
    return response({'message': 'Unexpected request $path'}, 404);
  }
}

PosRepository repository(FakeCashServer server) => PosRepository(
  ApiClient(
    baseUrl: Uri.parse('https://api.example.test/api/v1/'),
    client: MockClient(server.handle),
  ),
);
Future<PosController> readyController(FakeCashServer server) async {
  server.opened = true;
  final model = PosController(repository(server), 'STORE');
  await model.load();
  model.add(model.products.single);
  final entry = model.cart.values.single;
  model.quantity(entry, '4');
  model.stock(entry, 'BATCH:ready');
  await model.review();
  return model;
}

void main() {
  test(
    'Unknown payment response retains exact operation and cash for retry',
    () async {
      final server = FakeCashServer();
      final model = await readyController(server);
      addTearDown(model.dispose);
      server.losePaymentResponse = true;
      await model.pay('20000');
      expect(model.paymentUncertain, isTrue);
      expect(model.intent, isNotNull);
      expect(model.receipt, isNull);
      await model.pay('999999');
      expect(server.payBodies.length, 2);
      expect(server.payBodies[0], server.payBodies[1]);
      expect(model.receipt!['change_amount'], 8000);
      expect(server.payments, 1);
    },
  );
  test(
    'Refresh reconciles committed payment to receipt without another POST',
    () async {
      final server = FakeCashServer();
      final model = await readyController(server);
      addTearDown(model.dispose);
      server.losePaymentResponse = true;
      await model.pay('20000');
      await model.load();
      expect(model.receipt!['id'], 'SALE');
      expect(model.intent, isNull);
      expect(server.payBodies.length, 1);
    },
  );
  test(
    'Pending server draft is recoverable after controller restart',
    () async {
      final server = FakeCashServer();
      final first = await readyController(server);
      first.dispose();
      final second = PosController(repository(server), 'STORE');
      addTearDown(second.dispose);
      await second.load();
      expect(second.pending.single['id'], 'INTENT');
      await second.recover('INTENT');
      expect(second.intent!['total_amount'], 12000);
      await second.pay('20000');
      expect(server.payments, 1);
    },
  );
  test(
    'Loose stock choices exclude intact packages and pending drafts block accidental new review',
    () async {
      final server = FakeCashServer();
      server.opened = true;
      server.hasDraft = true;
      final model = PosController(repository(server), 'STORE');
      addTearDown(model.dispose);
      await model.load();
      model.add(model.products.single);
      final entry = model.cart.values.single;
      expect(model.choices(entry).any((c) => c['bucket'] == 'usable'), isFalse);
      model.unit(entry, 'pack');
      expect(
        model.choices(entry).every((c) => c['bucket'] == 'usable'),
        isTrue,
      );
      await model.review();
      expect(model.error, contains('Pulihkan'));
      expect(server.payments, 0);
    },
  );
  test('Refreshing depleted stock clears stale dropdown selection', () async {
    final server = FakeCashServer();
    server.opened = true;
    final model = PosController(repository(server), 'STORE');
    addTearDown(model.dispose);
    await model.load();
    model.add(model.products.single);
    final entry = model.cart.values.single;
    model.stock(entry, 'BATCH:ready');
    server.depleted = true;
    await model.load();
    expect(entry.stock, isNull);
    expect(model.choices(entry).any((c) => c['bucket'] == 'ready'), isFalse);
  });
  for (final width in [390.0, 1200.0]) {
    testWidgets(
      'Cash POS opens shift, checks out, shows change and closes at $width',
      (tester) async {
        tester.view.physicalSize = Size(width, 1200);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final server = FakeCashServer();
        await tester.pumpWidget(
          MaterialApp(
            home: Scaffold(
              body: PosPage(repository: repository(server), storeId: 'STORE'),
            ),
          ),
        );
        await tester.pumpAndSettle();
        Finder field(String label) => find.byWidgetPredicate(
          (w) => w is TextField && w.decoration?.labelText == label,
        );
        await tester.enterText(field('Nama terminal'), 'Terminal 1');
        await tester.enterText(field('Kas awal (Rp)'), '100000');
        await tester.tap(find.text('Buka shift'));
        await tester.pumpAndSettle();
        await tester.tap(find.byTooltip('Tambah AYAM'));
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.byKey(const ValueKey('qty-PRODUCT')));
        await tester.enterText(find.byKey(const ValueKey('qty-PRODUCT')), '4');
        final stock = find.byWidgetPredicate(
          (w) =>
              w is DropdownButtonFormField<String> &&
              w.decoration.labelText == 'Batch / keadaan stok',
        );
        await tester.ensureVisible(stock);
        await tester.tap(stock);
        await tester.pumpAndSettle();
        await tester.tap(find.text('LOT · ready · 30 biji').last);
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.text('Tinjau pembayaran'));
        await tester.tap(find.text('Tinjau pembayaran'));
        await tester.pumpAndSettle();
        expect(find.text('Total Rp 12000'), findsOneWidget);
        await tester.enterText(field('Tunai diterima (Rp)'), '20000');
        await tester.tap(find.text('Konfirmasi tunai'));
        await tester.pumpAndSettle();
        expect(find.text('Kembalian Rp 8000'), findsOneWidget);
        expect(find.textContaining('10:00 WIB'), findsOneWidget);
        expect(server.payments, 1);
        await tester.ensureVisible(find.text('Transaksi baru'));
        await tester.tap(find.text('Transaksi baru'));
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.text('Tutup shift'));
        await tester.tap(find.text('Tutup shift'));
        await tester.pumpAndSettle();
        await tester.ensureVisible(field('Kas fisik hasil hitungan (Rp)'));
        final textField = tester.widget<TextField>(
          field('Kas fisik hasil hitungan (Rp)'),
        );
        expect(textField.controller!.text, isEmpty);
        await tester.enterText(
          field('Kas fisik hasil hitungan (Rp)'),
          '112000',
        );
        await tester.ensureVisible(find.text('Konfirmasi tutup shift'));
        await tester.tap(find.text('Konfirmasi tutup shift'));
        await tester.pumpAndSettle();
        expect(find.text('Shift ditutup · Selisih Rp 0'), findsOneWidget);
        expect(server.opened, isFalse);
        expect(tester.takeException(), isNull);
      },
    );
  }
}
