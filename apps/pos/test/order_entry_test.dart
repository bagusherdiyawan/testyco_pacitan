import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/ordering/application/order_entry_controller.dart';
import 'package:testyco_pos/features/ordering/data/order_entry_repository.dart';
import 'package:testyco_pos/features/ordering/data/orders_repository.dart';
import 'package:testyco_pos/features/ordering/presentation/orders_page.dart';
import 'package:testyco_pos/design_system/molecules/order_line_editor.dart';

class EntryServer {
  Map<String, dynamic> context = {
    'name': 'Pacitan',
    'address': 'Jalan toko',
    'active': true,
    'can_order': true,
    'order_kind': 'wholesale',
  };
  final sources = <Map<String, dynamic>>[
    {
      'id': 'P1',
      'organization_id': 'C1',
      'sku': 'AYAM8',
      'name': 'Ayam pusat',
      'condition': 'frozen',
      'pack_size': 8,
      'wholesale_price': 18000,
    },
    {
      'id': 'P2',
      'organization_id': 'C1',
      'sku': 'SAPI8',
      'name': 'Sapi pusat',
      'condition': 'frozen',
      'pack_size': 8,
      'wholesale_price': 20000,
    },
    {
      'id': 'P3',
      'organization_id': 'C2',
      'sku': 'OTHER8',
      'name': 'Pusat lain',
      'condition': 'frozen',
      'pack_size': 8,
      'wholesale_price': 22000,
    },
  ];
  final locals = <Map<String, dynamic>>[
    {
      'id': 'L1',
      'sku': 'LOCAL8',
      'name': 'Ayam toko',
      'condition': 'frozen',
      'pack_size': 8,
    },
    {
      'id': 'L2',
      'sku': 'LOCAL-SAPI8',
      'name': 'Sapi toko',
      'condition': 'frozen',
      'pack_size': 8,
    },
    {
      'id': 'BAD',
      'sku': 'BAD10',
      'name': 'Kemasan berbeda',
      'condition': 'frozen',
      'pack_size': 10,
    },
    {
      'id': 'READY',
      'sku': 'READY8',
      'name': 'Kondisi berbeda',
      'condition': 'ready',
      'pack_size': 8,
    },
  ];
  final previews = <Map<String, dynamic>>[], creates = <Map<String, dynamic>>[];
  final saved = <String, String>{};
  int catalogueReads = 0, listReads = 0, createdTotal = 0;
  bool deniedContext = false,
      deniedCreate = false,
      loseResponse = false,
      failContext = false,
      changed = false;
  String get hash => List.filled(64, changed ? 'b' : 'a').join();
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example.test/api/v1/'),
    client: MockClient(handle),
  );
  OrderEntryRepository get repository => OrderEntryRepository(api);
  http.Response reply(Object body, [int status = 200]) =>
      http.Response(jsonEncode(body), status);
  List<Map<String, dynamic>> quoteLines(Map<String, dynamic> body) => [
    for (final row in body['lines'] as List)
      {
        'supplier_product_id': row['supplier_product_id'],
        'local_product_id': row['local_product_id'],
        'sku': sources.firstWhere(
          (p) => p['id'] == row['supplier_product_id'],
        )['sku'],
        'local_sku': locals.firstWhere(
          (p) => p['id'] == row['local_product_id'],
        )['sku'],
        'quantity_packs': row['quantity_packs'],
        'pack_size': 8,
        'unit_price': context['order_kind'] == 'internal_transfer'
            ? 0
            : changed
            ? 19000
            : sources.firstWhere(
                (p) => p['id'] == row['supplier_product_id'],
              )['wholesale_price'],
      },
  ];
  Future<http.Response> handle(http.Request r) async {
    final path = r.url.path;
    if (r.method == 'GET') {
      if (path.endsWith('/operations-context')) {
        if (deniedContext) {
          return reply({'message': 'Denied'}, 403);
        }
        if (failContext) {
          return reply({}, 503);
        }
        return reply({'data': context});
      }
      if (path.endsWith('/supply-catalog')) {
        catalogueReads++;
        return reply({'data': sources});
      }
      if (path.endsWith('/catalog')) {
        catalogueReads++;
        return reply({'data': locals});
      }
      if (path.endsWith('/stores/S1/orders')) {
        listReads++;
        return reply({
          'data': saved.isEmpty
              ? []
              : [
                  {
                    'id': 'O9',
                    'status': 'awaiting_approval',
                    'total_amount': createdTotal,
                  },
                ],
        });
      }
      if (path.endsWith('/orders/O9')) {
        return reply({
          'data': {
            'id': 'O9',
            'destination_name': 'Pacitan',
            'kind': context['order_kind'],
            'status': 'awaiting_approval',
            'version': 1,
            'total_amount': createdTotal,
            'fulfillment_enabled': false,
            'recipient_actions': {
              'approve': false,
              'reject': false,
              'cancel': false,
              'receive': false,
            },
            'lines': quoteLines(creates.first),
          },
        });
      }
      throw StateError('Unexpected GET $path');
    }
    final body = jsonDecode(r.body) as Map<String, dynamic>;
    if (path.endsWith('/preview')) {
      previews.add(body);
      final lines = quoteLines(body);
      final total = lines.fold<int>(
        0,
        (sum, l) =>
            sum + (l['quantity_packs'] as int) * (l['unit_price'] as int),
      );
      return reply({
        'data': {
          'destination_name': 'Pacitan',
          'destination_address': context['address'],
          'kind': context['order_kind'],
          'delivery_mode': body['delivery_mode'],
          'total_amount': total,
          'review_hash': hash,
          'observed_at': '2026-10-29T03:00:00Z',
          'lines': lines,
        },
      });
    }
    if (path.endsWith('/orders')) {
      creates.add(body);
      if (deniedCreate) {
        return reply({'message': 'Denied'}, 403);
      }
      final operation = body['operation_id'] as String;
      if (saved.containsKey(operation)) {
        return reply({
          'data': {'id': saved[operation]},
        }, 201);
      }
      if (body['review_hash'] != hash) {
        return reply({
          'message': 'Data pengajuan berubah setelah ditinjau',
        }, 409);
      }
      saved[operation] = 'O9';
      createdTotal = quoteLines(body).fold<int>(
        0,
        (sum, l) =>
            sum + (l['quantity_packs'] as int) * (l['unit_price'] as int),
      );
      if (loseResponse) {
        loseResponse = false;
        throw http.ClientException('Lost after commit');
      }
      return reply({
        'data': {'id': 'O9'},
      }, 201);
    }
    throw StateError('Unexpected POST $path');
  }
}

