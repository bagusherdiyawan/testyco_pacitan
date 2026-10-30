import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/domain/wib_date_range.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/sales/application/receipt_history_controller.dart';
import 'package:testyco_pos/features/sales/data/pos_repository.dart';
import 'package:testyco_pos/features/sales/presentation/pos_page.dart';
import 'package:testyco_pos/features/sales/presentation/receipt_history_page.dart';
import 'cash_pos_test.dart' show FakeCashServer;

class HistoryServer extends FakeCashServer {
  bool denied = false,
      unavailable = false,
      empty = false,
      loseOpen = false,
      inactive = false;
  String store = 'STORE', receiptStore = 'STORE', scope = 'own';
  final queries = <Map<String, String>>[], reads = <String>[];
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example/api/v1/'),
    client: MockClient(handle),
  );
  late final repo = PosRepository(api);
  @override
  Future<http.Response> handle(http.Request r) async {
    if (r.url.path.endsWith('/sales') || r.url.path.endsWith('/sales/SALE')) {
      reads.add(r.method);
      expect(r.method, 'GET');
      expect(r.url.host, 'api.example');
      if (denied) return response({'message': 'Tidak berwenang'}, 403);
      if (unavailable) return response({}, 503);
      if (r.url.path.endsWith('/sales/SALE')) {
        return response({
          'data': {...receipt, 'store_id': receiptStore},
        });
      }
      queries.add(r.url.queryParameters);
      return response({
        'data': empty
            ? []
            : [
                {...receipt, 'store_id': store}..remove('lines'),
              ],
        'store': {'id': store, 'name': 'Pacitan'},
        'scope': scope,
        'current_page': int.parse(r.url.queryParameters['page'] ?? '1'),
        'last_page': 2,
        'next_page_url': 'https://untrusted.example/collect',
      });
    }
    if (loseOpen && r.url.path.endsWith('/shifts') && r.method == 'POST') {
      throw http.ClientException('Unknown opening outcome');
    }
    if (inactive && r.url.path.endsWith('/pos/context')) {
      final reply = await super.handle(r);
      final body = jsonDecode(reply.body) as Map<String, dynamic>;
      body['data']['store']['active'] = false;
      return response(body);
    }
    if (inactive &&
        (r.url.path.endsWith('/catalog') ||
            r.url.path.endsWith('/inventory/batches'))) {
      throw StateError('Inactive store must not request operating catalogue');
    }
    return super.handle(r);
  }
}

