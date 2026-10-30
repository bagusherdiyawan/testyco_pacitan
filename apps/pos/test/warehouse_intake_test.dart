import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/inventory/application/warehouse_intake_controller.dart';
import 'package:testyco_pos/features/inventory/data/warehouse_intake_repository.dart';
import 'package:testyco_pos/features/inventory/data/inventory_repository.dart';
import 'package:testyco_pos/features/inventory/presentation/inventory_page.dart';
import 'package:testyco_pos/features/inventory/presentation/warehouse_intake_page.dart';

class IntakeServer {
  int pack = 8, writes = 0, pieces = 0, batchReads = 0;
  bool allowed = true,
      omitCapability = false,
      lose = false,
      deny = false,
      malformed = false;
  String store = 'S1';
  final cataloguePages = <int>[],
      posts = <Map<String, dynamic>>[],
      previews = <Map<String, dynamic>>[];
  final memo = <String, Map<String, dynamic>>{};
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example/api/v1/'),
    client: MockClient(handle),
  );
  late final repository = WarehouseIntakeRepository(api);
  String get hash => pack.toString().padLeft(64, '0');
  Map<String, dynamic> batch(Map<String, dynamic> v) => {
    'id': 'B1',
    'store_id': 'S1',
    'product_id': 'P1',
    'sku': 'AYAM8',
    'name': 'Ayam',
    'condition': 'frozen',
    'batch_code': v['batch_code'],
    'expires_on': v['expires_on'],
    'pack_size': pack,
    'usable_pieces': pieces,
    'quarantined_pieces': 0,
    'stock_revision': writes,
  };
  http.Response reply(Map<String, dynamic> v, [int status = 200]) =>
      http.Response(
        jsonEncode(v),
        status,
        headers: {'content-type': 'application/json'},
      );
  Future<http.Response> handle(http.Request r) async {
    if (r.method == 'GET' && r.url.path.endsWith('/intake-products')) {
      final page = int.parse(r.url.queryParameters['page'] ?? '1');
      cataloguePages.add(page);
      return reply({
        'data': page == 1
            ? [
                {
                  'id': 'P1',
                  'sku': 'AYAM8',
                  'name': 'Ayam',
                  'condition': 'frozen',
                  'pack_size': pack,
                },
              ]
            : [],
        'store': {'id': store, 'name': 'Gudang Pusat', 'active': true},
        if (!omitCapability) 'can_intake': allowed,
        'last_page': 2,
        'next_page_url': 'https://untrusted.test/collect',
      });
    }
    if (r.method == 'GET' && r.url.path.endsWith('/batches')) {
      batchReads++;
      return reply({
        'data': pieces == 0 ? [] : [batch(posts.last)],
        'last_page': 1,
      });
    }
    if (r.method == 'POST' && r.url.path.endsWith('/intakes/preview')) {
      final v = jsonDecode(r.body) as Map<String, dynamic>;
      previews.add(v);
      if (deny) return reply({'message': 'Denied'}, 403);
      return reply({
        'data': {
          ...v,
          'store_id': store,
          'store_name': 'Gudang Pusat',
          'sku': 'AYAM8',
          'name': 'Ayam',
          'condition': 'frozen',
          'pack_size': pack,
          'quantity_pieces': (v['quantity_packs'] as int) * pack,
          'review_hash': hash,
          'observed_at': '2026-10-30T03:00:00Z',
        },
      });
    }
    if (r.method == 'POST' && r.url.path.endsWith('/intakes')) {
      final v = jsonDecode(r.body) as Map<String, dynamic>;
      posts.add(v);
      if (deny) return reply({'message': 'Denied'}, 403);
      final operation = v['operation_id'] as String;
      if (memo.containsKey(operation)) {
        return reply({'data': memo[operation]}, 201);
      }
      if (v['review_hash'] != hash) {
        return reply({'message': 'Conversion changed'}, 409);
      }
      writes++;
      pieces += (v['quantity_packs'] as int) * pack;
      memo[operation] = batch(v);
      if (lose) {
        lose = false;
        throw http.ClientException('Lost after commit');
      }
      return reply({'data': malformed ? {} : memo[operation]}, 201);
    }
    throw StateError('Unexpected ${r.method} ${r.url}');
  }
}

