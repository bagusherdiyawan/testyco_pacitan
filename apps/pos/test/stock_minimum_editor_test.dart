import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/inventory/application/stock_minimum_controller.dart';
import 'package:testyco_pos/features/inventory/data/inventory_repository.dart';
import 'package:testyco_pos/features/inventory/presentation/stock_minimum_page.dart';
import 'package:testyco_pos/features/inventory/presentation/stock_minimum_editor.dart';

class MinimumServer {
  int version = 0, writes = 0;
  int? minimum;
  bool canManage = true,
      loseResponse = false,
      failRead = false,
      deny = false,
      omitVersion = false;
  String store = 'S1', product = 'P1';
  final requests = <Map<String, dynamic>>[];
  final queries = <Map<String, String>>[];
  final memo = <String, Map<String, dynamic>>{};
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example/api/v1/'),
    client: MockClient(handle),
  );
  late final repository = InventoryRepository(api);
  Map<String, dynamic> get row => {
    'product_id': product,
    if (!omitVersion) 'version': version,
    'sku': 'AYAM',
    'name': 'Dimsum',
    'minimum_pieces': minimum,
    'available_pieces': 12,
    'usable_pieces': 10,
    'opened_pieces': 2,
    'ready_pieces': 0,
    'is_low': minimum != null && minimum! > 12,
    'shortfall_pieces': minimum != null && minimum! > 12 ? minimum! - 12 : 0,
  };
  http.Response reply(Map<String, dynamic> data, [int status = 200]) =>
      http.Response(
        jsonEncode(data),
        status,
        headers: {'content-type': 'application/json'},
      );
  Future<http.Response> handle(http.Request r) async {
    if (r.method == 'GET' && r.url.path.endsWith('/minimums')) {
      queries.add(r.url.queryParameters);
      final page = int.parse(r.url.queryParameters['page'] ?? '1');
      final visible =
          r.url.queryParameters['low_only'] == '0' || row['is_low'] == true;
      return reply({
        'data': visible ? [row] : [],
        'store': {'id': store},
        'can_manage': canManage,
        'observed_at': '2026-10-29T03:00:00Z',
        'current_page': page,
        'last_page': 2,
        'next_page_url': 'https://untrusted.example/steal',
      });
    }
    if (r.method == 'GET' && r.url.path.endsWith('/minimums/P1')) {
      if (failRead) return reply({'message': 'Unavailable'}, 503);
      return reply({
        'data': row,
        'store': {'id': store},
        'can_manage': canManage,
        'observed_at': '2026-10-29T03:00:00Z',
      });
    }
    if (r.method == 'POST' && r.url.path.endsWith('/minimums/P1')) {
      final v = jsonDecode(r.body) as Map<String, dynamic>;
      requests.add(v);
      if (deny) return reply({'message': 'Denied'}, 403);
      final operation = v['operation_id'] as String;
      if (memo.containsKey(operation)) return reply({'data': memo[operation]});
      if (v['version'] != version) {
        return reply({'message': 'Ambang berubah'}, 409);
      }
      writes++;
      version++;
      minimum = v['minimum_pieces'] as int?;
      memo[operation] = {
        'product_id': 'P1',
        'version': version,
        'minimum_pieces': minimum,
      };
      if (loseResponse) {
        loseResponse = false;
        throw http.ClientException('Response lost after commit');
      }
      return reply({'data': memo[operation]});
    }
    throw StateError('Unexpected ${r.method} ${r.url}');
  }
}

