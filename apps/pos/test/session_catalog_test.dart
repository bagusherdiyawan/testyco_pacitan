import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/identity/application/session.dart';
import 'package:testyco_pos/main.dart';

void main() {
  test(
    'Token is sent only after login and cleared on unauthorized response',
    () async {
      final requests = <http.Request>[];
      final api = ApiClient(
        baseUrl: Uri.parse('https://api.example.test/api/v1/'),
        client: MockClient((r) async {
          requests.add(r);
          if (r.url.path.endsWith('auth/login')) {
            return http.Response(
              jsonEncode({
                'token': 'secret',
                'user': {'name': 'Kasir'},
              }),
              200,
            );
          }
          return http.Response('{}', 401);
        }),
      );
      final session = Session(api);
      addTearDown(session.dispose);
      await session.login('user@example.test', 'Password123456');
      expect(session.authenticated, isTrue);
      expect(requests.first.headers.containsKey('authorization'), isFalse);
      await expectLater(
        api.request('GET', 'stores'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 401)),
      );
      expect(requests.last.headers['authorization'], 'Bearer secret');
      expect(session.authenticated, isFalse);
    },
  );
  test(
    'Logout clears local credentials even if server is unavailable',
    () async {
      final api = ApiClient(
        baseUrl: Uri.parse('https://api.example.test/api/v1/'),
        client: MockClient((r) async {
          if (r.url.path.endsWith('auth/login')) {
            return http.Response(
              '{"token":"secret","user":{"name":"Kasir"}}',
              200,
            );
          }
          throw http.ClientException('network');
        }),
      );
      final session = Session(api);
      addTearDown(session.dispose);
      await session.login('a@example.test', 'Password123456');
      await expectLater(session.logout(), throwsA(isA<ApiException>()));
      expect(session.authenticated, isFalse);
    },
  );
  for (final width in [390.0, 1024.0]) {
    testWidgets('Login, select assigned store, search API catalog at $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 1000);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = ApiClient(
        baseUrl: Uri.parse('https://api.example.test/api/v1/'),
        client: MockClient((r) async {
          if (r.url.path.endsWith('/auth/login')) {
            return http.Response(
              '{"token":"secret","user":{"name":"Kasir"}}',
              200,
            );
          }
          expect(r.headers['authorization'], 'Bearer secret');
          if (r.url.path.endsWith('/stores')) {
            return http.Response(
              jsonEncode({
                'data': [
                  {'id': 'store-a', 'name': 'Pacitan A1', 'active': true},
                ],
                'next_page_url': null,
              }),
              200,
            );
          }
          if (r.url.path.endsWith('/catalog')) {
            return http.Response(
              jsonEncode({
                'data': [
                  {
                    'id': 'sku1',
                    'sku': 'AYAM-8',
                    'name': 'Dimsum Ayam',
                    'condition': 'frozen',
                    'pack_size': 8,
                    'portion_size': 4,
                    'retail_price': 3000,
                  },
                ],
                'next_page_url': null,
              }),
              200,
            );
          }
          return http.Response('{}', 204);
        }),
      );
      final session = Session(api);
      addTearDown(session.dispose);
      await tester.pumpWidget(TestycoApp(session: session));
      await tester.enterText(
        find.byType(TextFormField).first,
        'kasir@example.test',
      );
      await tester.enterText(find.byType(TextFormField).last, 'Password123456');
      await tester.tap(find.text('Masuk'));
      await tester.pumpAndSettle();
      expect(find.text('Pacitan A1'), findsOneWidget);
      await tester.tap(find.text('Pacitan A1'));
      await tester.pumpAndSettle();
      expect(find.text('Dimsum Ayam'), findsOneWidget);
      expect(find.text('Rp 3000 / biji'), findsOneWidget);
      await tester.enterText(find.byType(TextField), 'tidakada');
      await tester.pumpAndSettle();
      expect(find.text('Produk tidak ditemukan.'), findsOneWidget);
      await tester.tap(find.byTooltip('Keluar'));
      await tester.pumpAndSettle();
      expect(find.text('Masuk'), findsOneWidget);
      expect(session.authenticated, isFalse);
      expect(tester.takeException(), isNull);
    });
  }
  testWidgets('Catalog failure offers retry instead of fake products', (
    tester,
  ) async {
    var failed = true;
    final session = Session(
      ApiClient(
        baseUrl: Uri.parse('https://api.example.test/api/v1/'),
        client: MockClient((r) async {
          if (r.url.path.endsWith('auth/login')) {
            return http.Response(
              '{"token":"secret","user":{"name":"Kasir"}}',
              200,
            );
          }
          if (r.url.path.endsWith('stores')) {
            return http.Response(
              '{"data":[{"id":"s1","name":"Toko satu","active":true}]}',
              200,
            );
          }
          if (failed) {
            return http.Response('{"message":"Layanan belum tersedia"}', 503);
          }
          return http.Response('{"data":[]}', 200);
        }),
      ),
    );
    addTearDown(session.dispose);
    await session.login('a@example.test', 'Password123456');
    await tester.pumpWidget(TestycoApp(session: session));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Toko satu'));
    await tester.pumpAndSettle();
    expect(find.text('Coba kembali'), findsOneWidget);
    failed = false;
    await tester.tap(find.text('Coba kembali'));
    await tester.pumpAndSettle();
    expect(find.text('Belum ada produk aktif.'), findsOneWidget);
  });
}