void main() {
  test(
    'Calendar dates are validated and inclusive WIB becomes half-open ISO',
    () {
      final range = WibDateRange.form('2026-10-30', '2026-10-31');
      expect(range.params, {
        'from': '2026-10-30T00:00:00+07:00',
        'to': '2026-11-01T00:00:00+07:00',
      });
      for (final pair in [
        ('2026-02-30', '2026-03-01'),
        ('2026-10-31', '2026-10-30'),
        ('2026-10-01', '2026-11-01'),
      ]) {
        expect(
          () => WibDateRange.form(pair.$1, pair.$2),
          throwsFormatException,
        );
      }
      expect(
        () => WibDateRange.form('2026-10-01', '2026-10-31'),
        returnsNormally,
      );
    },
  );
  test(
    'Scoped local pagination ignores external next URL; receipt read never pays',
    () async {
      final s = HistoryServer();
      addTearDown(s.api.close);
      final m = ReceiptHistoryController(s.repo, 'STORE');
      addTearDown(m.dispose);
      final range = WibDateRange.form('2026-10-30', '2026-10-31');
      await m.load(next: range, search: 'POS-123');
      await m.load(nextPage: 2);
      expect(s.queries.last, {...range.params, 'q': 'POS-123', 'page': '2'});
      await m.open('SALE');
      expect(m.receipt!['change_amount'], 8000);
      expect(m.receipt!['lines'][0]['name'], 'Ayam');
      await m.load();
      expect(m.result!.page, 2);
      expect(m.query, 'POS-123');
      expect(s.reads.toSet(), {'GET'});
      expect(s.payments, 0);
    },
  );
  test(
    'Failures clear stale history/receipt; retry retains scope and rejects mismatched store',
    () async {
      final s = HistoryServer();
      addTearDown(s.api.close);
      final m = ReceiptHistoryController(s.repo, 'STORE');
      addTearDown(m.dispose);
      await m.load();
      expect(m.result, isNotNull);
      s.unavailable = true;
      await m.load();
      expect(m.result, isNull);
      expect(m.error, isNotNull);
      s.unavailable = false;
      s.store = 'OTHER';
      await m.retry();
      expect(m.result, isNull);
      expect(m.error, contains('toko'));
      s.store = 'STORE';
      await m.retry();
      expect(m.result, isNotNull);
      await m.open('SALE');
      expect(m.receipt, isNotNull);
      s.receiptStore = 'OTHER';
      await m.retry();
      expect(m.receipt, isNull);
      expect(m.error, contains('toko'));
      s.receiptStore = 'STORE';
      s.denied = true;
      await m.retry();
      expect(m.receipt, isNull);
      expect(m.error, contains('Tidak berwenang'));
      s.denied = false;
      await m.retry();
      expect(m.receipt!['id'], 'SALE');
    },
  );
  for (final setting in [(390.0, 1.5), (1200.0, 1.0)]) {
    testWidgets(
      'History before shift, filters, paging and receipt at ${setting.$1}',
      (tester) async {
        tester.view.physicalSize = Size(setting.$1, 1800);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final s = HistoryServer()..scope = setting.$1 > 900 ? 'store' : 'own';
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
              body: PosPage(repository: s.repo, storeId: 'STORE'),
            ),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text('Buka shift'), findsOneWidget);
        await tester.tap(find.text('Riwayat struk'));
        await tester.pumpAndSettle();
        expect(
          find.textContaining(
            setting.$1 > 900 ? 'Seluruh kasir toko' : 'Struk saya',
          ),
          findsOneWidget,
        );
        await tester.tap(find.text('Filter struk'));
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
          find.widgetWithText(TextField, 'Cari nomor struk'),
          'POS-123',
        );
        await tester.ensureVisible(find.text('Terapkan filter'));
        await tester.tap(find.text('Terapkan filter'));
        await tester.pumpAndSettle();
        expect(s.queries.last['from'], '2026-10-30T00:00:00+07:00');
        await tester.enterText(
          find.widgetWithText(TextField, 'Cari nomor struk'),
          'UNAPPLIED',
        );
        await tester.ensureVisible(find.text('Berikutnya'));
        await tester.tap(find.text('Berikutnya'));
        await tester.pumpAndSettle();
        expect(s.queries.last['page'], '2');
        expect(s.queries.last['q'], 'POS-123');
        await tester.ensureVisible(find.text('Buka struk'));
        await tester.tap(find.text('Buka struk'));
        await tester.pumpAndSettle();
        expect(find.text('Kembalian Rp 8000'), findsOneWidget);
        await tester.tap(find.text('Kembali ke daftar struk'));
        await tester.pumpAndSettle();
        expect(s.queries.last['page'], '2');
        expect(s.payments, 0);
        expect(s.reads.toSet(), {'GET'});
        expect(tester.takeException(), isNull);
      },
    );
  }
  testWidgets(
    'Denied request is not an empty history; explicit retry recovers',
    (tester) async {
      final s = HistoryServer()..denied = true;
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: ReceiptHistoryPage(repository: s.repo, store: 'STORE'),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Tidak ada struk pada filter ini.'), findsNothing);
      expect(find.textContaining('Tidak berwenang'), findsOneWidget);
      s.denied = false;
      s.empty = true;
      await tester.tap(find.text('Coba kembali'));
      await tester.pumpAndSettle();
      expect(find.text('Tidak ada struk pada filter ini.'), findsOneWidget);
    },
  );
  testWidgets(
    'Unknown shift opening keeps history disabled until original operation reconciles',
    (tester) async {
      final s = HistoryServer()..loseOpen = true;
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: PosPage(repository: s.repo, storeId: 'STORE'),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.enterText(
        find.widgetWithText(TextField, 'Nama terminal'),
        'Kasir',
      );
      await tester.enterText(
        find.widgetWithText(TextField, 'Kas awal (Rp)'),
        '0',
      );
      await tester.tap(find.text('Buka shift'));
      await tester.pumpAndSettle();
      final button = tester.widget<TextButton>(
        find.ancestor(
          of: find.text('Riwayat struk'),
          matching: find.byWidgetPredicate((w) => w is TextButton),
        ),
      );
      expect(button.onPressed, isNull);
      expect(find.text('Ulangi buka shift yang sama'), findsOneWidget);
      expect(s.queries, isEmpty);
      s.loseOpen = false;
      await tester.tap(find.text('Ulangi buka shift yang sama'));
      await tester.pumpAndSettle();
      expect(
        tester
            .widget<TextButton>(
              find.ancestor(
                of: find.text('Riwayat struk'),
                matching: find.byWidgetPredicate((w) => w is TextButton),
              ),
            )
            .onPressed,
        isNotNull,
      );
    },
  );
  testWidgets(
    'Inactive store opens authorized history without operating catalogue or shift',
    (tester) async {
      final s = HistoryServer()..inactive = true;
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: PosPage(repository: s.repo, storeId: 'STORE'),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(
        find.text('Toko nonaktif. Transaksi baru ditolak.'),
        findsOneWidget,
      );
      expect(find.text('Buka shift'), findsNothing);
      await tester.tap(find.text('Riwayat struk'));
      await tester.pumpAndSettle();
      expect(find.text('POS-123'), findsOneWidget);
      expect(s.payments, 0);
      expect(tester.takeException(), isNull);
    },
  );
}