void main() {
  StockMinimumController modelFor(MinimumServer s) {
    addTearDown(s.api.close);
    final m = StockMinimumController(s.repository, 'S1', 'P1');
    addTearDown(m.dispose);
    return m;
  }

  test(
    'Unconfigured has no default; preview is read only; zero remains active and explicit disable sends null',
    () async {
      final s = MinimumServer();
      final m = modelFor(s);
      await m.load();
      expect(m.detail!.row.minimum, isNull);
      expect(m.detail!.row.version, 0);
      final zero = m.prepare(true, '0', 'Pantau nol');
      expect(s.requests, isEmpty);
      await m.confirm(zero);
      expect(s.minimum, 0);
      expect(m.detail!.row.version, 1);
      await m.confirm(m.prepare(false, '', 'Tidak dipantau'));
      expect(s.minimum, isNull);
      expect(m.detail!.row.version, 2);
      expect(s.requests.last.containsKey('minimum_pieces'), true);
      expect(s.requests.last['minimum_pieces'], isNull);
    },
  );
  test(
    'Stale version requires reload/new review, never rebases the original request',
    () async {
      final s = MinimumServer();
      final m = modelFor(s);
      await m.load();
      final old = m.prepare(true, '20', 'Stok cadangan');
      s.version = 1;
      s.minimum = 50;
      await m.confirm(old);
      expect(m.pending, isNull);
      expect(m.fresh, false);
      expect(s.writes, 0);
      expect(
        () => m.prepare(true, '20', 'Stok cadangan'),
        throwsA(isA<ApiException>()),
      );
      await m.load();
      expect(m.detail!.row.minimum, 50);
      await m.confirm(old);
      expect(s.requests.length, 1);
      final next = m.prepare(true, '30', 'Ditinjau kembali');
      expect(next.body['version'], 1);
      await m.confirm(next);
      expect(s.minimum, 30);
      expect(s.version, 2);
    },
  );
  test(
    'Unknown retry keeps exact UUID/path/body and fetches current config after a later change',
    () async {
      final s = MinimumServer()..loseResponse = true;
      final m = modelFor(s);
      await m.load();
      await m.confirm(m.prepare(true, '20', 'Cadangan'));
      final original = jsonDecode(jsonEncode(s.requests.single));
      m.pending!.body['minimum_pieces'] = 999;
      final epoch = m.formEpoch;
      s.minimum = 90;
      s.version = 2;
      await m.load();
      expect(m.formEpoch, epoch);
      expect(m.editable, false);
      await m.retry();
      expect(s.requests.last, original);
      expect(s.writes, 1);
      expect(m.detail!.row.minimum, 90);
      expect(m.detail!.row.version, 2);
      expect(m.pending, isNull);
      expect(m.saved, true);
    },
  );
  test(
    'A denial after an unknown response cannot discard its original operation',
    () async {
      final s = MinimumServer()..loseResponse = true;
      final m = modelFor(s);
      await m.load();
      await m.confirm(m.prepare(true, '20', 'Cadangan'));
      s.deny = true;
      await m.retry();
      expect(m.pending, isNotNull);
      expect(m.editable, false);
      s.deny = false;
      await m.retry();
      expect(s.writes, 1);
      expect(s.requests.first, s.requests.last);
    },
  );
  test(
    'ACK followed by refresh failure is saved, read only, and never posted twice',
    () async {
      final s = MinimumServer();
      final m = modelFor(s);
      await m.load();
      final draft = m.prepare(true, '20', 'Cadangan');
      s.failRead = true;
      await m.confirm(draft);
      expect(m.saved, true);
      expect(m.pending, isNull);
      expect(m.fresh, false);
      await m.retry();
      await m.confirm(draft);
      expect(s.writes, 1);
      s.failRead = false;
      await m.load();
      expect(m.editable, true);
      expect(m.detail!.row.minimum, 20);
    },
  );
  test(
    'Denied/missing metadata, wrong store/SKU and failed reads never allow edits',
    () async {
      final s = MinimumServer();
      final m = modelFor(s);
      s.canManage = false;
      await m.load();
      expect(m.editable, false);
      s.canManage = true;
      s.omitVersion = true;
      await m.load();
      expect(m.editable, false);
      s.omitVersion = false;
      s.store = 'OTHER';
      await m.load();
      expect(m.fresh, false);
      s.store = 'S1';
      s.product = 'OTHER';
      await m.load();
      expect(m.editable, false);
      s.product = 'P1';
      s.failRead = true;
      await m.load();
      expect(m.editable, false);
      expect(s.requests, isEmpty);
    },
  );
  test(
    'Empty/fractional/negative/overflow threshold and blank reason are rejected before POST',
    () async {
      final s = MinimumServer();
      final m = modelFor(s);
      await m.load();
      for (final value in [
        '',
        '-1',
        '1.5',
        '1000000001',
        '0x10',
        '1e3',
        'NaN',
      ]) {
        expect(
          () => m.prepare(true, value, 'Cadangan'),
          throwsA(isA<ApiException>()),
        );
      }
      expect(() => m.prepare(false, '', ' '), throwsA(isA<ApiException>()));
      expect(s.requests, isEmpty);
    },
  );
  for (final size in [(390.0, 1.5), (1200.0, 1.0)]) {
    testWidgets(
      'Native minimum enable/disable review, search and pagination at ${size.$1}',
      (tester) async {
        tester.view.physicalSize = Size(size.$1, 1600);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final s = MinimumServer();
        addTearDown(s.api.close);
        await tester.pumpWidget(
          MaterialApp(
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(
                context,
              ).copyWith(textScaler: TextScaler.linear(size.$2)),
              child: child!,
            ),
            home: StockMinimumPage(repository: s.repository, storeId: 'S1'),
          ),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.byType(Switch));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.widgetWithText(TextField, 'Cari SKU atau nama'),
          'AYAM',
        );
        await tester.testTextInput.receiveAction(TextInputAction.done);
        await tester.pumpAndSettle();
        expect(s.queries.last['q'], 'AYAM');
        await tester.tap(find.text('Berikutnya'));
        await tester.pumpAndSettle();
        expect(s.queries.last['page'], '2');
        expect(s.queries.last['low_only'], '0');
        expect(s.queries.last['q'], 'AYAM');
        await tester.ensureVisible(find.text('Atur ambang'));
        await tester.tap(find.text('Atur ambang'));
        await tester.pumpAndSettle();
        expect(
          tester
              .widget<TextField>(
                find.widgetWithText(TextField, 'Ambang satuan dasar (biji)'),
              )
              .controller!
              .text,
          '',
        );
        await tester.tap(find.byType(Switch));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.widgetWithText(TextField, 'Ambang satuan dasar (biji)'),
          '0',
        );
        await tester.enterText(
          find.widgetWithText(TextField, 'Alasan perubahan'),
          'Nilai nol eksplisit',
        );
        await tester.ensureVisible(find.text('Tinjau perubahan ambang'));
        await tester.tap(find.text('Tinjau perubahan ambang'));
        await tester.pumpAndSettle();
        expect(find.text('Sesudah: 0 biji'), findsOneWidget);
        expect(s.requests, isEmpty);
        await tester.tap(find.text('Konfirmasi ambang'));
        await tester.pumpAndSettle();
        expect(s.minimum, 0);
        expect(s.version, 1);
        await tester.tap(find.byType(Switch));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.widgetWithText(TextField, 'Alasan perubahan'),
          'Berhenti pantau',
        );
        await tester.tap(find.text('Tinjau perubahan ambang'));
        await tester.pumpAndSettle();
        expect(find.text('Sesudah: Tidak dipantau'), findsOneWidget);
        await tester.tap(find.text('Konfirmasi ambang'));
        await tester.pumpAndSettle();
        expect(s.minimum, isNull);
        expect(s.version, 2);
        expect(tester.takeException(), isNull);
      },
    );
  }
  testWidgets(
    'Minimum list remains scrollable with mobile keyboard and large text',
    (tester) async {
      tester.view.physicalSize = const Size(390, 800);
      tester.view.devicePixelRatio = 1;
      tester.view.viewInsets = const FakeViewPadding(bottom: 320);
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      addTearDown(tester.view.resetViewInsets);
      final s = MinimumServer();
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          builder: (context, child) => MediaQuery(
            data: MediaQuery.of(
              context,
            ).copyWith(textScaler: TextScaler.linear(1.5)),
            child: child!,
          ),
          home: StockMinimumPage(repository: s.repository, storeId: 'S1'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.widgetWithText(TextField, 'Cari SKU atau nama'),
        150,
        scrollable: find
            .descendant(
              of: find.byKey(const Key('minimum-list-scroll')),
              matching: find.byType(Scrollable),
            )
            .first,
      );
      await tester.enterText(
        find.widgetWithText(TextField, 'Cari SKU atau nama'),
        'AYAM',
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
    },
  );
  testWidgets(
    'Read-only list has no editor entry and deep-link form remains disabled',
    (tester) async {
      final s = MinimumServer()..canManage = false;
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: StockMinimumPage(repository: s.repository, storeId: 'S1'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byType(Switch));
      await tester.pumpAndSettle();
      expect(find.text('Atur ambang'), findsNothing);
      await tester.pumpWidget(
        MaterialApp(
          home: StockMinimumEditor(
            repository: s.repository,
            storeId: 'S1',
            productId: 'P1',
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Tinjau perubahan ambang'), findsNothing);
      expect(s.requests, isEmpty);
    },
  );
}
