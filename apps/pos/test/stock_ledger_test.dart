import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/design_system/formatters.dart';
import 'package:testyco_pos/features/inventory/application/stock_ledger_controller.dart';
import 'package:testyco_pos/features/inventory/data/stock_ledger_repository.dart';
import 'package:testyco_pos/features/inventory/data/inventory_repository.dart';
import 'package:testyco_pos/features/inventory/domain/stock_ledger.dart';
import 'package:testyco_pos/features/inventory/presentation/inventory_page.dart';
import 'package:testyco_pos/features/inventory/presentation/stock_ledger_page.dart';

class LedgerServer {
  bool denied = false, unavailable = false, empty = false, legacyTime = false;
  String store = 'S1';
  final queries = <Map<String, String>>[], methods = <String>[];
  Map<String, dynamic> get movement => {
    'id': 'E1',
    'inventory_batch_id': 'B1',
    'sku': 'OLD8',
    'name': 'Nama historis',
    'batch_code': 'LOT-A',
    'condition': 'frozen',
    'pack_size': 8,
    'quantity_pieces': -8,
    'bucket': 'usable',
    'source_type': 'shipment_dispatch',
    'source_id': 'SL1',
    'actor_id': 7,
    'note': 'Pemeriksaan fisik',
    'created_at': '2026-10-29 17:00:00',
    if (!legacyTime) 'occurred_at': '2026-10-29T17:00:00+00:00',
  };
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example/api/v1/'),
    client: MockClient(handle),
  );
  late final repository = StockLedgerRepository(api);
  http.Response reply(Map<String, dynamic> data, [int status = 200]) =>
      http.Response(
        jsonEncode(data),
        status,
        headers: {'content-type': 'application/json'},
      );
  Future<http.Response> handle(http.Request r) async {
    methods.add(r.method);
    if (r.method != 'GET') {
      throw StateError('Read-only ledger attempted mutation');
    }
    if (r.url.path.endsWith('/inventory/batches')) {
      return reply({
        'data': [
          {
            'id': 'B1',
            'sku': 'OLD8',
            'name': 'Nama historis',
            'batch_code': 'LOT-A',
            'expires_on': '2030-01-01',
            'pack_size': 8,
            'usable_pieces': 8,
            'quarantined_pieces': 0,
          },
        ],
        'last_page': 1,
      });
    }
    if (!r.url.path.endsWith('/inventory/ledger')) {
      throw StateError('Unexpected ${r.url}');
    }
    expect(r.url.host, 'api.example');
    queries.add(r.url.queryParameters);
    if (denied) return reply({'message': 'Tidak berwenang'}, 403);
    if (unavailable) return reply({}, 503);
    return reply({
      'data': empty ? [] : [movement],
      'store': {'id': store, 'name': 'A1', 'active': true},
      'observed_at': '2026-10-30T03:00:00Z',
      'current_page': int.parse(r.url.queryParameters['page'] ?? '1'),
      'last_page': 2,
      'next_page_url': 'https://untrusted.test/collect',
    });
  }
}

