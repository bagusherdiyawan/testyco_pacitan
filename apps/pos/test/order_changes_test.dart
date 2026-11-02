import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/ordering/application/order_changes_controller.dart';
import 'package:testyco_pos/features/ordering/data/order_changes_repository.dart';
import 'package:testyco_pos/features/ordering/data/orders_repository.dart';
import 'package:testyco_pos/features/ordering/presentation/order_detail_page.dart';
import 'package:testyco_pos/features/ordering/presentation/order_changes_page.dart';
import 'package:testyco_pos/features/fulfillment/data/warehouse_repository.dart';
import 'package:testyco_pos/features/fulfillment/presentation/warehouse_order_page.dart';

Map<String, dynamic> clone(Map<String, dynamic> v) =>
    jsonDecode(jsonEncode(v)) as Map<String, dynamic>;

class ChangeServer {
  int version = 3,
      quantity = 20,
      writes = 0,
      reads = 0,
      readStatus = 0,
      postStatus = 0;
  bool supplier = true,
      lose = false,
      failRefresh = false,
      noActions = false,
      extraLine = false;
  String? malformedAck, wrongScope;
  final posts = <Map<String, dynamic>>[], paths = <String>[], pages = <int>[];
  final memo = <String, Map<String, dynamic>>{};
  Map<String, dynamic>? change;
  int price = 18000;
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example.test/api/v1/'),
    client: MockClient(handle),
  );
  late final repository = OrderChangesRepository(api);
  Map<String, dynamic> line(int q) => {
    'id': 'L1',
    'sku': 'AYAM8',
    'local_sku': 'LOCAL8',
    'quantity_packs': q,
    'pack_size': 8,
    'unit_price': price,
  };
  Map<String, dynamic> snapshot(int q) => {
    'lines': [
      line(q),
      if (extraLine)
        {...line(5), 'id': 'L2', 'sku': 'SAPI8', 'local_sku': 'SAPI8'},
    ],
    'total_amount': (q + (extraLine ? 5 : 0)) * price,
  };
  Map<String, dynamic> get order => {
    'id': 'O1',
    'store_id': 'S2',
    'destination_name': 'Toko Pacitan',
    'status': 'processing',
    'version': version,
    'kind': price == 0 ? 'internal_transfer' : 'wholesale',
    ...snapshot(quantity),
    'recipient_actions': <String, bool>{},
    'fulfillment_enabled': true,
    'source_store_id': 'S1',
    'delivery_mode': 'pickup',
    'destination_address': null,
  };
  bool get held => change?['status'] == 'pending';
  Map<String, dynamic> get context => {
    'order': {...order, if (wrongScope == 'order') 'id': 'FOREIGN'},
    'actions': noActions
        ? {}
        : {
            'propose': supplier && !held,
            'approve': !supplier && held,
            'reject': !supplier && held,
            'withdraw': supplier && held,
          },
    'change_pending': held,
    'data': change == null
        ? []
        : [
            {
              ...change!,
              if (wrongScope == 'row') 'supply_order_id': 'FOREIGN',
              if (wrongScope == 'snapshot')
                'after': {
                  'total_amount': 216000,
                  'lines': [
                    {...line(12), 'unit_price': 999},
                  ],
                },
            },
          ],
    'current_page': 1,
    'last_page': 2,
    'total': change == null ? 0 : 1,
    'next_page_url': 'https://untrusted.example/steal?token=never',
  };
  http.Response reply(Object body, [int status = 200]) =>
      http.Response(jsonEncode(body), status);
  Future<http.Response> handle(http.Request r) async {
    if (r.method == 'GET') {
      if (r.url.path.endsWith('/changes')) {
        reads++;
        final page = int.parse(r.url.queryParameters['page'] ?? '1');
        pages.add(page);
        if (readStatus > 0 || (failRefresh && writes > 0)) {
          return reply({
            'message': 'Read denied',
          }, readStatus > 0 ? readStatus : 503);
        }
        return reply({...context, 'current_page': page});
      }
      if (r.url.path.endsWith('/fulfillment/orders/O1')) {
        return reply({
          'data': {
            'order': {
              ...order,
              'lines': [
                {
                  ...line(quantity),
                  'name': 'Ayam',
                  'sent_packs': 0,
                  'remaining_packs': quantity,
                },
              ],
            },
            'source': {'id': 'S1', 'name': 'Gudang Pusat', 'active': true},
            'can_process': false,
            'can_dispatch': !held,
            'batches': [],
            'shipments': [],
            'observed_at': '2026-11-02T01:00:00Z',
          },
        });
      }
      if (r.url.path.endsWith('/shipments')) return reply({'data': []});
      if (r.url.path.endsWith('/orders/O1')) return reply({'data': order});
      throw StateError('Unexpected GET ${r.url}');
    }
    final body = jsonDecode(r.body) as Map<String, dynamic>;
    posts.add(body);
    paths.add(r.url.path);
    if (postStatus > 0) return reply({'message': 'Write denied'}, postStatus);
    final uuid = body['operation_id'] as String;
    if (!memo.containsKey(uuid)) {
      if (body['version'] != version) return reply({'message': 'Stale'}, 409);
      if (r.url.path.endsWith('/changes')) {
        final q =
            ((body['lines'] as List).single as Map)['quantity_packs'] as int;
        change = {
          'id': 'C1',
          'supply_order_id': 'O1',
          'status': 'pending',
          'base_version': version,
          'pending_version': version + 1,
          'requested_by': 1,
          'reason': body['reason'],
          'before': snapshot(quantity),
          'after': snapshot(q),
          'decided_by': null,
          'decision_note': null,
          'decided_at': null,
        };
      } else {
        final action = r.url.pathSegments.last;
        change = {
          ...change!,
          'status': {
            'approve': 'approved',
            'reject': 'rejected',
            'withdraw': 'withdrawn',
          }[action],
          'decided_by': 2,
          'decision_note': body['reason'],
          'decided_at': '2026-11-02T02:00:00Z',
        };
        if (action == 'approve') {
          quantity =
              (((change!['after'] as Map)['lines'] as List).single
                      as Map)['quantity_packs']
                  as int;
        }
      }
      version++;
      writes++;
      memo[uuid] = {'order': clone(order), 'change': clone(change!)};
    }
    if (lose) {
      lose = false;
      return reply({'message': 'Lost after commit'}, 503);
    }
    final result = clone(memo[uuid]!);
    if (malformedAck == 'order') (result['order'] as Map)['id'] = 'OTHER';
    if (malformedAck == 'version') (result['order'] as Map)['version'] = 999;
    if (malformedAck == 'status') (result['change'] as Map)['status'] = 'wrong';
    if (malformedAck == 'change') (result['change'] as Map)['id'] = 'OTHER';
    return reply({'data': result}, r.url.path.endsWith('/changes') ? 201 : 200);
  }

  Future<OrderChangesController> ready({bool pending = false}) async {
    final m = OrderChangesController(repository, 'O1');
    await m.load();
    if (pending) {
      await m.confirm(m.propose({'L1': '12'}, 'Supply shortage'));
      supplier = false;
      await m.load();
    }
    return m;
  }
}

