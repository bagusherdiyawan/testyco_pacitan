import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/reporting/data/cash_report_repository.dart';
import 'package:testyco_pos/features/reporting/domain/cash_report.dart';
import 'package:testyco_pos/features/reporting/presentation/cash_report_page.dart';
import 'package:testyco_pos/features/identity/domain/store.dart';

final period = CashReportPeriod(
  DateTime.utc(2026, 10, 27),
  DateTime.utc(2026, 10, 28),
);
Map<String, dynamic> summary() => {
  'organization': {'name': 'Mitra A'},
  'observed_at': '2026-10-28T03:00:00Z',
  'totals': {
    'transaction_count': 2,
    'gross_sales': 18000,
    'discount_amount': 0,
    'net_sales': 18000,
  },
  'available_stores': [
    {'id': 'S1', 'name': 'Pacitan', 'active': true},
    {'id': 'S2', 'name': 'Lama', 'active': false},
  ],
  'by_store': [
    {
      'store_id': 'S1',
      'store_name': 'Pacitan',
      'active': true,
      'transaction_count': 2,
      'gross_sales': 18000,
      'discount_amount': 0,
      'net_sales': 18000,
    },
  ],
};
Map<String, dynamic> products(int page) => {
  'data': [
    {
      'product_id': 'P1',
      'sku': 'AYAM',
      'name': page == 1 ? 'Nama historis' : 'Produk halaman kedua',
      'condition': 'frozen',
      'sold_pieces': 8,
      'gross_sales': 18000,
      'transaction_count': 2,
    },
  ],
  'observed_at': '2026-10-28T03:01:00Z',
  'current_page': page,
  'last_page': 2,
  'next_page_url': 'https://untrusted.test/collect',
};
void main() {
  test(
    'WIB calendar range is inclusive and crosses month without client timezone dependence',
    () {
      expect(period.query, {
        'from': '2026-10-27T00:00:00+07:00',
        'to': '2026-10-29T00:00:00+07:00',
      });
      expect(
        CashReportPeriod(
          DateTime.utc(2026, 1, 31),
          DateTime.utc(2026, 1, 31),
        ).query['to'],
        '2026-02-01T00:00:00+07:00',
      );
    },
  );
  test(
    'Repository preserves scope period and page without following external pagination',
    () async {
      final seen = <Uri>[];
      final api = ApiClient(
        baseUrl: Uri.parse('https://api.example.test/api/v1/'),
        client: MockClient((r) async {
          seen.add(r.url);
          expect(r.url.host, 'api.example.test');
          expect(r.url.queryParameters['store_id'], 'S1');
          expect(r.url.queryParameters['to'], '2026-10-29T00:00:00+07:00');
          return http.Response(
            jsonEncode(
              r.url.path.endsWith('/products')
                  ? products(2)
                  : {'data': summary()},
            ),
            200,
          );
        }),
      );
      addTearDown(api.close);
      final data = await CashReportRepository(
        api,
      ).load('O1', period, store: 'S1', page: 2);
      expect(data.products.first.pieces, 8);
      expect(data.metrics.net, 18000);
      expect(data.page, 2);
      expect(
        seen.every(
          (u) =>
              u.path.startsWith('/api/v1/organizations/O1/reports/cash-sales'),
        ),
        true,
      );
      expect(
        seen
            .firstWhere((u) => u.path.endsWith('/products'))
            .queryParameters['page'],
        '2',
      );
    },
  );
  for (final setting in [(390.0, 1.5), (1200.0, 1.0)]) {
    testWidgets(
      'Report filters snapshots and product pagination at ${setting.$1} scale ${setting.$2}',
      (tester) async {
        tester.view.physicalSize = Size(setting.$1, 1000);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final seen = <Uri>[];
        final api = ApiClient(
          baseUrl: Uri.parse('https://api.example.test/api/v1/'),
          client: MockClient((r) async {
            seen.add(r.url);
            final page = int.parse(r.url.queryParameters['page'] ?? '1');
            return http.Response(
              jsonEncode(
                r.url.path.endsWith('/products')
                    ? products(page)
                    : {'data': summary()},
              ),
              200,
            );
          }),
        );
        addTearDown(api.close);
        await tester.pumpWidget(
          MaterialApp(
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(
                context,
              ).copyWith(textScaler: TextScaler.linear(setting.$2)),
              child: child!,
            ),
            home: Scaffold(
              body: CashReportPage(
                repository: CashReportRepository(api),
                organizationId: 'O1',
                storeId: 'S1',
                initialPeriod: period,
              ),
            ),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text('2026-10-27 — 2026-10-28 WIB'), findsOneWidget);
        await tester.tap(find.byType(DropdownButtonFormField<String>));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Semua toko berizin').last);
        await tester.pumpAndSettle();
        expect(seen.last.queryParameters.containsKey('store_id'), false);
        await tester.scrollUntilVisible(find.text('Produk berikutnya'), 300);
        await tester.pumpAndSettle();
        expect(find.text('AYAM · Nama historis'), findsOneWidget);
        expect(find.text('frozen · 8 biji · 2 transaksi'), findsOneWidget);
        await tester.tap(find.text('Produk berikutnya'));
        await tester.pumpAndSettle();
        expect(
          seen
              .where((u) => u.path.endsWith('/products'))
              .last
              .queryParameters['page'],
          '2',
        );
        expect(seen.every((u) => u.host == 'api.example.test'), true);
        expect(tester.takeException(), isNull);
      },
    );
  }
  testWidgets(
    'Denied report offers retry without claiming zero sales or showing old metrics',
    (tester) async {
      var failed = true;
      final api = ApiClient(
        baseUrl: Uri.parse('https://api.example.test/api/v1/'),
        client: MockClient(
          (r) async => failed
              ? http.Response(jsonEncode({'message': 'Akses ditolak'}), 403)
              : http.Response(
                  jsonEncode(
                    r.url.path.endsWith('/products')
                        ? products(1)
                        : {'data': summary()},
                  ),
                  200,
                ),
        ),
      );
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CashReportPage(
              repository: CashReportRepository(api),
              organizationId: 'O1',
              storeId: 'S1',
              initialPeriod: period,
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Akses ditolak'), findsOneWidget);
      expect(find.text('Penjualan bersih'), findsNothing);
      failed = false;
      await tester.tap(find.text('Coba kembali'));
      await tester.pumpAndSettle();
      expect(find.text('Penjualan bersih'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );
  test('Report navigation requires eligible role and organization id', () {
    expect(
      AssignedStore.fromJson({
        'id': 'S1',
        'name': 'A',
        'active': true,
        'organization_id': 'O1',
        'role': 'store_manager',
      }).canViewReports,
      true,
    );
    expect(
      AssignedStore.fromJson({
        'id': 'S1',
        'name': 'A',
        'active': true,
        'organization_id': 'O1',
        'role': 'cashier',
      }).canViewReports,
      false,
    );
    expect(
      const AssignedStore(
        id: 'S1',
        name: 'A',
        active: true,
        role: 'partner_lead',
      ).canViewReports,
      false,
    );
  });
}
