import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/core/network/paged_records.dart';
import 'package:testyco_pos/features/inventory/data/inventory_repository.dart';
import 'package:testyco_pos/features/inventory/presentation/inventory_page.dart';
import 'package:testyco_pos/features/ordering/data/orders_repository.dart';
import 'package:testyco_pos/features/ordering/presentation/orders_page.dart';

void main() {
  for (final width in [390.0, 1024.0]) {
    testWidgets('Live stock view separates good and quarantine at $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = ApiClient(
        baseUrl: Uri.parse('https://api.example.test/api/v1/'),
        client: MockClient((r) async {
          expect(r.url.path, '/api/v1/stores/S1/inventory/batches');
          return http.Response(
            jsonEncode({
              'data': [
                {
                  'sku': 'AYAM-8',
                  'name': 'Ayam',
                  'batch_code': 'B-001',
                  'expires_on': '2030-01-01',
                  'pack_size': 8,
                  'usable_pieces': 144,
                  'quarantined_pieces': 16,
                },
              ],
            }),
            200,
          );
        }),
      );
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: InventoryPage(
              repository: InventoryRepository(api),
              storeId: 'S1',
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Diterima baik: 144 biji'), findsOneWidget);
      expect(find.text('Karantina: 16 biji'), findsOneWidget);
      expect(find.text('Isi kemasan historis: 8 biji'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
    testWidgets(
      'Order detail shows historical units and receipt discrepancy at $width',
      (tester) async {
        tester.view.physicalSize = Size(width, 1000);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final api = ApiClient(
          baseUrl: Uri.parse('https://api.example.test/api/v1/'),
          client: MockClient((r) async {
            if (r.url.path.endsWith('/stores/S1/orders')) {
              return http.Response(
                jsonEncode({
                  'data': [
                    {
                      'id': 'O1',
                      'status': 'partially_received',
                      'total_amount': 360000,
                    },
                  ],
                }),
                200,
              );
            }
            if (r.url.path.endsWith('/shipments')) {
              return http.Response(
                jsonEncode({
                  'data': [
                    {
                      'received_at': '2026-10-28',
                      'lines': [
                        {
                          'batch_code': 'B-001',
                          'quantity_packs': 20,
                          'good_packs': 18,
                          'damaged_packs': 2,
                        },
                      ],
                    },
                  ],
                }),
                200,
              );
            }
            return http.Response(
              jsonEncode({
                'data': {
                  'id': 'O1',
                  'destination_name': 'Pacitan A1',
                  'status': 'partially_received',
                  'version': 5,
                  'total_amount': 360000,
                  'lines': [
                    {
                      'sku': 'AYAM-8',
                      'local_sku': 'LOKAL-8',
                      'quantity_packs': 20,
                      'pack_size': 8,
                      'unit_price': 18000,
                    },
                  ],
                },
              }),
              200,
            );
          }),
        );
        addTearDown(api.close);
        await tester.pumpWidget(
          MaterialApp(
            home: Scaffold(
              body: OrdersPage(
                repository: OrdersRepository(api),
                storeId: 'S1',
              ),
            ),
          ),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('O1'));
        await tester.pumpAndSettle();
        expect(find.text('Pacitan A1'), findsOneWidget);
        expect(find.textContaining('20 kemasan × 8 biji'), findsOneWidget);
        expect(find.textContaining('baik 18 · rusak 2'), findsOneWidget);
        expect(tester.takeException(), isNull);
      },
    );
  }
  testWidgets(
    'Inventory retry replaces failed future and displays real result',
    (tester) async {
      var failure = true;
      final api = ApiClient(
        baseUrl: Uri.parse('https://api.example.test/api/v1/'),
        client: MockClient(
          (_) async => failure
              ? http.Response('{}', 503)
              : http.Response('{"data":[]}', 200),
        ),
      );
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: InventoryPage(
              repository: InventoryRepository(api),
              storeId: 'S1',
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Layanan API sedang tidak tersedia.'), findsOneWidget);
      failure = false;
      await tester.tap(find.text('Coba kembali'));
      await tester.pumpAndSettle();
      expect(find.text('Belum ada penerimaan stok.'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );
  test(
    'Pagination uses configured API host and ignores external next_page_url',
    () async {
      final calls = <Uri>[];
      final api = ApiClient(
        baseUrl: Uri.parse('https://api.example.test/api/v1/'),
        client: MockClient((r) async {
          calls.add(r.url);
          return http.Response(
            jsonEncode({
              'data': [
                {'id': '${calls.length}'},
              ],
              'last_page': 2,
              'next_page_url': 'https://untrusted.example/steal',
            }),
            200,
          );
        }),
      );
      addTearDown(api.close);
      final rows = await PagedRecords(api).load('stores/S1/orders');
      expect(rows.length, 2);
      expect(calls.every((u) => u.host == 'api.example.test'), isTrue);
      expect(calls.last.queryParameters['page'], '2');
    },
  );
}
