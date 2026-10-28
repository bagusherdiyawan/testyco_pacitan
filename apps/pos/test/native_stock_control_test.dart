import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/inventory/application/stock_control_controller.dart';
import 'package:testyco_pos/features/inventory/data/stock_control_repository.dart';
import 'package:testyco_pos/features/inventory/presentation/stock_control_page.dart';
import 'package:testyco_pos/features/identity/domain/store.dart';

class StockServer {
  Map<String, dynamic> batch = {
    'id': 'B1',
    'sku': 'AYAM',
    'name': 'Ayam',
    'condition': 'frozen',
    'batch_code': 'LOT10',
    'expires_on': '2030-01-01',
    'pack_size': 10,
    'usable_pieces': 10,
    'opened_pieces': 0,
    'ready_pieces': 0,
    'quarantined_pieces': 2,
    'waste_pieces': 0,
    'stock_revision': 1,
  };
  final posts = <Map<String, dynamic>>[],
      paths = <String>[],
      processed = <Map<String, dynamic>>[];
  final receipts = <String>{};
  List<Map<String, dynamic>> counts = [];
  bool loseResponse = false,
      conflict = false,
      failReads = false,
      failRefreshAfterPost = false,
      denied = false;
  int commits = 0, batchReads = 0;
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example.test/api/v1/'),
    client: MockClient(handle),
  );
  StockControlRepository get repository => StockControlRepository(api);
  http.Response reply(Object body, [int status = 200]) =>
      http.Response(jsonEncode(body), status);
  Map<String, dynamic> count({
    bool review = false,
    bool cancel = true,
    bool stale = false,
  }) => {
    'id': 'C1',
    'batch': Map.of(batch),
    'purpose': 'stocktake',
    'bucket': 'opened',
    'status': 'pending',
    'reason': 'Hitung fisik',
    'expected_pieces': 10,
    'counted_pieces': 7,
    'difference_pieces': -3,
    'snapshot_revision': batch['stock_revision'],
    'version': 1,
    'is_stale': stale,
    'can_review': review,
    'can_cancel': cancel,
    'review_note': null,
  };
  Future<http.Response> handle(http.Request r) async {
    final path = r.url.path;
    if (r.method == 'GET') {
      if (denied) {
        return reply({'message': 'Akses ditolak'}, 403);
      }
      if (failReads) {
        return reply({}, 503);
      }
      if (path.endsWith('/control-context')) {
        return reply({
          'data': {
            'store_name': 'Pacitan',
            'active': true,
            'can_process': true,
            'can_propose': true,
            'can_review': true,
          },
        });
      }
      if (path.endsWith('/batches')) {
        batchReads++;
        return reply({
          'data': [batch],
        });
      }
      if (path.endsWith('/counts')) {
        return reply({'data': counts});
      }
      if (path.endsWith('/processes')) {
        return reply({'data': processed});
      }
      throw StateError('Unexpected GET $path');
    }
    final body = jsonDecode(r.body) as Map<String, dynamic>;
    posts.add(body);
    paths.add(path);
    if (conflict) {
      return reply({'message': 'Snapshot stok berubah'}, 409);
    }
    final operation = body['operation_id'] as String;
    if (receipts.add(operation)) {
      commits++;
      if (path.endsWith('/processes')) {
        final before = Map.of(batch);
        final input = body['kind'] == 'open'
            ? (body['quantity_packs'] as int) * 10
            : body['input_pieces'] as int;
        final output = body['kind'] == 'open'
            ? input
            : body['output_pieces'] as int;
        if (body['kind'] == 'open') {
          batch = {
            ...batch,
            'usable_pieces': (batch['usable_pieces'] as int) - input,
            'opened_pieces': (batch['opened_pieces'] as int) + input,
            'stock_revision': (batch['stock_revision'] as int) + 1,
          };
        } else {
          batch = {
            ...batch,
            'opened_pieces': (batch['opened_pieces'] as int) - input,
            'ready_pieces': (batch['ready_pieces'] as int) + output,
            'waste_pieces': (batch['waste_pieces'] as int) + input - output,
            'stock_revision': (batch['stock_revision'] as int) + 1,
          };
        }
        processed.add({
          'kind': body['kind'],
          'batch': before,
          'input_pieces': input,
          'output_pieces': output,
          'waste_pieces': input - output,
          'reason': body['reason'],
        });
      } else if (path.endsWith('/counts')) {
        counts = [
          {
            ...count(),
            'purpose': body['purpose'],
            'bucket': body['bucket'],
            'counted_pieces': body['counted_pieces'],
          },
        ];
      } else {
        counts = [
          {
            ...counts.first,
            'status': path.endsWith('/approve')
                ? 'approved'
                : path.endsWith('/reject')
                ? 'rejected'
                : 'cancelled',
            'can_review': false,
            'can_cancel': false,
            'version': 2,
            'review_note': body['review_note'],
          },
        ];
      }
    }
    if (loseResponse) {
      loseResponse = false;
      throw http.ClientException('Response lost after commit');
    }
    if (failRefreshAfterPost) {
      failReads = true;
      failRefreshAfterPost = false;
    }
    return reply({
      'data': {'id': 'RESULT'},
    });
  }
}

