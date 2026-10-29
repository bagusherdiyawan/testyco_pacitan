import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/shifts/application/cash_movement_controller.dart';
import 'package:testyco_pos/features/shifts/data/shifts_repository.dart';
import 'package:testyco_pos/features/shifts/domain/cash_shift.dart';
import 'package:testyco_pos/features/shifts/presentation/shifts_page.dart';

class ShiftServer {
  Map<String, dynamic> shift = {
    'id': 'SH1',
    'store_id': 'S1',
    'cashier_name': 'Kasir A',
    'device_name': 'Tablet',
    'status': 'open',
    'version': 1,
    'opening_cash': 100000,
    'cash_sales': 200000,
    'cash_in': 0,
    'cash_out': 0,
    'expected_cash': 300000,
    'can_record_movement': true,
    'opened_at': '2026-10-29T01:00:00Z',
  };
  final movements = <Map<String, dynamic>>[],
      requests = <Map<String, dynamic>>[];
  final memo = <String, Map<String, dynamic>>{};
  final pages = <int>[];
  bool loseResponse = false, failRead = false, deny = false;
  int writes = 0;
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example/api/v1/'),
    client: MockClient(handle),
  );
  late final repository = ShiftsRepository(api);
  http.Response reply(Map<String, dynamic> v, [int status = 200]) =>
      http.Response(
        jsonEncode(v),
        status,
        headers: {'content-type': 'application/json'},
      );
  Future<http.Response> handle(http.Request r) async {
    if (r.method == 'GET' && r.url.path.endsWith('/stores/S1/shifts')) {
      final page = int.parse(r.url.queryParameters['page'] ?? '1');
      pages.add(page);
      return reply({
        'data': [shift],
        'current_page': page,
        'last_page': 2,
      });
    }
    if (r.method == 'GET' && r.url.path.endsWith('/shifts/SH1')) {
      if (failRead) return reply({'message': 'Unavailable'}, 503);
      return reply({
        'data': {...shift, 'movements': movements},
      });
    }
    if (r.method == 'POST' &&
        r.url.path.endsWith('/shifts/SH1/cash-movements')) {
      final v = jsonDecode(r.body) as Map<String, dynamic>;
      requests.add(v);
      if (deny) return reply({'message': 'Denied'}, 403);
      final operation = v['operation_id'] as String;
      if (memo.containsKey(operation)) {
        return reply({'data': memo[operation]}, 201);
      }
      if (v['version'] != shift['version'] || shift['status'] != 'open') {
        return reply({'message': 'Kas berubah'}, 409);
      }
      writes++;
      final amount = v['amount'] as int;
      final kind = v['kind'] as String;
      shift['version'] = (shift['version'] as int) + 1;
      shift['expected_cash'] =
          (shift['expected_cash'] as int) + (kind == 'in' ? amount : -amount);
      final field = kind == 'in' ? 'cash_in' : 'cash_out';
      shift[field] = (shift[field] as int) + amount;
      final movement = {
        'id': 'M$writes',
        'actor_id': 3,
        'created_at': '2026-10-29T02:00:00Z',
        ...v,
      };
      movements.add(movement);
      memo[operation] = {
        'shift': Map<String, dynamic>.from(shift),
        'movement': movement,
      };
      if (loseResponse) {
        loseResponse = false;
        throw http.ClientException('Response lost after commit');
      }
      return reply({'data': memo[operation]}, 201);
    }
    throw StateError('Unexpected ${r.method} ${r.url}');
  }
}

