import 'dart:convert';
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
  for (final width in [390.0, 1200.0]) {
    testWidgets('Wholesale policy explicitly reviews tax/freight at $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 2200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = fixtures.apiFor((r) async {
        if (r.method == 'POST') {
          throw StateError('Review must not post');
        }
        return fixtures.response({
          'organization_id': 'ORG',
          'section': 'posting',
          'current_page': 1,
          'next_page_url': null,
          'can_manage': true,
          'filter_stores': <String, dynamic>{},
          'data': [],
          'forms': [
            {
              'key': 'wholesale_policy-',
              'operation': 'wholesale_policy',
              'label': 'Ajukan integrasi jurnal wholesale',
              'fixed': {'base_policy_id': 'BASE', 'base_policy_version': 2},
              'subject_id': null,
              'expected_id': null,
              'expected_version': null,
              'fields': [
                {
                  'key': 'tax_treatment',
                  'label': 'Pajak pembelian',
                  'kind': 'choice',
                  'options': {
                    'recoverable': 'Dapat dikreditkan',
                    'clearing': 'Penampung biaya',
                  },
                  'optional': false,
                },
                {
                  'key': 'freight_treatment',
                  'label': 'Ongkir pembelian',
                  'kind': 'choice',
                  'options': {
                    'expense': 'Beban periode',
                    'clearing': 'Penampung biaya',
                  },
                  'optional': false,
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
            initialSection: 'posting',
            financeOnly: true,
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Pembukuan dan jurnal'), findsOneWidget);
      await tester.tap(find.text('Ajukan integrasi jurnal wholesale'));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>).at(0));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Dapat dikreditkan').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>).at(1));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Beban periode').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Tinjau sebelum mengirim'));
      await tester.pumpAndSettle();
      expect(find.text('Pajak pembelian: Dapat dikreditkan'), findsOneWidget);
      expect(find.text('Ongkir pembelian: Beban periode'), findsOneWidget);
      expect(find.text('Konfirmasi dan kirim'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
  for (final width in [390.0, 1200.0]) {
    testWidgets('Invoice cost retry retains exact pending request at $width', (
      tester,
    ) async {
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
          'section': 'invoice_variances',
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
                        'key': 'invoice_revalue_retry-V1',
                        'operation': 'invoice_revalue_retry',
                        'label': 'Antrekan ulang nilai invoice',
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
            initialSection: 'invoice_variances',
            financeOnly: true,
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Antrekan ulang nilai invoice'));
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
      await tester.tap(find.text('Periksa / kirim ulang permintaan yang sama'));
      await tester.pumpAndSettle();
      expect(find.text('Ubah data'), findsNothing);
      await tester.tap(find.text('Periksa / kirim ulang permintaan yang sama'));
      await tester.pumpAndSettle();
      expect(sent.length, 3);
      expect(sent[0], sent[1]);
      expect(sent[0], sent[2]);
      expect(sent[0]['version'], 2);
      expect(sent[0]['id'], 'V1');
      expect(find.text('Penyesuaian diterapkan'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
  for (final width in [390.0, 1200.0]) {
    testWidgets('Closing approval retains exact reviewed snapshot at $width', (
      tester,
    ) async {
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
          'section': 'closings',
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
                  ? 'Closing disetujui'
                  : 'Snapshot laba Rp220 perlu diperiksa',
              'forms': applied
                  ? []
                  : [
                      {
                        'key': 'closing_decide-V1',
                        'operation': 'closing_decide',
                        'label': 'Setujui penutupan buku',
                        'fixed': {
                          'id': 'V1',
                          'version': 2,
                          'decision': 'approve',
                        },
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
            initialSection: 'closings',
            financeOnly: true,
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Setujui penutupan buku'));
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
      await tester.tap(find.text('Periksa / kirim ulang permintaan yang sama'));
      await tester.pumpAndSettle();
      expect(find.text('Ubah data'), findsNothing);
      await tester.tap(find.text('Periksa / kirim ulang permintaan yang sama'));
      await tester.pumpAndSettle();
      expect(sent.length, 3);
      expect(sent[0], sent[1]);
      expect(sent[0], sent[2]);
      expect(sent[0]['version'], 2);
      expect(sent[0]['id'], 'V1');
      expect(find.text('Closing disetujui'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
  for (final width in [390.0, 1200.0]) {
    testWidgets('Commercial policy explicitly reviews tax/freight at $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 2200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = fixtures.apiFor((r) async {
        if (r.method == 'POST') {
          throw StateError('Review must not post');
        }
        return fixtures.response({
          'organization_id': 'ORG',
          'section': 'posting',
          'current_page': 1,
          'next_page_url': null,
          'can_manage': true,
          'filter_stores': <String, dynamic>{},
          'data': [],
          'forms': [
            {
              'key': 'commercial_policy-',
              'operation': 'commercial_policy',
              'label': 'Ajukan integrasi GL bagi hasil dan sponsor',
              'fixed': {'base_policy_id': 'BASE', 'base_policy_version': 2},
              'subject_id': null,
              'expected_id': null,
              'expected_version': null,
              'fields': [
                {
                  'key': 'tax_treatment',
                  'label': 'Pajak bagi hasil',
                  'kind': 'choice',
                  'options': {
                    'recoverable': 'Dapat dikreditkan',
                    'clearing': 'Penampung biaya',
                  },
                  'optional': false,
                },
                {
                  'key': 'recognition_basis',
                  'label': 'Basis pengakuan',
                  'kind': 'choice',
                  'options': {
                    'contractual_rights': 'Hak kontraktual',
                    'clearing': 'Penampung biaya',
                  },
                  'optional': false,
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
            initialSection: 'posting',
            financeOnly: true,
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Pembukuan dan jurnal'), findsOneWidget);
      await tester.tap(find.text('Ajukan integrasi GL bagi hasil dan sponsor'));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>).at(0));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Dapat dikreditkan').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>).at(1));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Hak kontraktual').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Tinjau sebelum mengirim'));
      await tester.pumpAndSettle();
      expect(find.text('Pajak bagi hasil: Dapat dikreditkan'), findsOneWidget);
      expect(find.text('Basis pengakuan: Hak kontraktual'), findsOneWidget);
      expect(find.text('Konfirmasi dan kirim'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
  for (final section in ['income_statement', 'balance_sheet']) {
    for (final width in [390.0, 1200.0]) {
      testWidgets(
        'Financial report selects actual period for $section at $width',
        (tester) async {
          tester.view.physicalSize = Size(width, 2200);
          tester.view.devicePixelRatio = 1;
          addTearDown(tester.view.resetPhysicalSize);
          addTearDown(tester.view.resetDevicePixelRatio);
          final paths = <String>[];
          final api = fixtures.apiFor((r) async {
            expect(r.method, 'GET');
            paths.add(r.url.toString());
            final selected = r.url.queryParameters['period_id'] == 'P1';
            return fixtures.response({
              'organization_id': 'ORG',
              'section': section,
              'can_manage': true,
              'current_page': 1,
              'next_page_url': null,
              'forms': [],
              'filter_stores': <String, dynamic>{},
              'filter_periods': {'P1': 'Oktober 2026'},
              'selected_period_id': selected ? 'P1' : null,
              'data': selected
                  ? [
                      {
                        'id': 'TOTAL',
                        'organization_id': 'ORG',
                        'summary': 'Laba periode Rp220',
                        'forms': [],
                      },
                    ]
                  : [],
              'notice': selected ? 'Hanya jurnal posted' : 'Pilih periode',
            });
          });
          await tester.pumpWidget(
            MaterialApp(
              home: ErpPage(
                repository: ErpRepository(api, 'ORG'),
                initialSection: section,
                financeOnly: true,
              ),
            ),
          );
          await tester.pumpAndSettle();
          expect(find.text('Pilih periode'), findsOneWidget);
          await tester.tap(find.byType(DropdownButtonFormField<String>).last);
          await tester.pumpAndSettle();
          await tester.tap(find.text('Oktober 2026').last);
          await tester.pumpAndSettle();
          expect(paths.last, contains('period_id=P1'));
          expect(find.text('Laba periode Rp220'), findsOneWidget);
          expect(tester.takeException(), isNull);
        },
      );
    }
  }
}