void main() {
  WarehouseIntakeController modelFor(IntakeServer s) {
    addTearDown(s.api.close);
    final m = WarehouseIntakeController(s.repository, 'S1');
    addTearDown(m.dispose);
    return m;
  }

  Future<void> preview(WarehouseIntakeController m) =>
      m.preview('P1', 'LOT-A', '2030-01-01', '2', 'Pemeriksaan fisik');
  test(
    'Catalogue pagination keeps scope; server preview supplies pack conversion without adding stock',
    () async {
      final s = IntakeServer();
      final m = modelFor(s);
      await m.load();
      expect(s.cataloguePages, [1, 2]);
      s.pack = 10;
      await preview(m);
      expect(m.review!.snapshot['quantity_pieces'], 20);
      expect(m.review!.snapshot['pack_size'], 10);
      expect(s.writes, 0);
      await m.confirm();
      expect(m.savedId, 'B1');
      expect(s.pieces, 20);
      expect(s.writes, 1);
      expect(s.posts.single['product_id'], 'P1');
      await m.confirm();
      await m.load();
      expect(s.posts.length, 1);
      expect(m.editable, false);
    },
  );
  test(
    'Changed pack after preview rejects write and requires refreshed physical review',
    () async {
      final s = IntakeServer();
      final m = modelFor(s);
      await m.load();
      await preview(m);
      s.pack = 10;
      await m.confirm();
      expect(m.pending, isNull);
      expect(m.review, isNull);
      expect(m.fresh, false);
      expect(s.writes, 0);
      await m.load();
      await preview(m);
      expect(m.review!.snapshot['quantity_pieces'], 20);
      await m.confirm();
      expect(s.writes, 1);
    },
  );
  test(
    'Unknown response keeps exact immutable body and UUID across denial and later master changes',
    () async {
      final s = IntakeServer()..lose = true;
      final m = modelFor(s);
      await m.load();
      await preview(m);
      await m.confirm();
      final original = jsonDecode(jsonEncode(s.posts.single));
      m.pending!.body['quantity_packs'] = 999;
      final reads = s.cataloguePages.length;
      await m.load();
      expect(s.cataloguePages.length, reads);
      expect(m.edit(), false);
      s.deny = true;
      await m.retry();
      expect(m.pending, isNotNull);
      s.deny = false;
      s.pack = 10;
      await m.retry();
      expect(s.posts.last, original);
      expect(s.writes, 1);
      expect(s.pieces, 16);
      expect(m.savedId, 'B1');
      expect(m.review!.snapshot['pack_size'], 8);
    },
  );
  test(
    'Invalid acknowledgement remains uncertain until replay confirms original batch',
    () async {
      final s = IntakeServer()..malformed = true;
      final m = modelFor(s);
      await m.load();
      await preview(m);
      await m.confirm();
      expect(m.pending, isNotNull);
      expect(m.savedId, isNull);
      await m.retry();
      expect(m.savedId, 'B1');
      expect(s.writes, 1);
    },
  );
  test(
    'Missing permission or wrong source cannot load an editable catalogue or create a preview',
    () async {
      final s = IntakeServer()..allowed = false;
      final m = modelFor(s);
      await m.load();
      expect(m.editable, false);
      expect(s.cataloguePages, [1]);
      s.allowed = true;
      s.omitCapability = true;
      await m.load();
      expect(m.editable, false);
      s.omitCapability = false;
      s.store = 'OTHER';
      await m.load();
      expect(m.editable, false);
      await preview(m);
      expect(s.previews, isEmpty);
      expect(s.posts, isEmpty);
    },
  );
  test(
    'Invalid dates quantities batch note and SKU are rejected before preview',
    () async {
      final s = IntakeServer();
      final m = modelFor(s);
      await m.load();
      for (final date in ['2030-02-30', '2030-2-01', 'not-a-date']) {
        await m.preview('P1', 'LOT', date, '2', 'Fisikal');
        expect(m.review, isNull);
      }
      for (final quantity in ['0', '-1', '1.5', '10001', '0x10', '']) {
        await m.preview('P1', 'LOT', '2030-01-01', quantity, 'Fisikal');
        expect(m.review, isNull);
      }
      await m.preview('P1', ' ', '2030-01-01', '2', 'Fisikal');
      await m.preview('P1', 'LOT', '2030-01-01', '2', ' ');
      await m.preview('OTHER', 'LOT', '2030-01-01', '2', 'Fisikal');
      expect(s.previews, isEmpty);
      await m.preview('P1', 'LOT', '2028-02-29', '2', 'Fisikal');
      expect(m.review, isNotNull);
    },
  );
  test(
    'Edit discards unsent quote; changing its returned maps cannot mutate submission',
    () async {
      final s = IntakeServer();
      final m = modelFor(s);
      await m.load();
      await preview(m);
      expect(m.edit(), true);
      expect(m.review, isNull);
      expect(s.posts, isEmpty);
      await preview(m);
      m.review!.body['quantity_packs'] = 9;
      m.review!.snapshot['pack_size'] = 99;
      await m.confirm();
      expect(s.posts.single['quantity_packs'], 2);
      expect(s.pieces, 16);
    },
  );
  for (final setting in [(390.0, 1.5), (1200.0, 1.0)]) {
    testWidgets(
      'Native intake from stock navigation to reviewed addition and fresh batch at ${setting.$1}',
      (tester) async {
        tester.view.physicalSize = Size(setting.$1, 1600);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final s = IntakeServer();
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
                canIntake: true,
              ),
            ),
          ),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Penerimaan awal gudang'));
        await tester.pumpAndSettle();
        await tester.tap(find.byType(DropdownButtonFormField<String>));
        await tester.pumpAndSettle();
        await tester.tap(find.textContaining('AYAM8 · Ayam').last);
        await tester.pumpAndSettle();
        for (final entry in {
          'Kode batch': 'LOT-A',
          'Kedaluwarsa (YYYY-MM-DD)': '2030-01-01',
          'Kemasan diterima baik': '2',
          'Referensi / catatan pemeriksaan': 'Pemeriksaan fisik',
        }.entries) {
          final f = find.widgetWithText(TextField, entry.key);
          await tester.ensureVisible(f);
          await tester.enterText(f, entry.value);
        }
        final scroll = find
            .descendant(
              of: find.byKey(const Key('intake-scroll')),
              matching: find.byType(Scrollable),
            )
            .first;
        await tester.scrollUntilVisible(
          find.text('Tinjau penerimaan di server'),
          200,
          scrollable: scroll,
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Tinjau penerimaan di server'));
        await tester.pumpAndSettle();
        expect(
          find.text('2 kemasan × 8 biji = 16 biji ditambahkan'),
          findsOneWidget,
        );
        expect(s.writes, 0);
        await tester.tap(find.text('Konfirmasi penerimaan'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Simpan penerimaan'));
        await tester.pumpAndSettle();
        expect(s.pieces, 16);
        expect(s.writes, 1);
        expect(find.text('Penerimaan tersimpan · batch B1'), findsOneWidget);
        await tester.tap(find.text('Kembali dan muat stok terkini'));
        await tester.pumpAndSettle();
        expect(s.batchReads, 2);
        expect(find.textContaining('LOT-A'), findsOneWidget);
        expect(tester.takeException(), isNull);
      },
    );
  }
  testWidgets(
    'Unknown intake locks pop and refresh, then reconciles exact original operation',
    (tester) async {
      final s = IntakeServer()..lose = true;
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: WarehouseIntakePage(repository: s.repository, store: 'S1'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.textContaining('AYAM8 · Ayam').last);
      await tester.pumpAndSettle();
      for (final entry in {
        'Kode batch': 'LOT-A',
        'Kedaluwarsa (YYYY-MM-DD)': '2030-01-01',
        'Kemasan diterima baik': '2',
        'Referensi / catatan pemeriksaan': 'Pemeriksaan fisik',
      }.entries) {
        final f = find.widgetWithText(TextField, entry.key);
        await tester.ensureVisible(f);
        await tester.enterText(f, entry.value);
      }
      await tester.ensureVisible(find.text('Tinjau penerimaan di server'));
      await tester.tap(find.text('Tinjau penerimaan di server'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Konfirmasi penerimaan'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Simpan penerimaan'));
      await tester.pumpAndSettle();
      expect(tester.widget<PopScope>(find.byType(PopScope)).canPop, false);
      expect(
        tester
            .widget<IconButton>(
              find.byWidgetPredicate(
                (w) =>
                    w is IconButton &&
                    w.tooltip == 'Muat ulang SKU dan buang tinjauan',
              ),
            )
            .onPressed,
        isNull,
      );
      await tester.ensureVisible(find.text('Ulangi penerimaan yang sama'));
      await tester.tap(find.text('Ulangi penerimaan yang sama'));
      await tester.pumpAndSettle();
      expect(s.posts.first, s.posts.last);
      expect(s.writes, 1);
      expect(find.text('Penerimaan tersimpan · batch B1'), findsOneWidget);
    },
  );
}