void main() {
  StockControlController modelFor(StockServer s) {
    addTearDown(s.api.close);
    final m = StockControlController(s.repository, 'S1');
    addTearDown(m.dispose);
    return m;
  }

  test(
    'Open and cook retain historical revision and actual output, including total loss',
    () async {
      final s = StockServer();
      final m = modelFor(s);
      await m.load();
      m.select(m.batches.first);
      expect(
        await m.process(
          kind: 'open',
          quantity: '1',
          output: '',
          reason: 'Dibuka fisik',
        ),
        true,
      );
      expect(s.batch['usable_pieces'], 0);
      expect(s.batch['opened_pieces'], 10);
      expect(s.posts.first['expected_revision'], 1);
      expect(s.posts.first.containsKey('output_pieces'), false);
      expect(m.selected, isNull);
      m.select(m.batches.first);
      expect(
        await m.process(
          kind: 'cook',
          quantity: '6',
          output: '0',
          reason: 'Seluruh hasil gagal',
        ),
        true,
      );
      expect(s.posts.last['expected_revision'], 2);
      expect(s.posts.last['output_pieces'], 0);
      expect(s.batch['opened_pieces'], 4);
      expect(s.batch['ready_pieces'], 0);
      expect(s.batch['waste_pieces'], 6);
    },
  );
  test(
    'Lost response retains exact operation and blocks new writes until retry',
    () async {
      final s = StockServer();
      final m = modelFor(s);
      await m.load();
      m.select(m.batches.first);
      s.loseResponse = true;
      expect(
        await m.process(
          kind: 'open',
          quantity: '1',
          output: '',
          reason: 'Fisik',
        ),
        false,
      );
      expect(m.pending, isNotNull);
      expect(s.commits, 1);
      final original = Map.of(s.posts.first);
      expect(
        await m.process(
          kind: 'cook',
          quantity: '9',
          output: '9',
          reason: 'Changed',
        ),
        false,
      );
      expect(s.posts.length, 1);
      await m.load();
      expect(m.pending, isNotNull);
      expect(m.canAct, false);
      expect(await m.retry(), true);
      expect(s.posts.last, original);
      expect(s.commits, 1);
      expect(m.pending, isNull);
      expect(m.batches.first.opened, 10);
    },
  );
  test(
    'Stale revision clears selection and requires reload and new physical observation',
    () async {
      final s = StockServer();
      final m = modelFor(s);
      await m.load();
      m.select(m.batches.first);
      s.conflict = true;
      expect(
        await m.propose(
          purpose: 'stocktake',
          bucket: 'opened',
          counted: '0',
          reason: 'Fisik',
        ),
        false,
      );
      expect(m.fresh, false);
      expect(m.selected, isNull);
      expect(m.pending, isNull);
      expect(
        await m.process(
          kind: 'open',
          quantity: '1',
          output: '',
          reason: 'Fisik',
        ),
        false,
      );
      expect(s.posts.length, 1);
      s.conflict = false;
      await m.load();
      expect(m.selected, isNull);
      m.select(m.batches.first);
      expect(
        await m.propose(
          purpose: 'stocktake',
          bucket: 'opened',
          counted: '0',
          reason: 'Dihitung ulang',
        ),
        true,
      );
      expect(s.batch['usable_pieces'], 10);
      expect(s.posts.last['expected_revision'], 1);
      expect(s.posts.last.containsKey('difference_pieces'), false);
    },
  );
  test(
    'Self review and stale approval are denied while reject/cancel preserve version',
    () async {
      final s = StockServer();
      s.counts = [s.count()];
      final m = modelFor(s);
      await m.load();
      expect(await m.review(m.counts.first, 'approve', 'Tidak boleh'), false);
      expect(s.posts, isEmpty);
      s.counts = [s.count(review: true, cancel: false, stale: true)];
      await m.load();
      expect(await m.review(m.counts.first, 'approve', 'Stale'), false);
      expect(s.posts, isEmpty);
      expect(await m.review(m.counts.first, 'reject', 'Hitung ulang'), true);
      expect(s.paths.last.endsWith('/reject'), true);
      expect(s.posts.last['version'], 1);
      s.counts = [s.count()];
      await m.load();
      expect(
        await m.review(m.counts.first, 'cancel', 'Dibatalkan pengusul'),
        true,
      );
      expect(s.paths.last.endsWith('/cancel'), true);
    },
  );
  test(
    'Successful write plus refresh failure does not leave a retry or allow stale writes',
    () async {
      final s = StockServer();
      final m = modelFor(s);
      await m.load();
      m.select(m.batches.first);
      s.failRefreshAfterPost = true;
      expect(
        await m.process(
          kind: 'open',
          quantity: '1',
          output: '',
          reason: 'Fisik',
        ),
        false,
      );
      expect(s.commits, 1);
      expect(m.pending, isNull);
      expect(m.fresh, false);
      expect(m.error, contains('Operasi tersimpan'));
      s.failReads = false;
      await m.load();
      expect(m.batches.first.opened, 10);
      expect(m.canAct, true);
      expect(m.selected, isNull);
    },
  );
  test('Control context denial stops history and stock requests', () async {
    final s = StockServer()..denied = true;
    final m = modelFor(s);
    expect(await m.load(), false);
    expect(s.batchReads, 0);
    expect(m.fresh, false);
    expect(m.context, isNull);
  });
  test(
    'Invalid physical values, forbidden bucket and nonfrozen batch never post',
    () async {
      final s = StockServer();
      final m = modelFor(s);
      await m.load();
      m.select(m.batches.first);
      for (final value in ['-1', '1.5', '10001', '2']) {
        expect(
          await m.process(
            kind: 'open',
            quantity: value,
            output: '',
            reason: 'Fisik',
          ),
          false,
        );
      }
      expect(
        await m.propose(
          purpose: 'stocktake',
          bucket: 'usable',
          counted: '11',
          reason: 'Fisik',
        ),
        false,
      );
      expect(
        await m.propose(
          purpose: 'stocktake',
          bucket: 'waste',
          counted: '0',
          reason: 'Fisik',
        ),
        false,
      );
      expect(
        await m.propose(
          purpose: 'waste',
          bucket: 'opened',
          counted: '0',
          reason: 'Fisik',
        ),
        false,
      );
      expect(
        await m.process(kind: 'open', quantity: '1', output: '', reason: ' '),
        false,
      );
      expect(s.posts, isEmpty);
      s.batch = {...s.batch, 'condition': 'ready'};
      await m.load();
      m.select(m.batches.first);
      expect(
        await m.process(
          kind: 'open',
          quantity: '1',
          output: '',
          reason: 'Fisik',
        ),
        false,
      );
      expect(s.posts, isEmpty);
    },
  );
  test('Assigned role controls navigation while API remains authoritative', () {
    expect(
      const AssignedStore(
        id: 'S1',
        name: 'A',
        active: true,
        role: 'cashier',
      ).canControlStock,
      false,
    );
    expect(
      const AssignedStore(
        id: 'S1',
        name: 'A',
        active: true,
        role: 'warehouse',
      ).canControlStock,
      true,
    );
    expect(
      const AssignedStore(id: 'S1', name: 'A', active: true).canControlStock,
      false,
    );
  });
  for (final width in [390.0, 1200.0]) {
    testWidgets('Native physical processing and proposal flow at $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 1200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final s = StockServer();
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: StockControlPage(repository: s.repository, storeId: 'S1'),
        ),
      );
      await tester.pumpAndSettle();
      Future<void> tapText(String text) async {
        final f = find.text(text).last;
        await tester.ensureVisible(f);
        await tester.tap(f);
        await tester.pumpAndSettle();
      }

      Future<void> formMode(String text) async {
        await tester.ensureVisible(find.byKey(const Key('stock-kind')));
        await tester.tap(find.byKey(const Key('stock-kind')));
        await tester.pumpAndSettle();
        await tapText(text);
      }

      Future<void> fill(String quantity, {String? output}) async {
        await tester.enterText(
          find.descendant(
            of: find.byKey(const Key('stock-quantity')),
            matching: find.byType(TextField),
          ),
          quantity,
        );
        if (output != null) {
          await tester.enterText(
            find.descendant(
              of: find.byKey(const Key('stock-output')),
              matching: find.byType(TextField),
            ),
            output,
          );
        }
        await tester.enterText(
          find.byKey(const Key('stock-reason')),
          'Hitungan fisik',
        );
        await tapText('Tinjau pencatatan');
        await tapText('Konfirmasi pencatatan');
      }

      await tapText('Pilih batch LOT10');
      await formMode('Buka kemasan');
      await fill('1');
      expect(s.batch['opened_pieces'], 10);
      await tapText('Pilih batch LOT10');
      await formMode('Kukus');
      await fill('6', output: '5');
      expect(s.batch['opened_pieces'], 4);
      expect(s.batch['ready_pieces'], 5);
      expect(s.batch['waste_pieces'], 1);
      await tapText('Pilih batch LOT10');
      await fill('3');
      expect(s.counts.first['status'], 'pending');
      expect(s.batch['opened_pieces'], 4);
      expect(find.text('Setujui penyesuaian'), findsNothing);
      expect(find.text('Batalkan pengajuan sendiri'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
  testWidgets(
    'Stale proposal exposes rejection but no approval and sends review reason',
    (tester) async {
      final s = StockServer();
      s.counts = [s.count(review: true, cancel: false, stale: true)];
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: StockControlPage(repository: s.repository, storeId: 'S1'),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.textContaining('Snapshot kedaluwarsa'), findsOneWidget);
      expect(find.text('Setujui penyesuaian'), findsNothing);
      await tester.ensureVisible(find.text('Tolak pengajuan'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Tolak pengajuan'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('stock-review-note')),
        'Hitung ulang',
      );
      await tester.tap(find.text('Konfirmasi review'));
      await tester.pumpAndSettle();
      expect(s.posts.last['review_note'], 'Hitung ulang');
      expect(s.paths.last.endsWith('/reject'), true);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'Authorized fresh review confirms original version and keeps proposal history',
    (tester) async {
      final s = StockServer();
      s.counts = [s.count(review: true, cancel: false)];
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: StockControlPage(repository: s.repository, storeId: 'S1'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Setujui penyesuaian'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Setujui penyesuaian'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('stock-review-note')),
        'Diperiksa petugas berbeda',
      );
      await tester.tap(find.text('Konfirmasi review'));
      await tester.pumpAndSettle();
      expect(s.paths.last.endsWith('/approve'), true);
      expect(s.posts.last['version'], 1);
      expect(s.posts.last.containsKey('counted_pieces'), false);
      expect(s.counts.first['status'], 'approved');
      expect(tester.takeException(), isNull);
    },
  );
  testWidgets(
    'Uncertain operation shows exact retry and prevents leaving page',
    (tester) async {
      tester.view.physicalSize = const Size(390, 1200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final s = StockServer()..loseResponse = true;
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: StockControlPage(repository: s.repository, storeId: 'S1'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Pilih batch LOT10'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.descendant(
          of: find.byKey(const Key('stock-quantity')),
          matching: find.byType(TextField),
        ),
        '0',
      );
      await tester.enterText(find.byKey(const Key('stock-reason')), 'Fisik');
      await tester.ensureVisible(find.text('Tinjau pencatatan'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Tinjau pencatatan'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Konfirmasi pencatatan'));
      await tester.pumpAndSettle();
      expect(find.text('Ulangi operasi yang sama'), findsOneWidget);
      final scope = tester.widget<PopScope>(find.byType(PopScope).first);
      expect(scope.canPop, false);
      await tester.ensureVisible(find.text('Ulangi operasi yang sama'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Ulangi operasi yang sama'));
      await tester.pumpAndSettle();
      expect(s.commits, 1);
      expect(s.posts.length, 2);
      expect(s.posts.first, s.posts.last);
      expect(find.text('Ulangi operasi yang sama'), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );
}
