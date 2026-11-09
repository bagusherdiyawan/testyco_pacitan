import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:testyco_pos/features/manufacturing/data/erp_repository.dart';
import 'package:testyco_pos/features/manufacturing/presentation/erp_page.dart';
import 'manufacturing_erp_test.dart' as fixtures;

void main() {
  for (final width in [390.0, 1200.0]) {
    testWidgets('Own finance queue and batch cost evidence at $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 2200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = fixtures.apiFor((r) async {
        return fixtures.response({
          'organization_id': 'ORG',
          'section': r.url.queryParameters['section'],
          'current_page': 1,
          'next_page_url': null,
          'can_manage': true,
          'filter_stores': <String, dynamic>{},
          'forms': [],
          'notice': 'Nilai belum diketahui; bukan nol.',
          'data': [
            {
              'id': 'E',
              'organization_id': 'ORG',
              'summary': 'Batch asli: HPP Rp3334',
              'forms': [],
            },
          ],
        });
      });
      await tester.pumpWidget(
        MaterialApp(
          home: ErpPage(
            repository: ErpRepository(api, 'ORG'),
            initialSection: 'posting_events',
            financeOnly: true,
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Batch asli: HPP Rp3334'), findsOneWidget);
      await tester.tap(find.byType(DropdownButtonFormField<String>).first);
      await tester.pumpAndSettle();
      expect(find.text('Resep produksi'), findsNothing);
      expect(find.text('Izin operator ERP'), findsNothing);
      expect(find.text('Jejak nilai / HPP batch'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
  test(
    'Financial forms preserve scoped policy and reject foreign events',
    () async {
      expect(
        ErpRepository.actions,
        containsAll([
          'posting_policy',
          'posting_decide',
          'posting_reconcile',
          'value_propose',
          'value_decide',
        ]),
      );
      final repo = ErpRepository(
        fixtures.apiFor(
          (r) async => fixtures.response({
            'organization_id': 'ORG',
            'section': 'posting_events',
            'current_page': 1,
            'next_page_url': null,
            'can_manage': true,
            'forms': [],
            'data': [
              {
                'id': 'E',
                'organization_id': 'OTHER',
                'summary': 'foreign',
                'forms': [],
              },
            ],
          }),
        ),
        'ORG',
      );
      await expectLater(
        repo.load('posting_events', 1, {}),
        throwsFormatException,
      );
    },
  );
}
