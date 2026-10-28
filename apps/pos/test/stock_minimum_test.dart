import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/inventory/data/inventory_repository.dart';
import 'package:testyco_pos/features/inventory/presentation/stock_minimum_page.dart';

Map<String, dynamic> row({
  String sku = 'AYAM',
  int? minimum = 20,
  bool low = true,
}) => {
  'sku': sku,
  'name': 'Dimsum',
  'available_pieces': 12,
  'usable_pieces': 10,
  'opened_pieces': 2,
  'ready_pieces': 0,
  'minimum_pieces': minimum,
  'shortfall_pieces': low ? 8 : 0,
  'is_low': low,
};
void main() {
  for (final width in [390.0, 1200.0]) {
    testWidgets('Stock warnings show server quantities and refresh at $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      var reads = 0;
      final api = ApiClient(
        baseUrl: Uri.parse('https://api.example.test/api/v1/'),
        client: MockClient((r) async {
          expect(r.url.path, '/api/v1/stores/S1/inventory/minimums');
          expect(r.url.queryParameters['low_only'], '1');
          reads++;
          return http.Response(
            jsonEncode({
              'data': reads == 1 ? [row()] : [],
              'last_page': 1,
            }),
            200,
          );
        }),
      );
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: StockMinimumPage(
            repository: InventoryRepository(api),
            storeId: 'S1',
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Tersedia: 12 biji'), findsOneWidget);
      expect(find.text('Utuh 10 · Terbuka 2 · Siap 0'), findsOneWidget);
      expect(find.text('Stok rendah · Kekurangan 8 biji'), findsOneWidget);
      expect(find.text('Ambang: 20 biji'), findsOneWidget);
      await tester.tap(find.byTooltip('Muat ulang peringatan'));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('Tidak ada stok di bawah ambang aktif.'),
        findsOneWidget,
      );
      expect(find.text('Stok rendah · Kekurangan 8 biji'), findsNothing);
      expect(reads, 2);
      expect(tester.takeException(), isNull);
    });
  }
  testWidgets('Filter shows unconfigured SKU without claiming a warning', (
    tester,
  ) async {
    final api = ApiClient(
      baseUrl: Uri.parse('https://api.example.test/api/v1/'),
      client: MockClient((r) async {
        final all = r.url.queryParameters['low_only'] == '0';
        return http.Response(
          jsonEncode({
            'data': all ? [row(minimum: null, low: false)] : [],
          }),
          200,
        );
      }),
    );
    addTearDown(api.close);
    await tester.pumpWidget(
      MaterialApp(
        home: StockMinimumPage(
          repository: InventoryRepository(api),
          storeId: 'S1',
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byType(Switch));
    await tester.pumpAndSettle();
    expect(find.text('Ambang belum diaktifkan'), findsOneWidget);
    expect(find.textContaining('Kekurangan'), findsNothing);
  });
  testWidgets(
    'Network error offers retry and does not claim stock is healthy',
    (tester) async {
      var retry = false;
      final api = ApiClient(
        baseUrl: Uri.parse('https://api.example.test/api/v1/'),
        client: MockClient(
          (r) async => retry
              ? http.Response(
                  jsonEncode({
                    'data': [row()],
                  }),
                  200,
                )
              : http.Response('{}', 503),
        ),
      );
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: StockMinimumPage(
            repository: InventoryRepository(api),
            storeId: 'S1',
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.textContaining('Layanan API'), findsOneWidget);
      expect(find.textContaining('Tidak ada stok di bawah'), findsNothing);
      retry = true;
      await tester.tap(find.text('Coba kembali'));
      await tester.pumpAndSettle();
      expect(find.text('Stok rendah · Kekurangan 8 biji'), findsOneWidget);
    },
  );
  test(
    'Warning pagination uses scoped path and preserves low filter',
    () async {
      final api = ApiClient(
        baseUrl: Uri.parse('https://api.example.test/api/v1/'),
        client: MockClient((r) async {
          expect(r.url.host, 'api.example.test');
          expect(r.url.queryParameters['low_only'], '1');
          return http.Response(
            jsonEncode({
              'data': [
                row(sku: r.url.queryParameters['page'] == '2' ? 'B' : 'A'),
              ],
              'last_page': 2,
              'next_page_url': 'https://untrusted.test/collect',
            }),
            200,
          );
        }),
      );
      addTearDown(api.close);
      final rows = await InventoryRepository(api).minimums('S1', lowOnly: true);
      expect(rows.map((r) => r.sku), ['A', 'B']);
    },
  );
}
