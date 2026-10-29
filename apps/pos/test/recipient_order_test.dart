import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/ordering/application/recipient_order_controller.dart';
import 'package:testyco_pos/features/ordering/data/orders_repository.dart';
import 'package:testyco_pos/features/ordering/domain/recipient_order.dart';
import 'package:testyco_pos/features/ordering/presentation/orders_page.dart';

class RecipientServer {
  Map<String, dynamic> order = {
    'id': 'O1',
    'destination_name': 'Pacitan A1',
    'kind': 'wholesale',
    'status': 'dispatched',
    'version': 3,
    'total_amount': 360000,
    'decision_note': null,
    'lines': [
      {
        'sku': 'AYAM8',
        'local_sku': 'LOCAL8',
        'quantity_packs': 20,
        'pack_size': 8,
        'unit_price': 18000,
      },
    ],
  };
  List<Map<String, dynamic>> deliveries = [
    {
      'id': 'H1',
      'handler': 'Petugas gudang',
      'received_at': null,
      'receipt_note': null,
      'lines': [
        {
          'id': 'L1',
          'batch_code': 'B-001',
          'expires_on': '2030-01-01',
          'quantity_packs': 20,
          'pack_size': 8,
          'good_packs': null,
          'damaged_packs': null,
          'condition_note': null,
        },
      ],
    },
  ];
  final posts = <Map<String, dynamic>>[], paths = <String>[];
  final operations = <String>{};
  bool lead = false,
      fulfillment = true,
      denied = false,
      failReads = false,
      failRefreshAfterPost = false,
      loseResponse = false,
      conflict = false;
  int goodPieces = 0,
      damagedPieces = 0,
      commits = 0,
      shipmentReads = 0,
      listReads = 0;
  Map<String, bool> get actions => {
    'approve':
        lead && ['awaiting_approval', 'rejected'].contains(order['status']),
    'reject':
        lead && ['awaiting_approval', 'rejected'].contains(order['status']),
    'cancel':
        lead &&
        [
          'awaiting_approval',
          'rejected',
          'approved',
          'processing',
        ].contains(order['status']),
    'receive':
        fulfillment &&
        ['dispatched', 'partially_received'].contains(order['status']),
  };
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example.test/api/v1/'),
    client: MockClient(handle),
  );
  OrdersRepository get repository => OrdersRepository(api);
  http.Response reply(Object body, [int status = 200]) =>
      http.Response(jsonEncode(body), status);
  void secondLine() {
    order = {
      ...order,
      'total_amount': 485000,
      'lines': [
        ...(order['lines'] as List),
        {
          'sku': 'SAPI10',
          'local_sku': 'SAPI10',
          'quantity_packs': 5,
          'pack_size': 10,
          'unit_price': 25000,
        },
      ],
    };
    deliveries = [
      {
        ...deliveries.first,
        'lines': [
          ...(deliveries.first['lines'] as List),
          {
            'id': 'L2',
            'batch_code': 'B-002',
            'expires_on': '2030-02-01',
            'quantity_packs': 5,
            'pack_size': 10,
            'good_packs': null,
            'damaged_packs': null,
            'condition_note': null,
          },
        ],
      },
    ];
  }

  Future<http.Response> handle(http.Request r) async {
    final path = r.url.path;
    if (r.method == 'GET') {
      if (denied) {
        return reply({'message': 'Akses ditolak'}, 403);
      }
      if (failReads) {
        return reply({}, 503);
      }
      if (path.endsWith('/stores/S1/orders')) {
        listReads++;
        return reply({
          'data': [order],
        });
      }
      if (path.endsWith('/shipments')) {
        shipmentReads++;
        return reply({'data': deliveries});
      }
      if (path.endsWith('/orders/O1')) {
        return reply({
          'data': {
            ...order,
            'fulfillment_enabled': fulfillment,
            'recipient_actions': actions,
          },
        });
      }
      throw StateError('Unexpected GET $path');
    }
    final body = jsonDecode(r.body) as Map<String, dynamic>;
    posts.add(body);
    paths.add(path);
    if (conflict) {
      return reply({'message': 'Versi pesanan berubah'}, 409);
    }
    final operation = body['operation_id'] as String;
    if (!operations.contains(operation)) {
      if (body['version'] != order['version']) {
        return reply({'message': 'Versi pesanan berubah'}, 409);
      }
      operations.add(operation);
      commits++;
      if (path.endsWith('/receive')) {
        final rows = (body['lines'] as List).cast<Map<String, dynamic>>();
        final next = <Map<String, dynamic>>[];
        var allGood = true;
        for (final old in deliveries.first['lines'] as List) {
          final line = old as Map<String, dynamic>;
          final actual = rows.firstWhere(
            (a) => a['shipment_line_id'] == line['id'],
          );
          goodPieces +=
              (actual['good_packs'] as int) * (line['pack_size'] as int);
          damagedPieces +=
              (actual['damaged_packs'] as int) * (line['pack_size'] as int);
          allGood = allGood && actual['good_packs'] == line['quantity_packs'];
          next.add({
            ...line,
            'good_packs': actual['good_packs'],
            'damaged_packs': actual['damaged_packs'],
            'condition_note': actual['condition_note'],
          });
        }
        deliveries = [
          {
            ...deliveries.first,
            'received_at': '2026-10-29T03:00:00Z',
            'receipt_note': body['note'],
            'lines': next,
          },
        ];
        order = {
          ...order,
          'status': allGood ? 'completed' : 'partially_received',
          'version': (order['version'] as int) + 1,
        };
      } else {
        final action = path.split('/').last;
        order = {
          ...order,
          'status': switch (action) {
            'approve' => 'approved',
            'reject' => 'rejected',
            _ => 'cancelled',
          },
          'version': (order['version'] as int) + 1,
          'decision_note': action == 'approve' ? null : body['reason'],
        };
      }
    }
    if (loseResponse) {
      loseResponse = false;
      throw http.ClientException('Lost response after commit');
    }
    if (failRefreshAfterPost) {
      failReads = true;
      failRefreshAfterPost = false;
    }
    return reply({
      'data': {
        'order': order,
        'shipment': deliveries.isEmpty ? null : deliveries.first,
      },
    });
  }
}

