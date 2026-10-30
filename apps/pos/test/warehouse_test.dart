import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/fulfillment/application/warehouse_controller.dart';
import 'package:testyco_pos/features/fulfillment/data/warehouse_repository.dart';
import 'package:testyco_pos/features/fulfillment/presentation/warehouse_inbox_page.dart';
import 'package:testyco_pos/features/identity/domain/store.dart';

class WarehouseServer {
  int version = 2, writes = 0, sent = 0, pieces = 160;
  bool canProcess = true,
      lose = false,
      deny = false,
      failRead = false,
      capabilities = true;
  String status = 'approved', source = 'S1';
  final posts = <Map<String, dynamic>>[];
  final paths = <String>[], pages = <int>[];
  final memo = <String, Map<String, dynamic>>{};
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example/api/v1/'),
    client: MockClient(handle),
  );
  late final repository = WarehouseRepository(api);
  Map<String, dynamic> get order => {
    'id': 'O1',
    'version': version,
    'status': status,
    'delivery_mode': 'delivery',
    'destination_name': 'Toko A',
    'destination_address': 'Pacitan',
    'source_store_id': canProcess ? null : 'S1',
    'lines': [
      {
        'id': 'L1',
        'supplier_product_id': 'P1',
        'sku': 'AYAM8',
        'name': 'Ayam',
        'pack_size': 8,
        'quantity_packs': 20,
        'sent_packs': sent,
        'remaining_packs': 20 - sent,
      },
    ],
  };
  Map<String, dynamic> get context => {
    'order': order,
    'source': {'id': source, 'name': 'Gudang Pusat', 'active': true},
    if (capabilities) 'can_process': canProcess,
    if (capabilities) 'can_dispatch': !canProcess && sent < 20,
    'batches': [
      {
        'id': 'B1',
        'product_id': 'P1',
        'pack_size': 8,
        'batch_code': 'LOT-1',
        'expires_on': '2030-01-01',
        'usable_pieces': pieces,
      },
      {
        'id': 'B2',
        'product_id': 'P1',
        'pack_size': 8,
        'batch_code': 'LOT-2',
        'expires_on': '2030-02-01',
        'usable_pieces': 80,
      },
    ],
    'shipments': sent == 0
        ? []
        : [
            {
              'id': 'SH1',
              'handler': 'Petugas',
              'note': 'Fisikal',
              'received_at': null,
            },
          ],
    'observed_at': '2026-10-30T01:00:00Z',
  };
  http.Response reply(Map<String, dynamic> data, [int code = 200]) =>
      http.Response(
        jsonEncode(data),
        code,
        headers: {'content-type': 'application/json'},
      );
  Future<http.Response> handle(http.Request r) async {
    if (r.method == 'GET' && r.url.path.endsWith('/fulfillment/orders')) {
      final page = int.parse(r.url.queryParameters['page'] ?? '1');
      pages.add(page);
      return reply({
        'data': [order],
        'source': {'id': source, 'name': 'Gudang Pusat'},
        'current_page': page,
        'last_page': 2,
        'next_page_url': 'https://untrusted.test/steal',
      });
    }
    if (r.method == 'GET' && r.url.path.endsWith('/fulfillment/orders/O1')) {
      if (failRead) return reply({'message': 'Unavailable'}, 503);
      return reply({'data': context});
    }
    if (r.method == 'POST') {
      final body = jsonDecode(r.body) as Map<String, dynamic>;
      posts.add(body);
      paths.add(r.url.path);
      if (deny) return reply({'message': 'Denied'}, 403);
      final operation = body['operation_id'] as String;
      if (memo.containsKey(operation)) return reply({'data': memo[operation]});
      if (body['version'] != version) {
        return reply({'message': 'Stale order'}, 409);
      }
      if (r.url.path.endsWith('/process')) {
        canProcess = false;
        status = 'processing';
      } else if (r.url.path.endsWith('/shipments')) {
        var total = 0;
        for (final line in body['lines'] as List) {
          for (final a in line['allocations'] as List) {
            total += a['quantity_packs'] as int;
          }
        }
        if (total > 20 - sent || total * 8 > pieces) {
          return reply({'message': 'Stock/quota changed'}, 409);
        }
        sent += total;
        pieces -= total * 8;
        status = 'dispatched';
      } else {
        throw StateError('Unexpected POST');
      }
      writes++;
      version++;
      memo[operation] = {'id': 'O1', 'version': version};
      if (lose) {
        lose = false;
        throw http.ClientException('Lost after commit');
      }
      return reply({'data': memo[operation]});
    }
    throw StateError('Unexpected ${r.method} ${r.url}');
  }
}

