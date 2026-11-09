import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:testyco_pos/features/manufacturing/data/erp_repository.dart';
import 'package:testyco_pos/features/manufacturing/presentation/erp_page.dart';
import 'manufacturing_erp_test.dart' as fixtures;

void main() {
  for (final width in [390.0, 1200.0]) {
    testWidgets('ERP operator scope has only production sections at $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 2200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = fixtures.apiFor((r) async {
        expect(r.url.queryParameters['store_id'], 'STORE');
        return fixtures.response({
          'organization_id': 'ORG',
          'section': r.url.queryParameters['section'],
          'current_page': 1,
          'next_page_url': null,
          'can_manage': false,
          'can_operate': true,
          'filter_stores': {'STORE': 'Gudang produksi'},
          'forms': [],
          'data': [
            {
              'id': 'P',
              'organization_id': 'ORG',
              'store_id': 'STORE',
              'summary': 'Menunggu QC owner',
              'forms': [],
            },
          ],
        });
      });
      await tester.pumpWidget(
        MaterialApp(
          home: ErpPage(
            repository: ErpRepository(api, 'ORG'),
            operatorStore: 'STORE',
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Menunggu QC owner'), findsOneWidget);
      await tester.tap(find.byType(DropdownButtonFormField<String>).first);
      await tester.pumpAndSettle();
      expect(find.text('Jurnal debit/kredit'), findsNothing);
      expect(find.text('Izin operator ERP'), findsNothing);
      expect(find.text('Resep produksi'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
    testWidgets(
      'Native trial balance explicitly selects own period at $width',
      (tester) async {
        tester.view.physicalSize = Size(width, 2200);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        var selected = false;
        final api = fixtures.apiFor((r) async {
          selected = r.url.queryParameters['period_id'] == 'PERIOD';
          return fixtures.response({
            'organization_id': 'ORG',
            'section': r.url.queryParameters['section'],
            'current_page': 1,
            'next_page_url': null,
            'can_manage': true,
            'filter_stores': [],
            'filter_periods': {'PERIOD': 'November2026'},
            'forms': [],
            'data': selected
                ? [
                    {
                      'id': 'TOTAL',
                      'organization_id': 'ORG',
                      'summary': 'Debit Rp1000 = kredit Rp1000',
                      'forms': [],
                    },
                  ]
                : [],
          });
        });
        await tester.pumpWidget(
          MaterialApp(home: ErpPage(repository: ErpRepository(api, 'ORG'))),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.byType(DropdownButtonFormField<String>).first);
        await tester.pumpAndSettle();
        await tester.ensureVisible(
          find.text('Neraca saldo dan ringkasan akun').last,
        );
        await tester.tap(find.text('Neraca saldo dan ringkasan akun').last);
        await tester.pumpAndSettle();
        expect(selected, false);
        await tester.tap(find.byType(DropdownButtonFormField<String>).last);
        await tester.pumpAndSettle();
        await tester.tap(find.text('November2026').last);
        await tester.pumpAndSettle();
        expect(selected, true);
        expect(find.text('Debit Rp1000 = kredit Rp1000'), findsOneWidget);
        expect(tester.takeException(), isNull);
      },
    );
  }
  test(
    'Operator malformed financial action and wrong warehouse are rejected before display',
    () async {
      final api = fixtures.apiFor(
        (r) async => fixtures.response({
          'organization_id': 'ORG',
          'section': 'productions',
          'current_page': 1,
          'can_manage': false,
          'can_operate': true,
          'forms': [],
          'data': [
            {
              'id': 'P',
              'organization_id': 'ORG',
              'store_id': 'FOREIGN',
              'summary': 'Should not render',
              'forms': [],
            },
          ],
        }),
      );
      await expectLater(
        ErpRepository(api, 'ORG').load('productions', 1, {'store_id': 'STORE'}),
        throwsFormatException,
      );
    },
  );
}