void main() {
  RecipientOrderController modelFor(RecipientServer s) {
    addTearDown(s.api.close);
    final m = RecipientOrderController(s.repository, 'O1');
    addTearDown(m.dispose);
    return m;
  }

  Map<String, ReceiptLineInput> actual({
    String good = '18',
    String damaged = '2',
    String note = 'Dua kemasan rusak',
  }) => {'L1': ReceiptLineInput(good: good, damaged: damaged, note: note)};
  test(
    'Actual receipt uses historical pack size and does not guess complete status',
    () async {
      final s = RecipientServer();
      final m = modelFor(s);
      await m.load();
      m.select(m.shipments.first);
      expect(
        await m.receive(m.selected!, 3, actual(), 'Pemeriksaan fisik'),
        true,
      );
      expect(s.goodPieces, 144);
      expect(s.damagedPieces, 16);
      expect(m.order!.status, 'partially_received');
      expect(m.shipments.first.receivedAt, isNotNull);
      expect(m.selected, isNull);
      expect(s.posts.single['version'], 3);
      expect(s.posts.single.keys.toSet(), {
        'operation_id',
        'version',
        'note',
        'lines',
      });
      expect((s.posts.single['lines'] as List).single, {
        'shipment_line_id': 'L1',
        'good_packs': 18,
        'damaged_packs': 2,
        'condition_note': 'Dua kemasan rusak',
      });
    },
  );
  test(
    'Missing explicit zeros invalid values overflow and missing discrepancy note never post',
    () async {
      final s = RecipientServer();
      final m = modelFor(s);
      await m.load();
      m.select(m.shipments.first);
      for (final input in [
        actual(good: ''),
        actual(damaged: ''),
        actual(good: '1.5'),
        actual(good: '-1'),
        actual(good: '10001'),
        actual(good: '20', damaged: '1'),
        actual(note: ' '),
      ]) {
        expect(await m.receive(m.selected!, 3, input, ''), false);
      }
      expect(s.posts, isEmpty);
      expect(s.goodPieces, 0);
      expect(
        await m.receive(
          m.selected!,
          3,
          actual(good: '0', damaged: '0', note: 'Barang belum ditemukan'),
          'Tidak diterima fisik',
        ),
        true,
      );
      expect(s.goodPieces, 0);
      expect(s.damagedPieces, 0);
      expect(m.order!.status, 'partially_received');
    },
  );
  test(
    'Multi-line shipment requires every line exactly once and preserves individual facts',
    () async {
      final s = RecipientServer()..secondLine();
      final m = modelFor(s);
      await m.load();
      m.select(m.shipments.first);
      expect(await m.receive(m.selected!, 3, actual(), ''), false);
      expect(
        await m.receive(m.selected!, 3, {
          ...actual(),
          'FOREIGN': const ReceiptLineInput(good: '5', damaged: '0', note: ''),
        }, ''),
        false,
      );
      expect(s.posts, isEmpty);
      expect(
        await m.receive(m.selected!, 3, {
          ...actual(),
          'L2': const ReceiptLineInput(good: '5', damaged: '0', note: ''),
        }, ''),
        true,
      );
      expect(s.goodPieces, 194);
      expect(s.damagedPieces, 16);
      expect((s.posts.single['lines'] as List).length, 2);
    },
  );
  test(
    'Lost receipt response keeps nested payload immutable across reload and exact retry',
    () async {
      final s = RecipientServer()..loseResponse = true;
      final m = modelFor(s);
      await m.load();
      m.select(m.shipments.first);
      expect(await m.receive(m.selected!, 3, actual(), 'Fisik'), false);
      expect(m.pending, isNotNull);
      expect(s.commits, 1);
      final first = jsonDecode(jsonEncode(s.posts.first));
      (m.pending!.body['lines'] as List).first['good_packs'] = 999;
      expect(await m.transition('cancel', 'Pengganti', version: 3), false);
      expect(s.posts.length, 1);
      await m.load();
      expect(m.shipments.first.receivedAt, isNotNull);
      expect(m.pending, isNotNull);
      expect(m.canAct, false);
      expect(await m.retry(), true);
      expect(s.posts.last, first);
      expect(s.commits, 1);
      expect(s.goodPieces, 144);
      expect(s.damagedPieces, 16);
    },
  );
  test(
    'Stale version discards selection and cannot silently reuse physical input after reload',
    () async {
      final s = RecipientServer()..conflict = true;
      final m = modelFor(s);
      await m.load();
      m.select(m.shipments.first);
      final old = m.selected!;
      expect(await m.receive(old, 3, actual(), ''), false);
      expect(m.fresh, false);
      expect(m.selected, isNull);
      expect(m.pending, isNull);
      s.conflict = false;
      s.order = {...s.order, 'version': 4};
      await m.load();
      m.select(m.shipments.first);
      expect(await m.receive(old, 3, actual(), ''), false);
      expect(await m.receive(old, 4, actual(), ''), false);
      expect(s.posts.length, 1);
      expect(await m.receive(m.selected!, 4, actual(), ''), true);
      expect(s.posts.last['version'], 4);
    },
  );
  test(
    'Committed receipt plus failed refresh does not leave a mutation retry',
    () async {
      final s = RecipientServer()..failRefreshAfterPost = true;
      final m = modelFor(s);
      await m.load();
      m.select(m.shipments.first);
      expect(await m.receive(m.selected!, 3, actual(), ''), false);
      expect(m.pending, isNull);
      expect(m.fresh, false);
      expect(m.error, contains('Pencatatan tersimpan'));
      expect(s.commits, 1);
      s.failReads = false;
      await m.load();
      expect(m.shipments.first.receivedAt, isNotNull);
      expect(s.posts.length, 1);
      expect(m.selected, isNull);
    },
  );
  test(
    'Approval reject and cancel use server flags version and required reasons',
    () async {
      final s = RecipientServer();
      s.order = {...s.order, 'status': 'awaiting_approval', 'version': 1};
      final m = modelFor(s);
      await m.load();
      expect(await m.transition('approve', '', version: 1), false);
      expect(s.posts, isEmpty);
      s.lead = true;
      await m.load();
      expect(await m.transition('reject', ' ', version: 1), false);
      expect(await m.transition('approve', '', version: 2), false);
      expect(s.posts, isEmpty);
      expect(await m.transition('approve', '', version: 1), true);
      expect(s.posts.single['version'], 1);
      expect(s.goodPieces, 0);
      expect(m.order!.status, 'approved');
      expect(await m.transition('cancel', 'Tidak jadi', version: 2), true);
      expect(m.order!.status, 'cancelled');
      expect(s.posts.last['reason'], 'Tidak jadi');
    },
  );
  test(
    'Decision response lost after commit retries original version instead of taking a new action',
    () async {
      final s = RecipientServer()..lead = true;
      s.order = {...s.order, 'status': 'awaiting_approval', 'version': 1};
      final m = modelFor(s);
      await m.load();
      s.loseResponse = true;
      expect(await m.transition('reject', 'Perlu koreksi', version: 1), false);
      expect(m.pending, isNotNull);
      await m.load();
      expect(m.order!.version, 2);
      expect(await m.retry(), true);
      expect(s.posts.first, s.posts.last);
      expect(s.commits, 1);
      expect(m.order!.decisionNote, 'Perlu koreksi');
    },
  );
  test(
    'Denied detail and disabled Fulfillment do not request shipments',
    () async {
      final s = RecipientServer()..denied = true;
      final m = modelFor(s);
      expect(await m.load(), false);
      expect(s.shipmentReads, 0);
      s.denied = false;
      s.fulfillment = false;
      expect(await m.load(), true);
      expect(s.shipmentReads, 0);
      expect(m.shipments, isEmpty);
      expect(m.order!.allows('receive'), false);
    },
  );
  for (final setting in [(390.0, 1.5), (1200.0, 1.0)]) {
    testWidgets(
      'Native actual receipt from orders navigation at ${setting.$1} scale ${setting.$2}',
      (tester) async {
        tester.view.physicalSize = Size(setting.$1, 1200);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final s = RecipientServer();
        if (setting.$1 > 1000) {
          s.secondLine();
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
              body: OrdersPage(repository: s.repository, storeId: 'S1'),
            ),
          ),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('O1'));
        await tester.pumpAndSettle();
        expect(find.text('Setujui pengajuan'), findsNothing);
        await tester.ensureVisible(find.text('Isi penerimaan H1'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Isi penerimaan H1'));
        await tester.pumpAndSettle();
        Future<void> fill(
          String id,
          String good,
          String damaged,
          String note,
        ) async {
          final fields = find.descendant(
            of: find.byKey(ValueKey('receipt-line-$id')),
            matching: find.byType(TextField),
          );
          expect(
            tester.widget<TextField>(fields.at(0)).controller!.text,
            isEmpty,
          );
          expect(
            tester.widget<TextField>(fields.at(1)).controller!.text,
            isEmpty,
          );
          await tester.enterText(fields.at(0), good);
          await tester.enterText(fields.at(1), damaged);
          await tester.enterText(fields.at(2), note);
        }

        await fill('L1', '18', '2', 'Dua kemasan rusak');
        if (setting.$1 > 1000) {
          await fill('L2', '5', '0', '');
        }
        await tester.scrollUntilVisible(
          find.text('Tinjau penerimaan'),
          250,
          scrollable: find
              .descendant(
                of: find.byKey(const Key('order-detail-scroll')),
                matching: find.byType(Scrollable),
              )
              .first,
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Tinjau penerimaan'));
        await tester.pumpAndSettle();
        expect(find.text('Tinjau penerimaan aktual'), findsOneWidget);
        await tester.tap(find.text('Konfirmasi penerimaan'));
        await tester.pumpAndSettle();
        expect(s.goodPieces, setting.$1 > 1000 ? 194 : 144);
        expect(s.damagedPieces, 16);
        expect(find.text('Isi penerimaan H1'), findsNothing);
        expect(find.text('Penerimaan dikonfirmasi'), findsOneWidget);
        await tester.pageBack();
        await tester.pumpAndSettle();
        expect(s.listReads, 2);
        expect(tester.takeException(), isNull);
      },
    );
  }
  testWidgets(
    'Approval and rejection review historical order and send scoped version',
    (tester) async {
      final s = RecipientServer()..lead = true;
      s.order = {...s.order, 'status': 'awaiting_approval', 'version': 1};
      s.deliveries = [];
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: OrderDetailPage(repository: s.repository, id: 'O1'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Tolak pengajuan'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('order-decision-reason')),
        'Jumlah perlu dikoreksi',
      );
      await tester.tap(find.text('Konfirmasi keputusan'));
      await tester.pumpAndSettle();
      expect(s.posts.single['reason'], 'Jumlah perlu dikoreksi');
      expect(s.posts.single['version'], 1);
      expect(s.goodPieces, 0);
      await tester.tap(find.text('Setujui pengajuan'));
      await tester.pumpAndSettle();
      expect(find.textContaining('20 kemasan × 8 biji'), findsWidgets);
      await tester.tap(find.text('Konfirmasi keputusan'));
      await tester.pumpAndSettle();
      expect(s.posts.last['version'], 2);
      expect(find.text('Setujui pengajuan'), findsNothing);
      expect(find.text('Batalkan pesanan'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );
  testWidgets(
    'Uncertain receipt disables new input and pop while exact retry reconciles',
    (tester) async {
      tester.view.physicalSize = const Size(390, 1200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final s = RecipientServer()..loseResponse = true;
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: OrderDetailPage(repository: s.repository, id: 'O1'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Isi penerimaan H1'));
      await tester.pumpAndSettle();
      final fields = find.descendant(
        of: find.byKey(const ValueKey('receipt-line-L1')),
        matching: find.byType(TextField),
      );
      await tester.enterText(fields.at(0), '20');
      await tester.enterText(fields.at(1), '0');
      await tester.ensureVisible(find.text('Tinjau penerimaan'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Tinjau penerimaan'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Konfirmasi penerimaan'));
      await tester.pumpAndSettle();
      expect(
        tester.widget<PopScope>(find.byType(PopScope).first).canPop,
        false,
      );
      expect(tester.widget<TextField>(fields.at(0)).enabled, false);
      await tester.ensureVisible(find.text('Ulangi pencatatan yang sama'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Ulangi pencatatan yang sama'));
      await tester.pumpAndSettle();
      expect(s.commits, 1);
      expect(s.goodPieces, 160);
      expect(s.posts.first, s.posts.last);
      expect(find.text('Ulangi pencatatan yang sama'), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );
}
