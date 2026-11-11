import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:testyco_pos/features/manufacturing/data/erp_repository.dart';
import 'package:testyco_pos/features/manufacturing/presentation/erp_page.dart';
import 'manufacturing_erp_test.dart' as fixtures;

void main() {
  for (final width in [390.0, 1200.0]) {
    testWidgets(
      'Consignor cost retry retains exact pending request at $width',
      (tester) async {
        tester.view.physicalSize = Size(width, 2200);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final sent = <Map<String, dynamic>>[];
        var applied = false;
        final api = fixtures.apiFor((r) async {
          if (r.method == 'POST') {
            final body = Map<String, dynamic>.from(jsonDecode(r.body));
            sent.add(body);
            if (sent.length < 3) {
              return fixtures.response({
                'message': 'Unknown',
              }, sent.length == 1 ? 503 : 403);
            }
            applied = true;
            return fixtures.response({
              'data': {
                'id': 'V1',
                'organization_id': 'ORG',
                'version': 3,
                'operation_id': body['operation_id'],
              },
            }, 201);
          }
          return fixtures.response({
            'organization_id': 'ORG',
            'section': 'consignor_values',
            'can_manage': true,
            'current_page': 1,
            'next_page_url': null,
            'filter_stores': <String, dynamic>{},
            'forms': [],
            'data': [
              {
                'id': 'V1',
                'organization_id': 'ORG',
                'summary': applied
                    ? 'Penyesuaian diterapkan'
                    : 'Periode asal perlu diperiksa',
                'forms': applied
                    ? []
                    : [
                        {
                          'key': 'consignor_retry-V1',
                          'operation': 'consignor_retry',
                          'label': 'Periksa ulang aktivasi konsinyasi',
                          'fixed': {'id': 'V1', 'version': 2},
                          'subject_id': 'V1',
                          'expected_id': 'V1',
                          'expected_version': 3,
                          'fields': [
                            {
                              'key': 'note',
                              'label': 'Bukti pemeriksaan',
                              'kind': 'text',
                              'options': <String, dynamic>{},
                              'optional': false,
                            },
                          ],
                        },
                      ],
              },
            ],
          });
        });
        await tester.pumpWidget(
          MaterialApp(
            home: ErpPage(
              repository: ErpRepository(api, 'ORG'),
              initialSection: 'consignor_values',
              financeOnly: true,
            ),
          ),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Periksa ulang aktivasi konsinyasi'));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.byType(TextFormField),
          'Periode asli diperiksa',
        );
        await tester.tap(find.text('Tinjau sebelum mengirim'));
        await tester.pumpAndSettle();
        expect(sent, isEmpty);
        await tester.tap(find.text('Konfirmasi dan kirim'));
        await tester.pumpAndSettle();
        expect(find.text('Ubah data'), findsNothing);
        await tester.tap(
          find.text('Periksa / kirim ulang permintaan yang sama'),
        );
        await tester.pumpAndSettle();
        expect(find.text('Ubah data'), findsNothing);
        await tester.tap(
          find.text('Periksa / kirim ulang permintaan yang sama'),
        );
        await tester.pumpAndSettle();
        expect(sent.length, 3);
        expect(sent[0], sent[1]);
        expect(sent[0], sent[2]);
        expect(sent[0]['version'], 2);
        expect(sent[0]['id'], 'V1');
        expect(find.text('Penyesuaian diterapkan'), findsOneWidget);
        expect(tester.takeException(), isNull);
      },
    );
  }
}