void main() {
  test(
    'WIB inclusive calendar range becomes explicit timezone half-open interval',
    () {
      final f = LedgerFilter.form(
        '2026-10-30',
        '2026-10-31',
        query: ' LOT-A ',
        batchId: 'B1',
        source: 'shipment_dispatch',
        bucket: 'usable',
      );
      expect(f.params, {
        'from': '2026-10-30T00:00:00+07:00',
        'to': '2026-11-01T00:00:00+07:00',
        'q': 'LOT-A',
        'batch_id': 'B1',
        'source_type': 'shipment_dispatch',
        'bucket': 'usable',
      });
      expect(f.allBatches().batchId, isNull);
      expect(f.allBatches().source, 'shipment_dispatch');
      for (final pair in [
        ('2026-02-30', '2026-03-01'),
        ('2026-10-31', '2026-10-30'),
        ('2026-10-01', '2026-11-01'),
      ]) {
        expect(
          () => LedgerFilter.form(pair.$1, pair.$2),
          throwsA(isA<LedgerFilterException>()),
        );
      }
      expect(
        () => LedgerFilter.form('2026-10-01', '2026-10-31'),
        returnsNormally,
      );
    },
  );
  test(
    'Legacy raw UTC and explicit timestamps render the same WIB midnight',
    () {
      final s = LedgerServer();
      final explicit = StockMovement.fromJson(s.movement);
      s.legacyTime = true;
      final legacy = StockMovement.fromJson(s.movement);
      expect(formatWib(explicit.occurredAt), '2026-10-30 00:00 WIB');
      expect(formatWib(legacy.occurredAt), '2026-10-30 00:00 WIB');
      expect(DateTime.parse(legacy.occurredAt).isUtc, true);
      expect(DateTime.parse(explicit.occurredAt).isUtc, true);
    },
  );
  test(
    'Pagination retains scoped filters, signed pieces and source references without mutation',
    () async {
      final s = LedgerServer();
      addTearDown(s.api.close);
      final f = LedgerFilter.form(
        '2026-10-30',
        '2026-10-30',
        query: 'LOT-A',
        batchId: 'B1',
        source: 'shipment_dispatch',
      );
      final r = await s.repository.load('S1', f, page: 2);
      expect(r.rows.single.quantity, -8);
      expect(r.rows.single.packSize, 8);
      expect(r.rows.single.sourceId, 'SL1');
      expect(s.queries.single, {...f.params, 'page': '2'});
      expect(s.methods, ['GET']);
    },
  );
  test(
    'Error removes stale data; retry uses same applied scope and wrong-store response is rejected',
    () async {
      final s = LedgerServer();
      addTearDown(s.api.close);
      final m = StockLedgerController(s.repository, 'S1', batchId: 'B1');
      addTearDown(m.dispose);
      await m.load();
      expect(m.result, isNotNull);
      s.unavailable = true;
      await m.load();
      expect(m.result, isNull);
      expect(m.error, isNotNull);
      s.unavailable = false;
      s.store = 'OTHER';
      await m.load();
      expect(m.result, isNull);
      expect(m.error, contains('toko'));
      s.store = 'S1';
      await m.load();
      expect(m.result!.rows.single.quantity, -8);
      expect(s.queries.every((q) => q['batch_id'] == 'B1'), true);
      s.denied = true;
      await m.load();
      expect(m.result, isNull);
      expect(m.error, contains('Tidak berwenang'));
      expect(s.methods.toSet(), {'GET'});
    },
  );
  for (final setting in [(390.0, 1.5), (1200.0, 1.0)]) {
    testWidgets(
      'Batch history navigation filters pagination and signed source metadata at ${setting.$1}',
      (tester) async {
        tester.view.physicalSize = Size(setting.$1, 1800);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final s = LedgerServer();
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
              body: InventoryPage(
                repository: InventoryRepository(s.api),
                storeId: 'S1',
                canControl: true,
              ),
            ),
          ),
        );
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.text('Riwayat batch ini'));
        await tester.tap(find.text('Riwayat batch ini'));
        await tester.pumpAndSettle();
        expect(s.queries.single['batch_id'], 'B1');
        expect(find.text('-8 biji · Utuh'), findsOneWidget);
        expect(find.text('Pelaku #7'), findsOneWidget);
        expect(find.text('Referensi sumber: SL1'), findsOneWidget);
        expect(find.textContaining('2026-10-30 00:00 WIB'), findsOneWidget);
        await tester.tap(find.text('Filter riwayat'));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.widgetWithText(TextField, 'Tanggal awal WIB (YYYY-MM-DD)'),
          '2026-10-30',
        );
        await tester.enterText(
          find.widgetWithText(TextField, 'Tanggal akhir WIB (YYYY-MM-DD)'),
          '2026-10-31',
        );
        await tester.enterText(
          find.widgetWithText(TextField, 'Cari SKU / nama / batch'),
          'LOT-A',
        );
        await tester.ensureVisible(find.text('Terapkan filter'));
        await tester.tap(find.text('Terapkan filter'));
        await tester.pumpAndSettle();
        expect(s.queries.last['from'], '2026-10-30T00:00:00+07:00');
        expect(s.queries.last['q'], 'LOT-A');
        final scroll = find
            .descendant(
              of: find.byKey(const Key('ledger-scroll')),
              matching: find.byType(Scrollable),
            )
            .first;
        await tester.scrollUntilVisible(
          find.text('Berikutnya'),
          250,
          scrollable: scroll,
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Berikutnya'));
        await tester.pumpAndSettle();
        expect(s.queries.last['page'], '2');
        expect(s.queries.last['batch_id'], 'B1');
        expect(s.queries.last['q'], 'LOT-A');
        await tester.ensureVisible(find.text('Lihat semua batch'));
        await tester.tap(find.text('Lihat semua batch'));
        await tester.pumpAndSettle();
        expect(s.queries.last.containsKey('batch_id'), false);
        expect(s.queries.last['page'], '1');
        expect(s.methods.toSet(), {'GET'});
        expect(tester.takeException(), isNull);
      },
    );
  }
  testWidgets(
    'Ledger failure is not presented as empty history and retry recovers',
    (tester) async {
      final s = LedgerServer()..unavailable = true;
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: StockLedgerHistoryPage(repository: s.repository, store: 'S1'),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.textContaining('Tidak ada pergerakan'), findsNothing);
      expect(find.textContaining('Layanan API'), findsOneWidget);
      s.unavailable = false;
      await tester.tap(find.text('Coba kembali'));
      await tester.pumpAndSettle();
      expect(find.text('-8 biji · Utuh'), findsOneWidget);
    },
  );
  testWidgets(
    'Cashier navigation hides ledger; forged read-only deep-link still shows API denial',
    (tester) async {
      final s = LedgerServer();
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: InventoryPage(
              repository: InventoryRepository(s.api),
              storeId: 'S1',
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Riwayat pergerakan stok'), findsNothing);
      expect(find.text('Riwayat batch ini'), findsNothing);
      s.denied = true;
      await tester.pumpWidget(
        MaterialApp(
          home: StockLedgerHistoryPage(repository: s.repository, store: 'S1'),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.textContaining('Tidak berwenang'), findsOneWidget);
      expect(s.methods.toSet(), {'GET'});
    },
  );
}
