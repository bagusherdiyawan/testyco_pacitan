import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/inventory/application/batch_expiry_controller.dart';
import 'package:testyco_pos/features/inventory/data/batch_expiry_repository.dart';
import 'package:testyco_pos/features/inventory/data/inventory_repository.dart';
import 'package:testyco_pos/features/inventory/domain/batch_expiry.dart';
import 'package:testyco_pos/features/inventory/presentation/batch_expiry_page.dart';
import 'package:testyco_pos/features/inventory/presentation/inventory_page.dart';

class ExpiryServer {
  int status = 0;
  bool empty = false,
      inactive = false,
      foreignStore = false,
      foreignRow = false,
      badMetadata = false;
  final requests = <http.Request>[];
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example.test/api/v1/'),
    client: MockClient(handle),
  );
  late final repository = BatchExpiryRepository(api);
  Future<http.Response> handle(http.Request r) async {
    requests.add(r);
    if (r.method != 'GET' ||
        r.url.host != 'api.example.test' ||
        !r.url.path.endsWith('/stores/S1/inventory/batches')) {
      throw StateError('Unexpected read ${r.method} ${r.url}');
    }
    if (status > 0) {
      return http.Response(jsonEncode({'message': 'Read denied'}), status);
    }
    final page = int.parse(r.url.queryParameters['page'] ?? '1');
    return http.Response(
      jsonEncode({
        'data': empty
            ? []
            : [
                {
                  'id': 'B$page',
                  'store_id': foreignRow ? 'OTHER' : 'S1',
                  'sku': 'OLD8',
                  'name': 'Nama historis <b>batch</b>',
                  'condition': 'frozen',
                  'batch_code': page == 1 ? 'LOT-QUARANTINE' : 'LOT-READY',
                  'expires_on': page == 1 ? '2028-02-29' : '2030-01-01',
                  'pack_size': 8,
                  'usable_pieces': 0,
                  'opened_pieces': 0,
                  'ready_pieces': page == 1 ? 0 : 5,
                  'quarantined_pieces': page == 1 ? 16 : 0,
                  'waste_pieces': 3,
                  'stock_revision': 7,
                },
              ],
        'store': {
          'id': foreignStore ? 'OTHER' : 'S1',
          'name': 'Toko A',
          'active': !inactive,
        },
        'observed_at': '2026-11-02T17:00:00Z',
        'current_page': badMetadata ? 99 : page,
        'last_page': 2,
        'per_page': 100,
        'total': empty ? 0 : 2,
        'next_page_url': 'https://untrusted.test/steal?token=secret',
      }),
      200,
    );
  }
}

