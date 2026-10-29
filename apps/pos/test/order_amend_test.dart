import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:testyco_pos/features/ordering/application/order_entry_controller.dart';
import 'package:testyco_pos/features/ordering/data/orders_repository.dart';
import 'package:testyco_pos/features/ordering/presentation/order_detail_page.dart';
import 'package:testyco_pos/design_system/molecules/order_line_editor.dart';
import 'support/order_entry_server.dart';

class AmendServer extends EntryServer {
  int version = 1, amendCount = 0;
  String status = 'awaiting_approval', seedStore = 'S1';
  bool allowed = true;
  Map<String, dynamic> stored = {
    'delivery_mode': 'pickup',
    'note': 'Awal',
    'lines': [
      {
        'supplier_product_id': 'P1',
        'local_product_id': 'L1',
        'quantity_packs': 20,
      },
    ],
  };
  late List<Map<String, dynamic>> snapshot = quoteLines(stored);
  final amends = <Map<String, dynamic>>[],
      amendPreviews = <Map<String, dynamic>>[],
      memo = <String>{};
  void externalChange() {
    version++;
    stored = {
      ...stored,
      'note': 'Perubahan pengguna lain',
      'lines': [
        {
          'supplier_product_id': 'P1',
          'local_product_id': 'L1',
          'quantity_packs': 3,
        },
      ],
    };
    snapshot = quoteLines(stored);
  }

  @override
  Future<http.Response> handle(http.Request r) async {
    final path = r.url.path;
    if (r.method == 'GET' && path.endsWith('/orders/O9')) {
      return reply({
        'data': {
          'id': 'O9',
          'store_id': seedStore,
          'destination_name': 'Pacitan',
          'kind': context['order_kind'],
          'status': status,
          'version': version,
          'total_amount': snapshot.fold<int>(
            0,
            (n, l) =>
                n + (l['quantity_packs'] as int) * (l['unit_price'] as int),
          ),
          'delivery_mode': stored['delivery_mode'],
          'note': stored['note'],
          'fulfillment_enabled': false,
          'recipient_actions': {
            'amend':
                allowed && ['awaiting_approval', 'rejected'].contains(status),
            'approve': false,
            'reject': false,
            'cancel': false,
            'receive': false,
          },
          'lines': snapshot,
        },
      });
    }
    if (r.method == 'POST' && path.endsWith('/orders/O9/preview')) {
      final body = jsonDecode(r.body) as Map<String, dynamic>;
      amendPreviews.add(body);
      if (!allowed) {
        return reply({'message': 'Denied'}, 403);
      }
      if (body['version'] != version ||
          !['awaiting_approval', 'rejected'].contains(status)) {
        return reply({'message': 'Versi berubah'}, 409);
      }
      final rows = quoteLines(body);
      return reply({
        'data': {
          'order_id': 'O9',
          'version': version,
          'destination_name': 'Pacitan',
          'destination_address': context['address'],
          'kind': context['order_kind'],
          'delivery_mode': body['delivery_mode'],
          'total_amount': rows.fold<int>(
            0,
            (n, l) =>
                n + (l['quantity_packs'] as int) * (l['unit_price'] as int),
          ),
          'review_hash': hash,
          'observed_at': '2026-10-29T03:00:00Z',
          'lines': rows,
        },
      });
    }
    if (r.method == 'PUT' && path.endsWith('/orders/O9')) {
      final body = jsonDecode(r.body) as Map<String, dynamic>;
      amends.add(body);
      if (!allowed || deniedCreate) {
        return reply({'message': 'Denied'}, 403);
      }
      final operation = body['operation_id'] as String;
      if (memo.contains(operation)) {
        return reply({
          'data': {'id': 'O9'},
        });
      }
      if (body['version'] != version ||
          body['review_hash'] != hash ||
          !['awaiting_approval', 'rejected'].contains(status)) {
        return reply({'message': 'Versi atau harga berubah'}, 409);
      }
      memo.add(operation);
      amendCount++;
      version++;
      stored = body;
      snapshot = quoteLines(body);
      status = 'awaiting_approval';
      if (loseResponse) {
        loseResponse = false;
        throw http.ClientException('Lost after commit');
      }
      return reply({
        'data': {'id': 'O9'},
      });
    }
    return super.handle(r);
  }
}

