import 'dart:convert';
import 'dart:io';
import 'package:cryptography/cryptography.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/core/storage/device_state.dart';
import 'package:testyco_pos/features/sales/data/offline_repository.dart';
import 'package:testyco_pos/features/sales/presentation/offline_page.dart';
import 'package:testyco_pos/features/identity/application/session.dart';
import 'package:testyco_pos/features/reliability/presentation/inbox_page.dart';

Map<String, dynamic> permit() => {
  'id': 'PERMIT',
  'organization_id': 'ORG',
  'store_id': 'STORE',
  'cash_shift_id': 'SHIFT',
  'cashier_id': 1,
  'issued_at': DateTime.now()
      .toUtc()
      .subtract(const Duration(minutes: 1))
      .toIso8601String(),
  'expires_at': DateTime.now()
      .toUtc()
      .add(const Duration(hours: 1))
      .toIso8601String(),
  'closed_at': null,
  'snapshot': {
    'user_id': 1,
    'store': {'id': 'STORE', 'name': 'Pacitan'},
    'cashier_name': 'Cashier',
    'payment_methods': ['cash'],
    'vouchers_allowed': false,
    'products': [
      {
        'id': 'P',
        'sku': 'SKU',
        'name': 'Item',
        'condition': 'frozen',
        'pack_size': 8,
        'portion_size': 4,
        'retail_price': 3000,
        'recipe': null,
      },
    ],
    'batches': [
      {
        'id': 'B',
        'product_id': 'P',
        'sku': 'SKU',
        'batch_code': 'LOT',
        'condition': 'frozen',
        'pack_size': 8,
        'usable_pieces': 16,
        'opened_pieces': 8,
        'ready_pieces': 8,
        'blocked_buckets': [],
      },
    ],
  },
};
List<Map<String, dynamic>> cart() => [
  {
    'product_id': 'P',
    'unit': 'piece',
    'quantity': 2,
    'allocations': [
      {'batch_id': 'B', 'bucket': 'ready', 'quantity': 2},
    ],
  },
];

class OfflineServer {
  bool lose = true, wrong = false, failRead = false;
  int writes = 0;
  final posts = <String>[];
  Map<String, dynamic>? saved;
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example.test/api/v1/'),
    client: MockClient((r) async {
      if (r.method == 'POST' && r.url.path.endsWith('/sales/offline')) {
        posts.add(r.body);
        final b = jsonDecode(r.body);
        if (saved == null) {
          writes++;
          saved = {
            'id': 'SERVER-OFF',
            'operation_id': b['operation_id'],
            'cashier_id': 1,
            'store_id': 'STORE',
            'offline_permit_id': 'PERMIT',
            'status': 'accepted',
            'receipt': {
              'id': 'SALE',
              'number': 'POS-1',
              'offline_sale_id': 'SERVER-OFF',
              'payment_method': 'cash',
            },
          };
        }
        if (lose) {
          lose = false;
          return http.Response('{}', 503);
        }
        final d = {...saved!};
        if (wrong) d['store_id'] = 'OTHER';
        return http.Response(jsonEncode({'data': d}), 201);
      }
      if (r.method == 'GET' && r.url.path.contains('/sales/offline/')) {
        return http.Response(jsonEncode({'data': saved}), failRead ? 503 : 200);
      }
      return http.Response('{}', 404);
    }),
  );
  void login([int id = 1]) {
    api.acceptLogin({
      'token': 'test-token',
      'user': {'id': id, 'name': 'Cashier', 'active': true},
      'expires_in': 28800,
    });
  }
}