void main() {
  WarehouseController modelFor(WarehouseServer s) {
    addTearDown(s.api.close);
    final m = WarehouseController(s.repository, 'S1', 'O1');
    addTearDown(m.dispose);
    return m;
  }

  test(
    'Process then reviewed dispatch uses exact source, lines, batches and original version',
    () async {
      final s = WarehouseServer();
      final m = modelFor(s);
      await m.load();
      final process = m.process();
      expect(s.posts, isEmpty);
      expect(process.body['source_store_id'], 'S1');
      await m.confirm(process);
      expect(m.context!.canProcess, false);
      expect(m.context!.canDispatch, true);
      m.quantity('L1:B1', '5');
      m.quantity('L1:B2', '3');
      final shipment = m.dispatch('Petugas fisik', 'Kondisi diperiksa');
      expect(s.posts.length, 1);
      expect(shipment.body['version'], 3);
      expect((shipment.body['lines'] as List).single['order_line_id'], 'L1');
      await m.confirm(shipment);
      expect(s.sent, 8);
      expect(s.pieces, 96);
      expect(m.context!.lines.single['remaining_packs'], 12);
      expect(m.quantities, isEmpty);
      expect(m.saved, true);
    },
  );
  test(
    'No default batch; overstock, overquota, fractions, negative, missing handler are rejected',
    () async {
      final s = WarehouseServer()..canProcess = false;
      final m = modelFor(s);
      await m.load();
      expect(() => m.dispatch('Petugas', ''), throwsA(isA<ApiException>()));
      for (final value in ['-1', '1.5', '10001', '0x10']) {
        m.quantity('L1:B1', value);
        expect(() => m.dispatch('Petugas', ''), throwsA(isA<ApiException>()));
      }
      m.quantity('L1:B1', '21');
      expect(() => m.dispatch('Petugas', ''), throwsA(isA<ApiException>()));
      m.quantity('L1:B1', '20');
      m.quantity('L1:B2', '1');
      expect(() => m.dispatch('Petugas', ''), throwsA(isA<ApiException>()));
      m.quantity('L1:B1', '2');
      m.quantity('L1:B2', '0');
      expect(() => m.dispatch(' ', ' '), throwsA(isA<ApiException>()));
      expect(s.posts, isEmpty);
    },
  );
  test(
    'Unknown dispatch keeps immutable nested original request across refresh, denial and later completion',
    () async {
      final s = WarehouseServer()
        ..canProcess = false
        ..lose = true;
      final m = modelFor(s);
      await m.load();
      m.quantity('L1:B1', '5');
      await m.confirm(m.dispatch('Petugas', 'Kondisi'));
      final original = jsonDecode(jsonEncode(s.posts.single));
      (m.pending!.body['lines'] as List)
              .first['allocations'][0]['quantity_packs'] =
          999;
      final epoch = m.epoch;
      await m.load();
      expect(m.epoch, epoch);
      expect(m.editable, false);
      s.deny = true;
      await m.retry();
      expect(m.pending, isNotNull);
      s.deny = false;
      s.sent = 20;
      s.status = 'completed';
      s.version = 9;
      await m.retry();
      expect(s.posts.last, original);
      expect(s.writes, 1);
      expect(m.pending, isNull);
      expect(m.context!.version, 9);
      expect(m.context!.canDispatch, false);
    },
  );
  test(
    'Unknown source selection replays same UUID and never dispatches implicitly',
    () async {
      final s = WarehouseServer()..lose = true;
      final m = modelFor(s);
      await m.load();
      await m.confirm(m.process());
      expect(m.pending, isNotNull);
      await m.retry();
      expect(s.posts.first, s.posts.last);
      expect(s.writes, 1);
      expect(s.paths.every((p) => p.endsWith('/process')), true);
      expect(s.sent, 0);
    },
  );
  test(
    'Stale version and lost read disable new actions until explicit reload and recheck',
    () async {
      final s = WarehouseServer()..canProcess = false;
      final m = modelFor(s);
      await m.load();
      m.quantity('L1:B1', '2');
      final old = m.dispatch('Petugas', '');
      s.version++;
      await m.confirm(old);
      expect(m.pending, isNull);
      expect(m.fresh, false);
      expect(s.writes, 0);
      await m.load();
      expect(m.quantities, isEmpty);
      await m.confirm(old);
      expect(s.posts.length, 1);
      m.quantity('L1:B1', '1');
      final next = m.dispatch('Petugas', '');
      s.failRead = true;
      await m.confirm(next);
      expect(m.saved, true);
      expect(m.pending, isNull);
      expect(m.fresh, false);
      await m.retry();
      expect(s.writes, 1);
    },
  );
  test(
    'Stock changed without order version change still rejects posting and requires physical recheck',
    () async {
      final s = WarehouseServer()..canProcess = false;
      final m = modelFor(s);
      await m.load();
      m.quantity('L1:B1', '20');
      final reviewed = m.dispatch('Petugas', 'Pemeriksaan');
      s.pieces = 8;
      await m.confirm(reviewed);
      expect(s.writes, 0);
      expect(s.sent, 0);
      expect(m.pending, isNull);
      expect(m.fresh, false);
      await m.load();
      expect(m.quantities, isEmpty);
      expect(m.context!.batches.first['usable_pieces'], 8);
    },
  );
  test(
    'Missing capabilities, wrong source and read failure never enable warehouse mutation',
    () async {
      final s = WarehouseServer()..capabilities = false;
      final m = modelFor(s);
      await m.load();
      expect(() => m.process(), throwsA(isA<ApiException>()));
      s.source = 'OTHER';
      await m.load();
      expect(m.fresh, false);
      s.source = 'S1';
      s.failRead = true;
      await m.load();
      expect(m.editable, false);
      expect(s.posts, isEmpty);
    },
  );
  test(
    'Navigation hint requires center role and legacy store metadata defaults hidden',
    () {
      for (final role in ['owner', 'admin', 'warehouse']) {
        expect(
          AssignedStore(
            id: 'S',
            name: 'S',
            active: true,
            role: role,
            organizationType: 'center',
          ).canFulfill,
          true,
        );
      }
      for (final role in ['partner_lead', 'store_manager', 'cashier']) {
        expect(
          AssignedStore(
            id: 'S',
            name: 'S',
            active: true,
            role: role,
            organizationType: 'center',
          ).canFulfill,
          false,
        );
      }
      expect(
        const AssignedStore(
          id: 'S',
          name: 'S',
          active: true,
          role: 'owner',
        ).canFulfill,
        false,
      );
      expect(
        const AssignedStore(
          id: 'S',
          name: 'S',
          active: true,
          role: 'owner',
          organizationType: 'partner',
        ).canFulfill,
        false,
      );
    },
  );
  for (final setting in [(390.0, 1.5), (1200.0, 1.0)]) {
    testWidgets(
      'Warehouse inbox process batch review and shipment at ${setting.$1}',
      (tester) async {
        tester.view.physicalSize = Size(setting.$1, 1600);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final s = WarehouseServer();
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
              body: WarehouseInboxPage(repository: s.repository, store: 'S1'),
            ),
          ),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Berikutnya'));
        await tester.pumpAndSettle();
        expect(s.pages, [1, 2]);
        await tester.tap(find.text('Toko A'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Gunakan gudang ini'));
        await tester.pumpAndSettle();
        expect(s.posts, isEmpty);
        await tester.tap(find.text('Konfirmasi pencatatan'));
        await tester.pumpAndSettle();
        expect(s.posts.single['source_store_id'], 'S1');
        final batch = find.widgetWithText(TextFormField, 'Kemasan dari LOT-1');
        await tester.ensureVisible(batch);
        await tester.enterText(batch, '5');
        final handler = find.widgetWithText(
          TextField,
          'Petugas / kurir / serah terima',
        );
        await tester.ensureVisible(handler);
        await tester.enterText(handler, 'Petugas fisik');
        final scroll = find
            .descendant(
              of: find.byKey(const Key('warehouse-order-scroll')),
              matching: find.byType(Scrollable),
            )
            .first;
        await tester.scrollUntilVisible(
          find.text('Tinjau pengiriman'),
          200,
          scrollable: scroll,
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Tinjau pengiriman'));
        await tester.pumpAndSettle();
        expect(find.textContaining('5 kemasan × 8 biji'), findsOneWidget);
        expect(s.sent, 0);
        await tester.tap(find.text('Konfirmasi pencatatan'));
        await tester.pumpAndSettle();
        expect(s.sent, 5);
        expect(s.pieces, 120);
        expect(s.writes, 2);
        await tester.ensureVisible(
          find.widgetWithText(TextFormField, 'Kemasan dari LOT-1'),
        );
        final field = find.descendant(
          of: find.widgetWithText(TextFormField, 'Kemasan dari LOT-1'),
          matching: find.byType(EditableText),
        );
        expect(tester.widget<EditableText>(field).controller.text, '');
        expect(tester.takeException(), isNull);
      },
    );
  }
}