void main() {
  CashMovementController modelFor(ShiftServer server, {String store = 'S1'}) {
    addTearDown(server.api.close);
    final m = CashMovementController(server.repository, store, 'SH1');
    addTearDown(m.dispose);
    return m;
  }

  CashMovementDraft draft(CashMovementController m) =>
      m.prepare('out', '20000', 'Operasional', 'Kas fisik keluar');
  test(
    'Preview is read only; confirmed cash out changes drawer, never sales; next detail is current',
    () async {
      final s = ShiftServer();
      final m = modelFor(s);
      await m.load();
      final d = draft(m);
      expect(s.requests, isEmpty);
      expect(d.body['version'], 1);
      await m.confirm(d);
      expect(s.writes, 1);
      expect(m.shift!.expected, 280000);
      expect(m.shift!.sales, 200000);
      expect(m.shift!.movements.single['id'], 'M1');
      expect(m.savedMovement!['id'], 'M1');
      expect(m.editable, true);
      expect(m.pending, isNull);
    },
  );
  test(
    'Lost response preserves exact UUID/body/path, blocks refresh/new movement and replays even after close',
    () async {
      final s = ShiftServer()..loseResponse = true;
      final m = modelFor(s);
      await m.load();
      await m.confirm(draft(m));
      final original = jsonDecode(jsonEncode(s.requests.single));
      m.pending!.body['amount'] = 999;
      expect(m.editable, false);
      final epoch = m.formEpoch;
      s.shift.addAll({
        'status': 'closed',
        'version': 3,
        'can_record_movement': false,
      });
      await m.load();
      expect(m.formEpoch, epoch);
      expect(() => draft(m), throwsA(isA<ApiException>()));
      await m.retry();
      expect(s.requests.last, original);
      expect(s.writes, 1);
      expect(m.shift!.status, 'closed');
      expect(m.shift!.version, 3);
      expect(m.pending, isNull);
    },
  );
  test('Later denial cannot clear an earlier unknown operation', () async {
    final s = ShiftServer()..loseResponse = true;
    final m = modelFor(s);
    await m.load();
    await m.confirm(draft(m));
    s.deny = true;
    await m.retry();
    expect(m.pending, isNotNull);
    expect(m.editable, false);
    s.deny = false;
    await m.retry();
    expect(s.requests.first, s.requests.last);
    expect(s.writes, 1);
    expect(m.pending, isNull);
  });
  test(
    'Stale cash is not silently rebased; reload and new review required',
    () async {
      final s = ShiftServer();
      final m = modelFor(s);
      await m.load();
      final old = draft(m);
      s.shift['version'] = 2;
      s.shift['expected_cash'] = 310000;
      await m.confirm(old);
      expect(s.writes, 0);
      expect(m.pending, isNull);
      expect(m.fresh, false);
      expect(() => draft(m), throwsA(isA<ApiException>()));
      await m.load();
      await m.confirm(old);
      expect(s.requests.length, 1);
      final next = draft(m);
      expect(next.body['version'], 2);
      expect(next.body['operation_id'], isNot(old.body['operation_id']));
      await m.confirm(next);
      expect(m.shift!.expected, 290000);
    },
  );
  test(
    'Acknowledged write followed by failed refresh never prepares duplicate operation',
    () async {
      final s = ShiftServer();
      final m = modelFor(s);
      await m.load();
      final d = draft(m);
      s.failRead = true;
      await m.confirm(d);
      expect(m.savedMovement!['id'], 'M1');
      expect(m.pending, isNull);
      expect(m.fresh, false);
      expect(m.error, contains('Kas tersimpan'));
      await m.confirm(d);
      await m.retry();
      expect(s.writes, 1);
      s.failRead = false;
      await m.load();
      expect(m.shift!.movements.length, 1);
      expect(m.editable, true);
    },
  );
  test(
    'Missing capability, closed, wrong store and read failure never enable mutations',
    () async {
      final s = ShiftServer();
      final m = modelFor(s);
      s.shift.remove('can_record_movement');
      await m.load();
      expect(m.editable, false);
      s.shift.addAll({'can_manage': true});
      await m.load();
      expect(m.editable, false);
      s.shift.addAll({'can_record_movement': true, 'status': 'closed'});
      await m.load();
      expect(m.editable, false);
      s.shift.addAll({'status': 'open', 'store_id': 'OTHER'});
      await m.load();
      expect(m.fresh, false);
      s.shift['store_id'] = 'S1';
      s.failRead = true;
      await m.load();
      expect(m.editable, false);
      expect(s.requests, isEmpty);
    },
  );
  test(
    'Money, required reason, category and overdraw are validated before POST',
    () async {
      final s = ShiftServer();
      final m = modelFor(s);
      await m.load();
      for (final v in ['0', '-1', '1.5', '1000000000001', '300001', '']) {
        expect(
          () => m.prepare('out', v, 'Kas', 'Alasan'),
          throwsA(isA<ApiException>()),
        );
      }
      expect(
        () => m.prepare('in', '1', ' ', 'Alasan'),
        throwsA(isA<ApiException>()),
      );
      expect(
        () => m.prepare('in', '1', 'Kas', ' '),
        throwsA(isA<ApiException>()),
      );
      expect(
        () => m.prepare('in', '1', 'A' * 81, 'Alasan'),
        throwsA(isA<ApiException>()),
      );
      expect(s.requests, isEmpty);
    },
  );
  for (final size in [(390.0, 1.5), (1200.0, 1.0)]) {
    testWidgets(
      'Shift list, pagination, review and cash history at ${size.$1}',
      (tester) async {
        tester.view.physicalSize = Size(size.$1, 1500);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final s = ShiftServer();
        addTearDown(s.api.close);
        await tester.pumpWidget(
          MaterialApp(
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(
                context,
              ).copyWith(textScaler: TextScaler.linear(size.$2)),
              child: child!,
            ),
            home: Scaffold(
              body: ShiftsPage(repository: s.repository, storeId: 'S1'),
            ),
          ),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Berikutnya'));
        await tester.pumpAndSettle();
        expect(s.pages, [1, 2]);
        await tester.tap(find.text('Kasir A · Tablet'));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.widgetWithText(TextField, 'Nilai kas (Rp)'),
          '20000',
        );
        await tester.enterText(
          find.widgetWithText(TextField, 'Kategori'),
          'Operasional',
        );
        await tester.enterText(
          find.widgetWithText(TextField, 'Alasan'),
          'Kas fisik keluar',
        );
        await tester.ensureVisible(find.text('Tinjau catatan kas'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Tinjau catatan kas'));
        await tester.pumpAndSettle();
        expect(find.text('Kas keluar Rp 20.000'), findsOneWidget);
        expect(s.requests, isEmpty);
        await tester.tap(find.text('Konfirmasi pencatatan'));
        await tester.pumpAndSettle();
        expect(s.writes, 1);
        expect(find.text('Kas diharapkan Rp 280.000'), findsOneWidget);
        await tester.drag(
          find.byKey(const Key('shift-detail-scroll')),
          const Offset(0, 1200),
        );
        await tester.pumpAndSettle();
        expect(find.text('Catatan kas tersimpan · M1'), findsOneWidget);
        expect(tester.takeException(), isNull);
      },
    );
  }
}