void main() {
  test('default filter has no dates, no query, no warning horizon', () {
    const f = BatchExpiryFilter();
    expect(f.params, {'with_balance': '0'});
    final copy = f.params;
    copy['expires_to'] = '2030-01-01';
    expect(f.to, '');
  });
  test(
    'independent inclusive date bounds and multi-year ranges stay calendar dates',
    () {
      expect(BatchExpiryFilter.form('2028-02-29', '').params, {
        'expires_from': '2028-02-29',
        'with_balance': '0',
      });
      expect(BatchExpiryFilter.form('', '2030-01-01').params, {
        'expires_to': '2030-01-01',
        'with_balance': '0',
      });
      final f = BatchExpiryFilter.form(
        ' 2020-01-01 ',
        ' 2030-01-01 ',
        query: ' LOT %_ ',
        withBalance: true,
      );
      expect(f.params, {
        'expires_from': '2020-01-01',
        'expires_to': '2030-01-01',
        'q': 'LOT %_',
        'with_balance': '1',
      });
    },
  );
  test(
    'invalid calendar, reversed range and overlong search rejected without normalization',
    () {
      for (final raw in [
        '2026-02-29',
        '2028-02-30',
        '2030-13-01',
        '2030-00-01',
        '0000-01-01',
        '2030-01-00',
        '2030-1-01',
        '2030-01-01T00:00:00Z',
      ]) {
        expect(
          () => BatchExpiryFilter.form(raw, ''),
          throwsA(isA<ApiException>()),
        );
        expect(
          () => BatchExpiryFilter.form('', raw),
          throwsA(isA<ApiException>()),
        );
      }
      expect(
        () => BatchExpiryFilter.form('2030-01-02', '2030-01-01'),
        throwsA(isA<ApiException>()),
      );
      expect(
        () => BatchExpiryFilter.form('', '', query: 'a' * 101),
        throwsA(isA<ApiException>()),
      );
      expect(
        BatchExpiryFilter.form('2030-01-01', '2030-01-01').from,
        '2030-01-01',
      );
    },
  );
  test(
    'scoped GET preserves exact query, server ordering and immutable historical balances',
    () async {
      final s = ExpiryServer();
      addTearDown(s.api.close);
      final f = BatchExpiryFilter.form(
        '2020-01-01',
        '2030-01-01',
        query: 'LOT / %_ &',
        withBalance: true,
      );
      final page = await s.repository.load('S1', f);
      expect(s.requests.single.url.queryParameters, {...f.params, 'page': '1'});
      expect(s.requests.single.body, isEmpty);
      expect(page.rows.single.quarantined, 16);
      expect(page.rows.single.available, 0);
      expect(page.rows.single.packSize, 8);
      expect(page.rows.single.revision, 7);
      expect(page.rows.single.expiry, '2028-02-29');
      expect(page.rows.single.name, 'Nama historis <b>batch</b>');
      expect(() => page.rows.clear(), throwsUnsupportedError);
      expect(page.observedAt, '2026-11-02T17:00:00Z');
    },
  );
  test(
    'pagination and refresh use applied filters; new filter resets to page1',
    () async {
      final s = ExpiryServer();
      addTearDown(s.api.close);
      final m = BatchExpiryController(s.repository, 'S1');
      addTearDown(m.dispose);
      await m.load(
        next: BatchExpiryFilter.form(
          '',
          '2030-01-01',
          query: 'LOT',
          withBalance: true,
        ),
      );
      await m.load(nextPage: 2);
      await m.load();
      expect(s.requests.map((r) => r.url.queryParameters['page']), [
        '1',
        '2',
        '2',
      ]);
      expect(
        s.requests.every(
          (r) =>
              r.url.queryParameters['q'] == 'LOT' &&
              r.url.queryParameters['expires_to'] == '2030-01-01',
        ),
        isTrue,
      );
      expect(s.requests.every((r) => r.url.host == 'api.example.test'), isTrue);
      await m.load(next: const BatchExpiryFilter());
      expect(s.requests.last.url.queryParameters, {
        'with_balance': '0',
        'page': '1',
      });
      expect(m.result!.rows.single.code, 'LOT-QUARANTINE');
    },
  );
  test(
    'foreign scope and pagination mismatch clear stale data and are never empty success',
    () async {
      final s = ExpiryServer();
      addTearDown(s.api.close);
      final m = BatchExpiryController(s.repository, 'S1');
      addTearDown(m.dispose);
      await m.load();
      expect(m.result, isNotNull);
      s.foreignStore = true;
      await m.load();
      expect(m.result, isNull);
      expect(m.error, isNotNull);
      s.foreignStore = false;
      s.foreignRow = true;
      await m.load();
      expect(m.result, isNull);
      s.foreignRow = false;
      s.badMetadata = true;
      await m.load();
      expect(m.result, isNull);
      expect(m.error, isNotNull);
      await expectLater(
        s.repository.load('S1', const BatchExpiryFilter(), page: 0),
        throwsA(isA<ApiException>()),
      );
    },
  );
  test(
    '403/503 reads are errors, while authorized empty and inactive reads remain valid',
    () async {
      final s = ExpiryServer();
      addTearDown(s.api.close);
      final m = BatchExpiryController(s.repository, 'S1');
      addTearDown(m.dispose);
      for (final code in [403, 503]) {
        s.status = code;
        await m.load();
        expect(m.result, isNull);
        expect(m.error, isNotNull);
      }
      s.status = 0;
      s.empty = true;
      s.inactive = true;
      await m.load();
      expect(m.error, isNull);
      expect(m.result!.rows, isEmpty);
      expect(m.result!.active, isFalse);
    },
  );
  test(
    '401 uses existing session callback without returning cached batch data',
    () async {
      final s = ExpiryServer();
      addTearDown(s.api.close);
      var ended = false;
      s.api.onUnauthorized = () => ended = true;
      final m = BatchExpiryController(s.repository, 'S1');
      addTearDown(m.dispose);
      await m.load();
      s.status = 401;
      await m.load();
      expect(ended, isTrue);
      expect(m.result, isNull);
      expect(m.error, isNotNull);
    },
  );
  for (final setting in [(390.0, 1.5), (1200.0, 1.0)]) {
    testWidgets(
      'assigned cashier entry and date/search/balance pagination at ${setting.$1}',
      (tester) async {
        tester.view.physicalSize = Size(setting.$1, 1000);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final s = ExpiryServer();
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
              ),
            ),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text('Pengolahan dan opname'), findsNothing);
        expect(find.text('Riwayat pergerakan stok'), findsNothing);
        await tester.tap(find.text('Telusuri kedaluwarsa batch'));
        await tester.pumpAndSettle();
        expect(s.requests.last.url.queryParameters, {
          'with_balance': '0',
          'page': '1',
        });
        await tester.tap(find.text('Filter kedaluwarsa'));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.widgetWithText(TextField, 'Sampai tanggal kemasan (YYYY-MM-DD)'),
          '2030-01-01',
        );
        tester.view.viewInsets = const FakeViewPadding(bottom: 280);
        addTearDown(tester.view.resetViewInsets);
        await tester.pumpAndSettle();
        final scroll = find
            .descendant(
              of: find.byKey(const Key('batch-expiry-scroll')),
              matching: find.byType(Scrollable),
            )
            .first;
        await tester.scrollUntilVisible(
          find
              .widgetWithText(TextField, 'Cari SKU / nama / kode batch')
              .hitTestable(),
          200,
          scrollable: scroll,
        );
        await tester.enterText(
          find.widgetWithText(TextField, 'Cari SKU / nama / kode batch'),
          'LOT',
        );
        tester.view.resetViewInsets();
        await tester.pumpAndSettle();
        await tester.scrollUntilVisible(
          find.byType(CheckboxListTile).hitTestable(),
          150,
          scrollable: scroll,
        );
        await tester.tap(find.byType(CheckboxListTile));
        await tester.scrollUntilVisible(
          find.text('Terapkan filter batch').hitTestable(),
          150,
          scrollable: scroll,
        );
        await tester.tap(find.text('Terapkan filter batch'));
        await tester.pumpAndSettle();
        expect(s.requests.last.url.queryParameters, {
          'expires_to': '2030-01-01',
          'q': 'LOT',
          'with_balance': '1',
          'page': '1',
        });
        // Draft edits never replace applied pagination filters.
        await tester.scrollUntilVisible(
          find
              .widgetWithText(TextField, 'Cari SKU / nama / kode batch')
              .hitTestable(),
          -150,
          scrollable: scroll,
        );
        await tester.enterText(
          find.widgetWithText(TextField, 'Cari SKU / nama / kode batch'),
          'Unapplied',
        );
        await tester.scrollUntilVisible(
          find.text('Karantina: 16 biji').hitTestable(),
          200,
          scrollable: scroll,
        );
        expect(
          find.textContaining('Isi kemasan historis: 8 biji'),
          findsOneWidget,
        );
        expect(find.textContaining('2028-02-29'), findsWidgets);
        expect(find.text('Kondisi: frozen'), findsOneWidget);
        expect(
          find.textContaining('Diamati 2026-11-03 00:00 WIB'),
          findsOneWidget,
        );
        await tester.scrollUntilVisible(
          find.text('Berikutnya').hitTestable(),
          200,
          scrollable: scroll,
        );
        await tester.tap(find.text('Berikutnya'));
        await tester.pumpAndSettle();
        expect(s.requests.last.url.queryParameters['page'], '2');
        expect(s.requests.last.url.queryParameters['q'], 'LOT');
        expect(
          s.requests.every(
            (r) => r.method == 'GET' && r.url.host == 'api.example.test',
          ),
          isTrue,
        );
        expect(tester.takeException(), isNull);
      },
    );
  }
  testWidgets(
    'invalid form does not send request or replace applied results; denied read is not empty',
    (tester) async {
      final s = ExpiryServer();
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: BatchExpirySearchPage(repository: s.repository, store: 'S1'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Filter kedaluwarsa'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.widgetWithText(TextField, 'Mulai tanggal kemasan (YYYY-MM-DD)'),
        '2026-02-29',
      );
      final scroll = find
          .descendant(
            of: find.byKey(const Key('batch-expiry-scroll')),
            matching: find.byType(Scrollable),
          )
          .first;
      await tester.scrollUntilVisible(
        find.text('Terapkan filter batch').hitTestable(),
        200,
        scrollable: scroll,
      );
      final count = s.requests.length;
      await tester.tap(find.text('Terapkan filter batch'));
      await tester.pumpAndSettle();
      expect(s.requests.length, count);
      await tester.scrollUntilVisible(
        find.textContaining('Filter belum diterapkan:').hitTestable(),
        150,
        scrollable: scroll,
      );
      expect(find.textContaining('Tanpa awal — Tanpa akhir'), findsOneWidget);
      s.status = 403;
      await tester.tap(find.byTooltip('Muat ulang hasil batch'));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Read denied').hitTestable(),
        150,
        scrollable: scroll,
      );
      expect(find.text('Tidak ada batch pada filter ini.'), findsNothing);
      expect(find.textContaining('Karantina: 16'), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );
}