void main() {
  OrderEntryController modelFor(AmendServer s) {
    addTearDown(s.api.close);
    final m = OrderEntryController(s.repository, 'S1', orderId: 'O9');
    addTearDown(m.dispose);
    return m;
  }

  test(
    'Existing lines prefill from exact IDs then review and PUT preserve original order identity',
    () async {
      final s = AmendServer();
      final m = modelFor(s);
      expect(await m.load(), true);
      expect(m.cart.single.quantity, '20');
      expect(m.cart.single.localId, 'L1');
      expect(m.note, 'Awal');
      m.quantity(m.cart.single, '10');
      expect(await m.review(), true);
      expect(m.preview!.total, 180000);
      expect(s.amendCount, 0);
      expect(s.amendPreviews.single['version'], 1);
      expect(await m.confirm(), true);
      expect(m.createdId, 'O9');
      expect(s.amends.single['version'], 1);
      expect(s.version, 2);
      expect(s.status, 'awaiting_approval');
      expect(s.creates, isEmpty);
      await m.confirm();
      expect(s.amendCount, 1);
    },
  );
  test(
    'Version change never silently rebases typed intent; explicit restart replaces draft and note',
    () async {
      final s = AmendServer();
      final m = modelFor(s);
      await m.load();
      m.quantity(m.cart.single, '30');
      s.externalChange();
      expect(await m.load(), false);
      expect(m.orderChanged, true);
      expect(m.fresh, false);
      expect(m.cart.single.quantity, '30');
      expect(await m.review(), false);
      expect(s.amendPreviews, isEmpty);
      expect(await m.restartFromServer(), true);
      expect(m.cart.single.quantity, '3');
      expect(m.note, 'Perubahan pengguna lain');
      expect(m.compositionEpoch, 2);
      expect(m.orderChanged, false);
      await m.review();
      expect(s.amendPreviews.single['version'], 2);
      await m.confirm();
      expect(s.version, 3);
    },
  );
  test(
    'Price conflict at unchanged order version requires another server review',
    () async {
      final s = AmendServer();
      final m = modelFor(s);
      await m.load();
      m.quantity(m.cart.single, '10');
      await m.review();
      s.changed = true;
      expect(await m.confirm(), false);
      expect(m.pending, isNull);
      expect(m.preview, isNull);
      expect(s.amendCount, 0);
      await m.load();
      expect(m.cart.single.quantity, '10');
      await m.review();
      expect(m.preview!.total, 190000);
      await m.confirm();
      expect(s.amendCount, 1);
    },
  );
  test(
    'Lost amendment response keeps exact nested payload and original version after later approval',
    () async {
      final s = AmendServer()..loseResponse = true;
      final m = modelFor(s);
      await m.load();
      m.quantity(m.cart.single, '10');
      await m.review();
      expect(await m.confirm(), false);
      final original = jsonDecode(jsonEncode(s.amends.single));
      (m.pending!.body['lines'] as List).first['quantity_packs'] = 999;
      s.status = 'approved';
      await m.load();
      expect(m.pending, isNotNull);
      expect(await m.restartFromServer(), false);
      expect(await m.retry(), true);
      expect(s.amends.last, original);
      expect(s.amendCount, 1);
      expect(s.version, 2);
      expect(m.createdId, 'O9');
    },
  );
  test(
    'Denied role or wrong store never loads commercial choices or posts an edit',
    () async {
      final s = AmendServer()..allowed = false;
      final m = modelFor(s);
      expect(await m.load(), false);
      expect(s.catalogueReads, 0);
      s.allowed = true;
      s.seedStore = 'OTHER';
      expect(await m.load(), false);
      expect(s.catalogueReads, 0);
      expect(s.amends, isEmpty);
      expect(s.amendPreviews, isEmpty);
    },
  );
  test(
    'Unknown followed by denial retains same amendment UUID until permission returns',
    () async {
      final s = AmendServer()..loseResponse = true;
      final m = modelFor(s);
      await m.load();
      await m.review();
      await m.confirm();
      s.deniedCreate = true;
      expect(await m.retry(), false);
      expect(m.pending, isNotNull);
      expect(s.amends.first, s.amends.last);
      s.deniedCreate = false;
      expect(await m.retry(), true);
      expect(s.amendCount, 1);
    },
  );
  for (final setting in [(390.0, 1.5), (1200.0, 1.0)]) {
    testWidgets('Native amendment prefill and reviewed save at ${setting.$1}', (
      tester,
    ) async {
      tester.view.physicalSize = Size(setting.$1, 1200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final s = AmendServer();
      addTearDown(s.api.close);
      await tester.pumpWidget(
        MaterialApp(
          builder: (context, child) => MediaQuery(
            data: MediaQuery.of(
              context,
            ).copyWith(textScaler: TextScaler.linear(setting.$2)),
            child: child!,
          ),
          home: OrderDetailPage(repository: OrdersRepository(s.api), id: 'O9'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Ubah pengajuan'));
      await tester.pumpAndSettle();
      Finder quantity() => find
          .descendant(
            of: find.byType(OrderLineEditor),
            matching: find.byType(TextFormField),
          )
          .last;
      expect(tester.widget<TextFormField>(quantity()).initialValue, '20');
      if (setting.$1 < 500) {
        await tester.enterText(quantity(), '30');
        s.externalChange();
        await tester.tap(find.byTooltip('Muat ulang katalog pengajuan'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Mulai ulang dari versi terbaru'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Muat versi terbaru'));
        await tester.pumpAndSettle();
        expect(tester.widget<TextFormField>(quantity()).initialValue, '3');
      }
      await tester.enterText(quantity(), '5');
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
      expect(find.text('Total Rp 90.000'), findsOneWidget);
      expect(s.amendCount, 0);
      await tester.ensureVisible(find.text('Konfirmasi perubahan'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Konfirmasi perubahan'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Lihat detail pengajuan'));
      await tester.pumpAndSettle();
      expect(s.amendCount, 1);
      expect(s.version, setting.$1 < 500 ? 3 : 2);
      expect(find.textContaining('5 kemasan × 8 biji'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