void main() {
  test(
    'proposal requires full review; sends frozen IDs/counts only then reloads current context',
    () async {
      final s = ChangeServer();
      addTearDown(s.api.close);
      final m = await s.ready();
      addTearDown(m.dispose);
      final op = m.propose({'L1': '12'}, ' Reviewed physical shortage ');
      expect(s.posts, isEmpty);
      expect(m.context!.original.total, 360000);
      expect(op.review['after_total'], 216000);
      expect(op.body.keys.toSet(), {
        'operation_id',
        'version',
        'reason',
        'lines',
      });
      expect(op.body['lines'], [
        {'order_line_id': 'L1', 'quantity_packs': 12},
      ]);
      expect(op.body['reason'], 'Reviewed physical shortage');
      final modified = op.body;
      (modified['lines'] as List).clear();
      modified['version'] = 999;
      final review = op.review;
      (review['lines'] as List).clear();
      await m.confirm(op);
      expect(s.writes, 1);
      expect(s.quantity, 20);
      expect(m.context!.held, isTrue);
      expect(m.context!.original.total, 360000);
      expect(m.context!.rows.single.after.total, 216000);
      expect(m.saved, isTrue);
      expect(m.pending, isNull);
      expect(m.fresh, isTrue);
      await m.confirm(op);
      expect(s.writes, 1);
    },
  );
  test(
    'multiple lines retain unchanged quantities and old reviews expire on refresh',
    () async {
      final s = ChangeServer()..extraLine = true;
      addTearDown(s.api.close);
      final m = await s.ready();
      addTearDown(m.dispose);
      final op = m.propose({'L1': '12', 'L2': '5'}, 'Shortage only first SKU');
      expect(op.body['lines'], [
        {'order_line_id': 'L1', 'quantity_packs': 12},
        {'order_line_id': 'L2', 'quantity_packs': 5},
      ]);
      expect(op.review['after_total'], 306000);
      await m.load();
      await m.confirm(op);
      expect(s.posts, isEmpty);
    },
  );
  test(
    'invalid counts, missing/extra lines, blank reason and no reduction never submit',
    () async {
      final s = ChangeServer();
      addTearDown(s.api.close);
      final m = await s.ready();
      addTearDown(m.dispose);
      for (final raw in ['', '0', '-1', '1.5', '0x10', '10001', '21', '20']) {
        expect(
          () => m.propose({'L1': raw}, 'Shortage'),
          throwsA(isA<ApiException>()),
        );
      }
      for (final rows in [
        <String, String>{},
        {'OTHER': '12'},
        {'L1': '12', 'OTHER': '1'},
      ]) {
        expect(() => m.propose(rows, 'Shortage'), throwsA(isA<ApiException>()));
      }
      expect(() => m.propose({'L1': '12'}, ' '), throwsA(isA<ApiException>()));
      expect(
        () => m.propose({'L1': '12'}, 'x' * 2001),
        throwsA(isA<ApiException>()),
      );
      expect(s.posts, isEmpty);
    },
  );
  test(
    'unknown proposal keeps nested UUID/path/body through denial, refresh and replacement',
    () async {
      final s = ChangeServer();
      addTearDown(s.api.close);
      final m = await s.ready();
      addTearDown(m.dispose);
      s.lose = true;
      final op = m.propose({'L1': '12'}, 'Shortage');
      await m.confirm(op);
      expect(s.writes, 1);
      expect(m.pending, same(op));
      expect(m.fresh, isFalse);
      final reads = s.reads;
      await m.load(page: 2);
      expect(s.reads, reads);
      expect(
        () => m.propose({'L1': '10'}, 'Replacement'),
        throwsA(isA<ApiException>()),
      );
      await m.confirm(op);
      expect(s.posts.length, 1);
      s.postStatus = 403;
      await m.retry();
      expect(m.pending, same(op));
      s.postStatus = 0;
      await m.retry();
      expect(s.writes, 1);
      expect(s.posts.length, 3);
      expect(
        s.posts.every((v) => jsonEncode(v) == jsonEncode(s.posts.first)),
        isTrue,
      );
      expect(s.paths.toSet(), {'/api/v1/orders/O1/changes'});
      expect(m.pending, isNull);
      expect(m.context!.version, 4);
    },
  );
  for (final action in ['approve', 'reject', 'withdraw']) {
    test(
      '$action reviews pending snapshots, uses exact version/change and preserves expected quote',
      () async {
        final s = ChangeServer();
        addTearDown(s.api.close);
        final m = await s.ready(pending: true);
        addTearDown(m.dispose);
        if (action == 'withdraw') {
          s.supplier = true;
          await m.load();
        }
        final row = m.context!.rows.single;
        if (action != 'approve') {
          expect(() => m.decide(row, action, ''), throwsA(isA<ApiException>()));
        }
        final op = m.decide(
          row,
          action,
          action == 'approve' ? '' : 'Reviewed decision',
        );
        expect(op.body['version'], 4);
        expect(op.review['before_total'], 360000);
        expect(op.review['after_total'], 216000);
        expect(s.writes, 1);
        await m.confirm(op);
        expect(s.quantity, action == 'approve' ? 12 : 20);
        expect(s.paths.last, '/api/v1/orders/O1/changes/C1/$action');
        expect(
          m.context!.rows.single.status,
          {
            'approve': 'approved',
            'reject': 'rejected',
            'withdraw': 'withdrawn',
          }[action],
        );
        expect(m.context!.held, isFalse);
        expect(m.context!.version, 5);
      },
    );
  }
  test(
    'missing capabilities, stale decision, removed rows and wrong action do not infer permission',
    () async {
      final s = ChangeServer();
      addTearDown(s.api.close);
      final m = await s.ready(pending: true);
      addTearDown(m.dispose);
      final row = m.context!.rows.single;
      expect(
        () => m.propose({'L1': '1'}, 'Cannot'),
        throwsA(isA<ApiException>()),
      );
      expect(
        () => m.decide(row, 'withdraw', 'Not supplier'),
        throwsA(isA<ApiException>()),
      );
      expect(
        () => m.decide(row, 'delete', 'Invalid'),
        throwsA(isA<ApiException>()),
      );
      s.noActions = true;
      await m.load();
      expect(
        () => m.decide(m.context!.rows.single, 'approve', ''),
        throwsA(isA<ApiException>()),
      );
      s.noActions = false;
      s.version++;
      await m.load();
      expect(
        () => m.decide(m.context!.rows.single, 'approve', ''),
        throwsA(isA<ApiException>()),
      );
      expect(() => m.decide(row, 'approve', ''), throwsA(isA<ApiException>()));
      expect(s.writes, 1);
    },
  );
  for (final field in ['order', 'version', 'status', 'change']) {
    test(
      'malformed $field ACK keeps immutable decision until matching replay',
      () async {
        final s = ChangeServer();
        addTearDown(s.api.close);
        final m = await s.ready(pending: true);
        addTearDown(m.dispose);
        final op = m.decide(m.context!.rows.single, 'approve', '');
        s.malformedAck = field;
        await m.confirm(op);
        expect(m.pending, same(op));
        expect(m.saved, isFalse);
        expect(s.writes, 2);
        s.malformedAck = null;
        await m.retry();
        expect(m.pending, isNull);
        expect(s.writes, 2);
        expect(s.posts.last, s.posts[s.posts.length - 2]);
      },
    );
  }
  test(
    'known conflict/validation release initial request; unknown throttling retains it',
    () async {
      final s = ChangeServer();
      addTearDown(s.api.close);
      final m = await s.ready();
      addTearDown(m.dispose);
      for (final status in [403, 409, 422]) {
        s.postStatus = status;
        await m.confirm(m.propose({'L1': '12'}, 'Shortage'));
        expect(m.pending, isNull);
        expect(m.fresh, isFalse);
        await m.load();
      }
      s.postStatus = 429;
      await m.confirm(m.propose({'L1': '12'}, 'Shortage'));
      expect(m.pending, isNotNull);
      expect(m.saved, isFalse);
      expect(s.writes, 0);
    },
  );
  test(
    'acknowledged decision with failed reload is saved but cannot act until new read',
    () async {
      final s = ChangeServer();
      addTearDown(s.api.close);
      final m = await s.ready(pending: true);
      addTearDown(m.dispose);
      s.failRefresh = true;
      await m.confirm(m.decide(m.context!.rows.single, 'approve', ''));
      expect(m.saved, isTrue);
      expect(m.pending, isNull);
      expect(m.fresh, isFalse);
      expect(m.context, isNull);
      expect(m.error, contains('Pencatatan tersimpan'));
      s.failRefresh = false;
      await m.load();
      expect(m.context!.original.total, 216000);
    },
  );
  test(
    'internal transfer stays zero; pagination is scoped and ignores external next URLs',
    () async {
      final s = ChangeServer()..price = 0;
      addTearDown(s.api.close);
      final m = await s.ready();
      addTearDown(m.dispose);
      final op = m.propose({'L1': '12'}, 'Transfer shortage');
      expect(op.review['after_total'], 0);
      await m.confirm(op);
      await m.load(page: 2);
      expect(s.pages.last, 2);
      expect(m.context!.original.total, 0);
      expect(m.context!.rows.single.after.total, 0);
    },
  );
  test(
    'foreign envelope/row and read denial clear previous context without empty success',
    () async {
      final s = ChangeServer();
      addTearDown(s.api.close);
      final m = await s.ready(pending: true);
      addTearDown(m.dispose);
      for (final scope in ['order', 'row', 'snapshot']) {
        s.wrongScope = scope;
        await m.load();
        expect(m.fresh, isFalse);
        expect(m.context, isNull);
        expect(m.error, isNotNull);
      }
      s.wrongScope = null;
      for (final status in [403, 503]) {
        s.readStatus = status;
        await m.load();
        expect(m.fresh, isFalse);
        expect(m.context, isNull);
      }
    },
  );
  test(
    '401 invokes session boundary; pending never enables another action before route reset',
    () async {
      final s = ChangeServer();
      addTearDown(s.api.close);
      var ended = false;
      s.api.onUnauthorized = () => ended = true;
      final m = await s.ready();
      addTearDown(m.dispose);
      s.lose = true;
      await m.confirm(m.propose({'L1': '12'}, 'Shortage'));
      s.postStatus = 401;
      await m.retry();
      expect(ended, isTrue);
      expect(m.editable, isFalse);
      expect(m.pending, isNotNull);
    },
  );

  testWidgets(
    'recipient rejection needs reason and preserves original quote on mobile',
    (tester) async {
      tester.view.physicalSize = const Size(390, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final s = ChangeServer();
      addTearDown(s.api.close);
      final seeded = await s.ready(pending: true);
      seeded.dispose();
      await tester.pumpWidget(
        MaterialApp(
          home: OrderChangesPage(repository: s.repository, orderId: 'O1'),
        ),
      );
      await tester.pumpAndSettle();
      final scroll = find
          .descendant(
            of: find.byKey(const Key('order-changes-scroll')),
            matching: find.byType(Scrollable),
          )
          .first;
      await tester.scrollUntilVisible(
        find.text('Tolak usulan').hitTestable(),
        200,
        scrollable: scroll,
      );
      await tester.tap(find.text('Tolak usulan'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Tinjau keputusan'));
      await tester.pumpAndSettle();
      expect(s.writes, 1);
      expect(find.text('Isi alasan, maksimal 2000 karakter.'), findsOneWidget);
      await tester.tap(find.text('Tolak usulan'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('change-decision-note')),
        'Keep original approved supply',
      );
      await tester.tap(find.text('Tinjau keputusan'));
      await tester.pumpAndSettle();
      expect(find.text('20 → 12 kemasan · isi 8 biji'), findsWidgets);
      await tester.tap(find.text('Konfirmasi pencatatan'));
      await tester.pumpAndSettle();
      expect(s.quantity, 20);
      expect(s.change!['status'], 'rejected');
      expect(find.text('Ditolak'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  for (final setting in [(390.0, 1.5), (1200.0, 1.0)]) {
    testWidgets(
      'supplier warehouse entry proposal/review/unknown retry at ${setting.$1}',
      (tester) async {
        tester.view.physicalSize = Size(setting.$1, 900);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final s = ChangeServer();
        addTearDown(s.api.close);
        await tester.pumpWidget(
          MaterialApp(
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(
                context,
              ).copyWith(textScaler: TextScaler.linear(setting.$2)),
              child: child!,
            ),
            home: WarehouseOrderPage(
              repository: WarehouseRepository(s.api),
              store: 'S1',
              order: 'O1',
            ),
          ),
        );
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.text('Persetujuan ulang dan riwayat'));
        await tester.tap(find.text('Persetujuan ulang dan riwayat'));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.widgetWithText(TextFormField, 'Jumlah usulan AYAM8'),
          '12',
        );
        await tester.ensureVisible(
          find.byKey(const Key('change-proposal-reason')),
        );
        tester.view.viewInsets = const FakeViewPadding(bottom: 280);
        addTearDown(tester.view.resetViewInsets);
        await tester.pumpAndSettle();
        await tester.enterText(
          find.byKey(const Key('change-proposal-reason')),
          'Reviewed shortage',
        );
        await tester.testTextInput.receiveAction(TextInputAction.done);
        tester.view.resetViewInsets();
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.text('Tinjau usulan pengurangan'));
        await tester.tap(find.text('Tinjau usulan pengurangan'));
        await tester.pumpAndSettle();
        expect(s.posts, isEmpty);
        expect(find.text('20 → 12 kemasan · isi 8 biji'), findsOneWidget);
        expect(find.text('Total usulan Rp 216.000'), findsOneWidget);
        s.lose = true;
        await tester.tap(find.text('Konfirmasi pencatatan'));
        await tester.pumpAndSettle();
        expect(s.writes, 1);
        expect(find.textContaining('Hasil belum pasti.'), findsOneWidget);
        await tester.pageBack();
        await tester.pumpAndSettle();
        expect(find.text('Persetujuan ulang'), findsOneWidget);
        final refresh = tester.widget<IconButton>(
          find.byWidgetPredicate(
            (v) => v is IconButton && v.tooltip == 'Muat ulang usulan',
          ),
        );
        expect(refresh.onPressed, isNull);
        await tester.scrollUntilVisible(
          find.text('Ulangi request yang sama').hitTestable(),
          200,
          scrollable: find
              .descendant(
                of: find.byKey(const Key('order-changes-scroll')),
                matching: find.byType(Scrollable),
              )
              .first,
        );
        await tester.tap(find.text('Ulangi request yang sama'));
        await tester.pumpAndSettle();
        expect(s.writes, 1);
        expect(s.posts[0], s.posts[1]);
        await tester.scrollUntilVisible(
          find.text('Menunggu keputusan').hitTestable(),
          200,
          scrollable: find
              .descendant(
                of: find.byKey(const Key('order-changes-scroll')),
                matching: find.byType(Scrollable),
              )
              .first,
        );
        expect(find.text('Menunggu keputusan'), findsOneWidget);
        expect(find.text('Tarik usulan'), findsOneWidget);
        expect(find.text('Setujui jumlah revisi'), findsNothing);
        expect(tester.takeException(), isNull);
      },
    );
    testWidgets(
      'recipient detail entry reads snapshots and approves at ${setting.$1}',
      (tester) async {
        tester.view.physicalSize = Size(setting.$1, 900);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final s = ChangeServer();
        addTearDown(s.api.close);
        final seeded = await s.ready(pending: true);
        seeded.dispose();
        await tester.pumpWidget(
          MaterialApp(
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(
                context,
              ).copyWith(textScaler: TextScaler.linear(setting.$2)),
              child: child!,
            ),
            home: OrderDetailPage(
              repository: OrdersRepository(s.api),
              id: 'O1',
            ),
          ),
        );
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.text('Persetujuan ulang dan riwayat'));
        await tester.tap(find.text('Persetujuan ulang dan riwayat'));
        await tester.pumpAndSettle();
        final scroll = find
            .descendant(
              of: find.byKey(const Key('order-changes-scroll')),
              matching: find.byType(Scrollable),
            )
            .first;
        await tester.scrollUntilVisible(
          find.text('Setujui jumlah revisi').hitTestable(),
          200,
          scrollable: scroll,
        );
        await tester.tap(find.text('Setujui jumlah revisi'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Tinjau keputusan'));
        await tester.pumpAndSettle();
        expect(find.text('Alasan pemasok: Supply shortage'), findsOneWidget);
        expect(s.writes, 1);
        await tester.tap(find.text('Konfirmasi pencatatan'));
        await tester.pumpAndSettle();
        expect(s.quantity, 12);
        expect(find.text('Disetujui'), findsOneWidget);
        expect(find.text('Tarik usulan'), findsNothing);
        expect(tester.takeException(), isNull);
      },
    );
  }
}