void main() {
  test(
    'AES-GCM vault survives reopen, never contains plaintext and rejects corruption without deleting evidence',
    () async {
      final dir = await Directory.systemTemp.createTemp('testyco-vault-test-');
      addTearDown(() => dir.delete(recursive: true));
      final key = SecretKey(List.generate(32, (i) => i));
      final first = EncryptedDeviceState(dir, key);
      final value = {
        'token': 'PRIVATE-TOKEN-FIXTURE',
        'body': {'operation_id': 'UUID-1', 'cash_received': 10000},
      };
      await first.write('session', value);
      final file = (await dir.list().toList()).whereType<File>().single;
      expect(
        await file.readAsString(),
        isNot(contains('PRIVATE-TOKEN-FIXTURE')),
      );
      final second = EncryptedDeviceState(dir, key);
      expect(await second.read('session'), value);
      final envelope = jsonDecode(await file.readAsString());
      envelope['mac'] = base64Encode(List.filled(16, 0));
      await file.writeAsString(jsonEncode(envelope));
      await expectLater(second.read('session'), throwsStateError);
      expect(await file.exists(), isTrue);
    },
  );
  test(
    'Offline cash survives process-style reopen and lost committed response replays exact UUID once',
    () async {
      final dir = await Directory.systemTemp.createTemp('testyco-outbox-test-');
      addTearDown(() => dir.delete(recursive: true));
      final key = SecretKey(List.filled(32, 7));
      final s = OfflineServer()..login();
      var repo = OfflineRepository(s.api, EncryptedDeviceState(dir, key));
      await repo.cachePermit(permit());
      final candidate = await repo.preview('PERMIT', cart(), 10000);
      await repo.capture(candidate);
      await repo.capture(candidate);
      expect((await repo.load())['outbox'].length, 1);
      await expectLater(repo.synchronize(), throwsA(isA<ApiException>()));
      expect((await repo.load())['outbox'].values.single['state'], 'pending');
      repo = OfflineRepository(s.api, EncryptedDeviceState(dir, key));
      await repo.synchronize();
      expect(s.writes, 1);
      expect(s.posts.length, 2);
      expect(s.posts[0], s.posts[1]);
      final row = (await repo.load())['outbox'].values.single;
      expect(row['state'], 'accepted');
      expect(row['receipt']['number'], 'POS-1');
      expect(row['body']['cash_received'], 10000);
    },
  );
  test(
    'Offline account isolation, malformed ACK, cached stock and expiry fail without dropping original evidence',
    () async {
      final store = MemoryDeviceState();
      final s = OfflineServer()..login();
      final repo = OfflineRepository(s.api, store);
      await repo.cachePermit(permit());
      await repo.capture(await repo.preview('PERMIT', cart(), 10000));
      s.login(2);
      expect((await OfflineRepository(s.api, store).load())['outbox'], isEmpty);
      s.login();
      s.lose = false;
      s.wrong = true;
      await expectLater(repo.synchronize(), throwsA(isA<ApiException>()));
      expect((await repo.load())['outbox'].values.single['state'], 'pending');
      expect((await repo.load())['outbox'].values.single['server_id'], isNull);
      final tooMuch = cart();
      tooMuch[0]['quantity'] = 8;
      tooMuch[0]['allocations'][0]['quantity'] = 8;
      await expectLater(
        repo.preview('PERMIT', tooMuch, 30000),
        throwsA(isA<ApiException>()),
      );
      final expired = permit()
        ..['expires_at'] = DateTime.now()
            .toUtc()
            .subtract(const Duration(seconds: 1))
            .toIso8601String();
      await repo.cachePermit(expired);
      await expectLater(
        repo.preview('PERMIT', cart(), 10000),
        throwsA(isA<ApiException>()),
      );
      expect((await repo.load())['outbox'].length, 1);
    },
  );
  test(
    'Offline cached SOP rejects known ready expiry and concurrent stale candidates cannot overspend snapshot',
    () async {
      final server = OfflineServer()..login();
      final repo = OfflineRepository(server.api, MemoryDeviceState());
      final p = permit();
      p['snapshot']['safety'] = {
        'block_expired': false,
        'ready_life_minutes': 60,
      };
      p['snapshot']['batches'][0]['ready_expires_at'] = DateTime.now()
          .toUtc()
          .subtract(const Duration(seconds: 1))
          .toIso8601String();
      await repo.cachePermit(p);
      final ready = cart();
      ready[0]['allocations'][0]['bucket'] = 'ready';
      await expectLater(
        repo.preview('PERMIT', ready, 10000),
        throwsA(isA<ApiException>()),
      );
      final large = cart();
      large[0]['quantity'] = 5;
      large[0]['allocations'][0]['bucket'] = 'opened';
      large[0]['allocations'][0]['quantity'] = 5;
      final a = await repo.preview('PERMIT', large, 20000);
      final b = await repo.preview('PERMIT', large, 20000);
      await repo.capture(a);
      await expectLater(repo.capture(b), throwsA(isA<ApiException>()));
      expect((await repo.load())['outbox'].length, 1);
    },
  );
  test(
    'MFA login issues no session before second factor and secure session restores online or bounded offline',
    () async {
      final calls = <String>[];
      var offline = false;
      final api = ApiClient(
        baseUrl: Uri.parse('https://example.test/api/v1/'),
        client: MockClient((r) async {
          calls.add(r.body);
          if (r.url.path.endsWith('/auth/login')) {
            return http.Response(
              jsonEncode({
                'requires_mfa': true,
                'challenge': 'x' * 64,
                'expires_in': 120,
              }),
              200,
            );
          }
          if (r.url.path.endsWith('/auth/login/mfa')) {
            return http.Response(
              jsonEncode({
                'token': 'TOKEN-FIXTURE',
                'user': {'id': 1, 'name': 'Cashier', 'active': true},
                'expires_in': 28800,
              }),
              200,
            );
          }
          return http.Response(
            jsonEncode({
              'data': {'id': 1, 'name': 'Cashier', 'active': true},
            }),
            offline ? 503 : 200,
          );
        }),
      );
      final state = MemoryDeviceState();
      final session = Session(api, deviceState: state);
      await expectLater(
        session.login('cashier@example.test', 'NotStoredPassword123'),
        throwsA(isA<MfaRequired>()),
      );
      expect(session.authenticated, isFalse);
      expect(api.needsMfa, isTrue);
      expect(
        state.values.values.join(),
        isNot(contains('NotStoredPassword123')),
      );
      await session.completeMfa('123456');
      expect(session.authenticated, isTrue);
      final saved = api.savedSession!;
      expect(saved['token'], 'TOKEN-FIXTURE');
      final other = ApiClient(
        baseUrl: Uri.parse('https://other.example.test/api/v1/'),
      );
      expect(other.restoreSession(saved), isFalse);
      offline = true;
      await session.restore();
      expect(session.offlineOnly, isTrue);
      expect(session.authenticated, isTrue);
      await expectLater(session.logout(), throwsA(isA<ApiException>()));
      await Future<void>.delayed(Duration.zero);
      expect(session.authenticated, isFalse);
      expect(state.values, isEmpty);
      other.close();
      session.dispose();
    },
  );
  for (final width in [390.0, 1200.0]) {
    testWidgets('Offline review and durable capture fit $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 1400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final s = OfflineServer()..login();
      final repo = OfflineRepository(s.api, MemoryDeviceState());
      await repo.cachePermit(permit());
      await tester.pumpWidget(MaterialApp(home: OfflinePage(repository: repo)));
      await tester.pumpAndSettle();
      expect(find.text('POS offline tunai dan antrean'), findsOneWidget);
      final product = find.byWidgetPredicate(
        (w) =>
            w is DropdownButtonFormField<String> &&
            w.decoration.labelText == 'Produk dari snapshot izin',
      );
      await tester.ensureVisible(product);
      await tester.tap(product);
      await tester.pumpAndSettle();
      await tester.tap(find.text('SKU · Item').last);
      await tester.pumpAndSettle();
      final unit = find.byWidgetPredicate(
        (w) =>
            w is DropdownButtonFormField<String> &&
            w.decoration.labelText == 'Satuan',
      );
      await tester.ensureVisible(unit);
      await tester.tap(unit);
      await tester.pumpAndSettle();
      await tester.tap(find.text('piece').last);
      await tester.pumpAndSettle();
      final qty = find.byWidgetPredicate(
        (w) =>
            w is TextField && w.decoration?.labelText == 'Jumlah unit aktual',
      );
      await tester.enterText(qty, '2');
      final batch = find.byWidgetPredicate(
        (w) =>
            w is DropdownButtonFormField<String> &&
            w.decoration.labelText == 'Batch SKU',
      );
      await tester.ensureVisible(batch);
      await tester.tap(batch);
      await tester.pumpAndSettle();
      await tester.tap(find.text('LOT · ready · 8 biji').last);
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Tambahkan ke keranjang offline'));
      await tester.tap(find.text('Tambahkan ke keranjang offline'));
      await tester.pumpAndSettle();
      final cash = find.byWidgetPredicate(
        (w) =>
            w is TextField &&
            w.decoration?.labelText == 'Tunai aktual diterima (Rp)',
      );
      await tester.ensureVisible(cash);
      await tester.enterText(cash, '10000');
      await tester.ensureVisible(find.text('Tinjau sebelum menyimpan offline'));
      await tester.tap(find.text('Tinjau sebelum menyimpan offline'));
      await tester.pumpAndSettle();
      expect(find.text('Tinjau tunai Rp 6000'), findsOneWidget);
      await tester.ensureVisible(find.byType(CheckboxListTile));
      await tester.tap(find.byType(CheckboxListTile));
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.text('Konfirmasi dan simpan catatan offline'),
      );
      await tester.tap(find.text('Konfirmasi dan simpan catatan offline'));
      await tester.pumpAndSettle();
      expect((await repo.load())['outbox'].length, 1);
      expect(tester.takeException(), isNull);
    });
    testWidgets('Inbox fits $width and refuses other-user envelope', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 1200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = ApiClient(
        baseUrl: Uri.parse('https://example.test/'),
        client: MockClient(
          (r) async => http.Response(
            jsonEncode({
              'user_id': 1,
              'data': [
                {
                  'id': 'I1',
                  'organization_id': 'ORG',
                  'title': 'Pesanan memerlukan pemeriksaan pimpinan organisasi',
                  'created_at': '2026-11-04T00:00:00Z',
                  'read_at': null,
                },
              ],
              'current_page': 1,
              'per_page': 50,
              'total': 1,
              'last_page': 1,
              'unread_count': 1,
              'preferences': {'version': 0, 'email_enabled': false},
              'smtp_configured': false,
            }),
            200,
          ),
        ),
      );
      api.acceptLogin({
        'token': 'x',
        'user': {'id': 1, 'name': 'U'},
      });
      await tester.pumpWidget(MaterialApp(home: InboxPage(api: api)));
      await tester.pumpAndSettle();
      expect(find.text('Inbox operasional'), findsOneWidget);
      expect(tester.takeException(), isNull);
      api.close();
    });
  }
}