void main() {
  OrderEntryController modelFor(EntryServer s) {
    addTearDown(s.api.close);
    final m = OrderEntryController(s.repository, 'S1');
    addTearDown(m.dispose);
    return m;
  }

  Future<void> draft(OrderEntryController m) async {
    await m.load();
    m.add(m.suppliers.first);
    m.local(m.cart.first, 'L1');
    m.quantity(m.cart.first, '20');
  }

  test(
    'Explicit mapping and server preview precede create without client prices',
    () async {
      final s = EntryServer();
      final m = modelFor(s);
      await draft(m);
      expect(m.candidates(m.cart.first).map((p) => p.id), ['L1', 'L2']);
      expect(await m.review(), true);
      expect(m.preview!.total, 360000);
      expect(s.saved, isEmpty);
      expect(s.creates, isEmpty);
      expect(s.previews.single.keys.toSet(), {
        'delivery_mode',
        'note',
        'lines',
      });
      expect((s.previews.single['lines'] as List).single, {
        'supplier_product_id': 'P1',
        'local_product_id': 'L1',
        'quantity_packs': 20,
      });
      expect(await m.confirm(), true);
      expect(m.createdId, 'O9');
      expect(s.saved.length, 1);
      expect(s.creates.single['review_hash'], s.hash);
      await m.confirm();
      expect(s.creates.length, 1);
    },
  );
  test(
    'Invalid quantity mapping duplicates and mixed centers never preview or create',
    () async {
      final s = EntryServer();
      final m = modelFor(s);
      await m.load();
      m.add(m.suppliers.first);
      expect(await m.review(), false);
      m.local(m.cart.first, 'BAD');
      m.quantity(m.cart.first, '1');
      expect(await m.review(), false);
      m.local(m.cart.first, 'L1');
      for (final bad in ['0', '1.5', '-1', '10001']) {
        m.quantity(m.cart.first, bad);
        expect(await m.review(), false);
      }
      m.quantity(m.cart.first, '1');
      m.add(m.suppliers.last);
      expect(m.cart.length, 1);
      m.add(m.suppliers[1]);
      m.local(m.cart.last, 'L1');
      m.quantity(m.cart.last, '1');
      expect(await m.review(), false);
      expect(s.previews, isEmpty);
      expect(s.creates, isEmpty);
    },
  );
  test(
    'Review locks editing and refresh preserves intent while requiring a new quote',
    () async {
      final s = EntryServer();
      final m = modelFor(s);
      await draft(m);
      await m.review();
      m.quantity(m.cart.first, '99');
      m.remove(m.cart.first);
      expect(m.cart.first.quantity, '20');
      await m.load();
      expect(m.preview, isNull);
      expect(m.cart.first.quantity, '20');
      expect(m.canEdit, true);
      await m.review();
      s.failContext = true;
      await m.load();
      expect(m.fresh, false);
      expect(await m.confirm(), false);
      expect(s.creates, isEmpty);
    },
  );
  test(
    'Definitive stale review rolls back local confirmation and requires explicit new review',
    () async {
      final s = EntryServer();
      final m = modelFor(s);
      await draft(m);
      await m.review();
      s.changed = true;
      expect(await m.confirm(), false);
      expect(m.preview, isNull);
      expect(m.pending, isNull);
      expect(m.fresh, false);
      expect(s.saved, isEmpty);
      await m.load();
      await m.review();
      expect(m.preview!.total, 380000);
      expect(await m.confirm(), true);
      expect(s.saved.length, 1);
      expect(
        s.creates.first['operation_id'],
        isNot(s.creates.last['operation_id']),
      );
    },
  );
  test(
    'Unknown result retains nested request across refresh price changes and retry',
    () async {
      final s = EntryServer()..loseResponse = true;
      final m = modelFor(s);
      await draft(m);
      await m.review();
      expect(await m.confirm(), false);
      expect(m.pending, isNotNull);
      expect(s.saved.length, 1);
      final original = jsonDecode(jsonEncode(s.creates.first));
      (m.pending!.body['lines'] as List).first['quantity_packs'] = 999;
      m.quantity(m.cart.first, '999');
      m.edit();
      expect(await m.review(), false);
      expect(s.previews.length, 1);
      s.changed = true;
      await m.load();
      expect(m.pending, isNotNull);
      expect(await m.retry(), true);
      expect(s.creates.last, original);
      expect(m.createdId, 'O9');
      expect(s.saved.length, 1);
      expect(s.createdTotal, 360000);
    },
  );
  test(
    'Authorization failure after unknown outcome never rotates or discards the pending request',
    () async {
      final s = EntryServer()..loseResponse = true;
      final m = modelFor(s);
      await draft(m);
      await m.review();
      await m.confirm();
      s.deniedCreate = true;
      expect(await m.retry(), false);
      expect(m.pending, isNotNull);
      expect(s.creates.first, s.creates.last);
      s.deniedCreate = false;
      expect(await m.retry(), true);
      expect(s.saved.length, 1);
    },
  );
  test(
    'Inactive denied and legacy context do not load commercial catalogues',
    () async {
      final s = EntryServer()..deniedContext = true;
      final m = modelFor(s);
      expect(await m.load(), false);
      expect(s.catalogueReads, 0);
      s.deniedContext = false;
      s.context = {...s.context, 'active': false};
      await m.load();
      expect(m.canEdit, false);
      expect(s.catalogueReads, 0);
      s.context = {...s.context, 'active': true};
      s.context.remove('order_kind');
      await m.load();
      expect(m.canEdit, false);
      expect(s.catalogueReads, 0);
    },
  );
  test(
    'Internal transfer only maps identical SKU and uses server zero value',
    () async {
      final s = EntryServer();
      s.context = {...s.context, 'order_kind': 'internal_transfer'};
      s.locals.add({
        'id': 'P1',
        'sku': 'AYAM8',
        'name': 'Ayam pusat',
        'condition': 'frozen',
        'pack_size': 8,
      });
      final m = modelFor(s);
      await m.load();
      m.add(m.suppliers.first);
      m.quantity(m.cart.first, '3');
      expect(m.cart.first.localId, 'P1');
      expect(m.candidates(m.cart.first).map((p) => p.id), ['P1']);
      expect(await m.review(), true);
      expect(m.preview!.total, 0);
      expect(await m.confirm(), true);
      expect(s.createdTotal, 0);
    },
  );
  test(
    'Delivery without address does not preview or invent a destination',
    () async {
      final s = EntryServer();
      s.context = {...s.context, 'address': null};
      final m = modelFor(s);
      await draft(m);
      m.delivery('delivery');
      expect(await m.review(), false);
      expect(s.previews, isEmpty);
    },
  );
  test('Catalogue pagination remains on scoped API paths', () async {
    final seen = <Uri>[];
    final api = ApiClient(
      baseUrl: Uri.parse('https://api.example.test/api/v1/'),
      client: MockClient((r) async {
        seen.add(r.url);
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': r.url.queryParameters['page'] == '2' ? 'P2' : 'P1',
                'organization_id': 'C1',
                'sku': 'SKU',
                'name': 'Product',
                'condition': 'frozen',
                'pack_size': 8,
              },
            ],
            'last_page': 2,
            'next_page_url': 'https://untrusted.test/collect',
          }),
          200,
        );
      }),
    );
    addTearDown(api.close);
    expect((await OrderEntryRepository(api).suppliers('S1')).length, 2);
    expect(
      seen.every(
        (u) =>
            u.host == 'api.example.test' &&
            u.path == '/api/v1/stores/S1/supply-catalog',
      ),
      true,
    );
  });
  for (final setting in [(390.0, 1.5), (1200.0, 1.0)]) {
    testWidgets(
      'New order via list catalogue mapping review and persisted detail at ${setting.$1}',
      (tester) async {
        tester.view.physicalSize = Size(setting.$1, 1200);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final s = EntryServer();
        if (setting.$1 < 500) {
          s.loseResponse = true;
        }
        addTearDown(s.api.close);
        await tester.pumpWidget(
          MaterialApp(
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(
                context,
              ).copyWith(textScaler: TextScaler.linear(setting.$2)),
              child: child!,
            ),
            home: Scaffold(
              body: OrdersPage(
                repository: OrdersRepository(s.api),
                storeId: 'S1',
              ),
            ),
          ),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Buat pengajuan'));
        await tester.pumpAndSettle();
        await tester.tap(find.byTooltip('Tambah AYAM8'));
        await tester.pumpAndSettle();
        final editor = find.byType(OrderLineEditor);
        final dropdown = find.descendant(
          of: editor,
          matching: find.byType(DropdownButtonFormField<String>),
        );
        await tester.ensureVisible(dropdown);
        await tester.pumpAndSettle();
        await tester.tap(dropdown);
        await tester.pumpAndSettle();
        await tester.tap(find.text('LOCAL8 · Ayam toko').last);
        await tester.pumpAndSettle();
        await tester.enterText(
          find
              .descendant(of: editor, matching: find.byType(TextFormField))
              .last,
          '20',
        );
        final scroll = find
            .descendant(
              of: find.byKey(const Key('order-entry-scroll')),
              matching: find.byType(Scrollable),
            )
            .first;
        await tester.scrollUntilVisible(
          find.text('Tinjau pengajuan di server'),
          250,
          scrollable: scroll,
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Tinjau pengajuan di server'));
        await tester.pumpAndSettle();
        expect(find.text('Total Rp 360.000'), findsOneWidget);
        expect(s.saved, isEmpty);
        await tester.ensureVisible(find.text('Konfirmasi pengajuan'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Konfirmasi pengajuan'));
        await tester.pumpAndSettle();
        expect(s.saved.length, 1);
        if (setting.$1 < 500) {
          expect(
            tester.widget<PopScope>(find.byType(PopScope).first).canPop,
            false,
          );
          await tester.scrollUntilVisible(
            find.text('Ulangi pengajuan yang sama'),
            -250,
            scrollable: scroll,
          );
          await tester.pumpAndSettle();
          await tester.tap(find.text('Ulangi pengajuan yang sama'));
          await tester.pumpAndSettle();
          expect(s.creates.first, s.creates.last);
          expect(s.saved.length, 1);
        }
        await tester.tap(find.text('Lihat detail pengajuan'));
        await tester.pumpAndSettle();
        expect(find.text('Pacitan'), findsOneWidget);
        expect(find.textContaining('awaiting_approval'), findsOneWidget);
        expect(find.text('Setujui pengajuan'), findsNothing);
        expect(tester.takeException(), isNull);
      },
    );
  }
}
