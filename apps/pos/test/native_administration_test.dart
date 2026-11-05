import 'dart:convert';
import 'package:testyco_pos/features/administration/application/admin_acknowledgement.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/administration/administration_module.dart';
import 'package:testyco_pos/features/administration/application/admin_mutation.dart';
import 'package:testyco_pos/features/administration/data/admin_repository.dart';
import 'package:testyco_pos/features/administration/presentation/admin_form.dart';
import 'package:testyco_pos/features/administration/presentation/assets_page.dart';
import 'package:testyco_pos/features/administration/presentation/billing_page.dart';
import 'package:testyco_pos/features/administration/presentation/contracts_page.dart';
import 'package:testyco_pos/features/administration/presentation/vouchers_page.dart';
import 'package:testyco_pos/features/administration/presentation/commercial_report_page.dart';

ApiClient client(Future<http.Response> Function(http.Request) handler) {
  final api = ApiClient(
    baseUrl: Uri.parse('https://api.test/api/v1/'),
    client: MockClient(handler),
  );
  api.acceptLogin({
    'token': 'private',
    'user': {'id': 1, 'name': 'Owner'},
  });
  return api;
}

http.Response json(Object value, [int status = 200]) => http.Response(
  jsonEncode(value),
  status,
  headers: {'content-type': 'application/json'},
);
Map<String, dynamic> page(
  List data, {
  String? org,
  int number = 1,
  String? next,
}) => {
  'data': data,
  'current_page': number,
  'next_page_url': next,
  if (org != null) 'organization_id': org,
};
void main() {
  test(
    'Central onboarding authority does not grant commercial administration',
    () {
      expect(
        AdminRepository.manages({
          'active': true,
          'can_manage': true,
          'role': null,
        }),
        isFalse,
      );
      expect(
        AdminRepository.manages({'active': true, 'role': 'store_manager'}),
        isFalse,
      );
      expect(
        AdminRepository.manages({'active': false, 'role': 'owner'}),
        isFalse,
      );
      expect(
        AdminRepository.manages({'active': true, 'role': 'partner_lead'}),
        isTrue,
      );
    },
  );
  test(
    'Pagination retains local path and rejects mismatched envelope',
    () async {
      final paths = <String>[];
      final api = client((r) async {
        paths.add(r.url.toString());
        return json(
          page(
            [
              {'id': 'one'},
            ],
            number: paths.length,
            next: paths.length == 1
                ? 'https://foreign.test/steal?page=99'
                : null,
          ),
        );
      });
      expect((await AdminRepository(api).all('organizations')).length, 2);
      expect(paths.last, 'https://api.test/api/v1/organizations?page=2');
      expect(
        () => AdminRepository.pageRows(
          page([], org: 'foreign'),
          1,
          organization: 'own',
        ),
        throwsFormatException,
      );
    },
  );
  test(
    'Unknown response retains UUID, nested body and actor through later denial',
    () async {
      final requests = <String>[];
      final api = client((r) async {
        requests.add(r.body);
        return requests.length == 1
            ? json({}, 503)
            : requests.length == 2
            ? json({'message': 'Denied'}, 403)
            : json({
                'data': {
                  'id': 'doc',
                  'organization_id': 'own',
                  'version': 2,
                  'status': 'accepted',
                },
              });
      });
      final input = <String, dynamic>{
        'version': 1,
        'lines': [
          {'quantity': 7},
        ],
      };
      final op = AdminMutation(
        api,
        'organizations/own/contracts/doc/accept',
        input,
        (r) => documentAck(
          r,
          scopeKey: 'organization_id',
          scope: 'own',
          id: 'doc',
          version: 2,
          status: 'accepted',
        ),
      );
      (input['lines'] as List).clear();
      await expectLater(op.send(), throwsA(isA<ApiException>()));
      expect(op.uncertain, isTrue);
      await expectLater(op.send(), throwsA(isA<ApiException>()));
      expect(op.uncertain, isTrue);
      await op.send();
      expect(requests.toSet().length, 1);
      expect(jsonDecode(requests.singleOrNull ?? requests.first)['lines'], [
        {'quantity': 7},
      ]);
      api.acceptLogin({
        'token': 'other',
        'user': {'id': 2, 'name': 'Other'},
      });
      await expectLater(op.send(), throwsA(isA<ApiException>()));
      expect(requests.length, 3);
    },
  );
  test(
    'Malformed or cross organization ACK remains unknown; first definite denial permits edit',
    () async {
      final api = client(
        (r) async => json({
          'data': {'id': 'x', 'organization_id': 'foreign', 'version': 1},
        }),
      );
      final op = AdminMutation(
        api,
        'path',
        {},
        (r) => documentAck(r, scopeKey: 'organization_id', scope: 'own'),
      );
      await expectLater(op.send(), throwsFormatException);
      expect(op.uncertain, isTrue);
      final denied = AdminMutation(
        client((r) async => json({'message': 'Invalid'}, 422)),
        'path',
        {},
        (_) => true,
      );
      await expectLater(denied.send(), throwsA(isA<ApiException>()));
      expect(denied.uncertain, isFalse);
    },
  );
  test(
    'Private PDF checks local route, MIME, redirect and bytes; no external token forwarding',
    () async {
      final org = '01AAAAAAAAAAAAAAAAAAAAAAAA',
          id = '01BBBBBBBBBBBBBBBBBBBBBBBB';
      var calls = 0;
      final api = client((r) async {
        calls++;
        expect(r.followRedirects, isFalse);
        expect(r.headers['Authorization'], 'Bearer private');
        return http.Response(
          '%PDF-fixture',
          200,
          headers: {'content-type': 'application/pdf'},
        );
      });
      expect(
        ascii.decode(
          await api.pdf('organizations/$org/contracts/$id/attachment'),
        ),
        startsWith('%PDF-'),
      );
      await expectLater(
        api.pdf('https://foreign.test/file'),
        throwsA(isA<ApiException>()),
      );
      expect(calls, 1);
      final redirect = client(
        (r) async => http.Response(
          '',
          302,
          headers: {
            'location': 'https://foreign.test',
            'content-type': 'application/pdf',
          },
        ),
      );
      await expectLater(
        redirect.pdf('organizations/$org/contracts/$id/attachment'),
        throwsA(isA<ApiException>()),
      );
      final invalid = client(
        (r) async => http.Response(
          'not-pdf',
          200,
          headers: {'content-type': 'application/pdf'},
        ),
      );
      await expectLater(
        invalid.pdf('organizations/$org/contracts/$id/attachment'),
        throwsA(isA<ApiException>()),
      );
    },
  );
  for (final width in [390.0, 1200.0]) {
    testWidgets(
      'Native administration modules render and contract consent requires review at $width',
      (tester) async {
        tester.view.physicalSize = Size(width, 1800);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        var posts = 0;
        final api = client((r) async {
          if (r.method == 'POST') {
            posts++;
            return json({
              'data': {
                'id': 'c',
                'organization_id': 'source',
                'partner_id': 'own',
                'version': 2,
                'status': 'accepted',
              },
            });
          }
          return json({
            ...page([
              {
                'id': 'c',
                'organization_id': 'source',
                'partner_id': 'own',
                'title': 'Perjanjian Pacitan',
                'status': posts > 0 ? 'accepted' : 'published',
                'version': posts > 0 ? 2 : 1,
                'terms': 'Ketentuan lengkap',
                'brand_terms': 'Merek',
                'data_scope': 'Terbatas',
                'facilities': 'Freezer',
              },
            ], org: 'own'),
            'can_create': false,
            'can_decide': true,
          });
        });
        await tester.pumpWidget(
          MaterialApp(
            home: ContractsPage(api: api, organization: 'own'),
          ),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Setujui'));
        await tester.pumpAndSettle();
        expect(find.textContaining('Ketentuan lengkap'), findsOneWidget);
        await tester.tap(find.text('Tinjau sebelum mengirim'));
        await tester.pumpAndSettle();
        expect(posts, 0);
        await tester.enterText(find.byType(TextFormField), 'Pimpinan Mitra');
        await tester.tap(find.byType(Checkbox));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Tinjau sebelum mengirim'));
        await tester.pumpAndSettle();
        expect(posts, 0);
        await tester.tap(find.text('Konfirmasi dan kirim'));
        await tester.pumpAndSettle();
        expect(posts, 1);
        expect(find.textContaining('accepted'), findsOneWidget);
        expect(tester.takeException(), isNull);
        await tester.pumpWidget(
          MaterialApp(
            home: AdministrationHome(
              api: api,
              organization: {'id': 'own', 'name': 'Pacitan', 'type': 'partner'},
            ),
          ),
        );
        await tester.pumpAndSettle();
        for (final name in [
          'Invoice dan konsinyasi',
          'Kontrak kemitraan',
          'Konfigurasi voucher',
          'Administrasi aset',
          'Laporan komersial',
        ]) {
          expect(find.text(name), findsOneWidget);
        }
        expect(tester.takeException(), isNull);
      },
    );
  }
  testWidgets(
    'Unknown native form blocks pop and reuses exact reviewed payload',
    (tester) async {
      final sent = <String>[];
      final api = client((r) async {
        sent.add(r.body);
        return json({}, 503);
      });
      await tester.pumpWidget(
        MaterialApp(
          home: AdminForm(
            api: api,
            title: 'Biaya',
            path: 'costs',
            fields: [numberField('amount', 'Nominal')],
            accept: (_) => false,
          ),
        ),
      );
      await tester.enterText(find.byType(TextFormField), '0');
      await tester.tap(find.text('Tinjau sebelum mengirim'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Konfirmasi dan kirim'));
      await tester.pumpAndSettle();
      expect(tester.widget<PopScope>(find.byType(PopScope)).canPop, isFalse);
      expect(find.text('Ubah data'), findsNothing);
      await tester.ensureVisible(
        find.text('Periksa / kirim ulang permintaan yang sama'),
      );
      await tester.tap(find.text('Periksa / kirim ulang permintaan yang sama'));
      await tester.pumpAndSettle();
      expect(sent.length, 2);
      expect(sent.first, sent.last);
      expect(jsonDecode(sent.first)['amount'], 0);
    },
  );
  testWidgets(
    'Voucher native status uses original version and explicit active target',
    (tester) async {
      Map<String, dynamic>? sent;
      final api = client((r) async {
        if (r.method == 'POST') {
          sent = jsonDecode(r.body);
          return json({
            'data': {
              'id': 'v',
              'organization_id': 'own',
              'version': 5,
              'active': false,
            },
          });
        }
        return json(
          page([
            {
              'id': 'v',
              'organization_id': 'own',
              'version': sent == null ? 4 : 5,
              'code': 'PACITAN',
              'active': sent == null,
            },
          ], org: 'own'),
        );
      });
      await tester.pumpWidget(
        MaterialApp(
          home: VouchersPage(api: api, organization: 'own'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Nonaktifkan'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextFormField), 'Anggaran dihentikan');
      await tester.tap(find.text('Tinjau sebelum mengirim'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Konfirmasi dan kirim'));
      await tester.pumpAndSettle();
      expect(sent!['version'], 4);
      expect(sent!['active'], false);
    },
  );
  testWidgets('Asset detail rejects foreign history before showing actions', (
    tester,
  ) async {
    final api = client(
      (r) async => json({
        'asset': {'id': 'a', 'organization_id': 'own', 'version': 1},
        'is_owner': true,
        'can_confirm': false,
        'placements': page([
          {'id': 'p', 'business_asset_id': 'foreign'},
        ]),
        'incidents': page([]),
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: AssetDetailPage(api: api, id: 'a'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('Riwayat aset tidak cocok'), findsOneWidget);
    expect(find.text('Usulkan penempatan'), findsNothing);
  });
  testWidgets('Invoice context rejects foreign order before mutations', (
    tester,
  ) async {
    final api = client(
      (r) async => json({
        'order_id': 'foreign',
        'organization_id': 'own',
        'partner_id': 'partner',
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: BillingPage(api: api, organization: 'own', order: 'order'),
      ),
    );
    await tester.pumpAndSettle();
    expect(
      find.textContaining('Lingkup penagihan tidak cocok'),
      findsOneWidget,
    );
    expect(find.text('Usulkan invoice'), findsNothing);
  });
  testWidgets(
    'Commercial native report sends explicit WIB period and preserves unknown margin',
    (tester) async {
      final api = client((r) async {
        expect(r.url.queryParameters['from'], '2026-11-01T00:00:00+07:00');
        expect(r.url.queryParameters['to'], '2026-11-03T00:00:00+07:00');
        return json({
          'organization_id': 'own',
          'currency': 'IDR',
          'side': 'buyer',
          'basis': 'document_cohort_current_adjustments',
          'balances_scope': 'accepted_invoices_in_selected_period',
          'observed_at': '2026-11-05T00:00:00Z',
          'metrics': {
            for (final t in [
              'pos_sale',
              'wholesale_invoice',
              'purchase_invoice',
            ])
              t: {'profit_amount': null},
          },
          'balances': {'outstanding_amount': 0},
          'data': [],
        });
      });
      await tester.pumpWidget(
        MaterialApp(
          home: CommercialReportPage(api: api, organization: 'own'),
        ),
      );
      await tester.enterText(find.byType(TextField).at(0), '2026-11-01');
      await tester.enterText(find.byType(TextField).at(1), '2026-11-02');
      await tester.tap(find.text('Tampilkan laporan'));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('Laba tercakup (Rp): Belum diketahui'),
        findsWidgets,
      );
      expect(tester.takeException(), isNull);
    },
  );
  for (final width in [390.0, 1200.0]) {
    testWidgets(
      'Native asset recipient accepts original placement/version at $width',
      (tester) async {
        tester.view.physicalSize = Size(width, 2000);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        Map<String, dynamic>? sent;
        Map<String, dynamic> asset() => {
          'id': 'asset',
          'organization_id': 'owner',
          'version': sent == null ? 3 : 4,
          'number': 'Freezer-1',
          'status': sent == null ? 'reserved' : 'in_use',
        };
        Map<String, dynamic> placement() => {
          'id': 'placement',
          'business_asset_id': 'asset',
          'active_asset_id': 'asset',
          'store_name': 'Toko Pacitan',
          'status': sent == null ? 'pending' : 'active',
        };
        final api = client((r) async {
          if (r.method == 'POST') {
            expect(
              r.url.path,
              endsWith('assets/asset/placements/placement/accept'),
            );
            sent = jsonDecode(r.body);
            return json({
              'data': {'asset': asset(), 'placement': placement()},
            });
          }
          return json({
            'asset': asset(),
            'is_owner': false,
            'can_confirm': true,
            'active_placement': placement(),
            'placements': page([placement()]),
            'incidents': page([]),
            'placement_options': {'stores': [], 'contracts': []},
          });
        });
        await tester.pumpWidget(
          MaterialApp(
            home: AssetDetailPage(api: api, id: 'asset'),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text('Usulkan penempatan'), findsNothing);
        await tester.tap(find.text('Setujui'));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.byType(TextFormField),
          'Sudah diperiksa fisik',
        );
        await tester.tap(find.byType(Checkbox));
        await tester.tap(find.text('Tinjau sebelum mengirim'));
        await tester.pumpAndSettle();
        expect(sent, isNull);
        await tester.tap(find.text('Konfirmasi dan kirim'));
        await tester.pumpAndSettle();
        expect(sent!['version'], 3);
        expect(sent!['consent'], true);
        expect(find.text('Setujui'), findsNothing);
        expect(tester.takeException(), isNull);
      },
    );
    testWidgets(
      'Native invoice partner consent is scoped and versioned at $width',
      (tester) async {
        tester.view.physicalSize = Size(width, 2000);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        Map<String, dynamic>? sent;
        Map<String, dynamic> invoice() => {
          'id': 'invoice',
          'organization_id': 'source',
          'partner_id': 'own',
          'supply_order_id': 'order',
          'version': sent == null ? 6 : 7,
          'status': sent == null ? 'pending' : 'accepted',
          'number': 'INV-001',
          'total_amount': 123000,
          'lines': [],
          'payments': [],
          'credits': [],
        };
        final api = client((r) async {
          if (r.method == 'POST') {
            expect(
              r.url.path,
              endsWith('orders/order/billing/invoices/invoice/accept'),
            );
            sent = jsonDecode(r.body);
            return json({'data': invoice()});
          }
          return json({
            'order_id': 'order',
            'organization_id': 'source',
            'partner_id': 'own',
            'can_create': false,
            'can_confirm': true,
            'invoices': page([invoice()]),
            'reports': page([]),
            'contracts': [],
            'shipments': [],
            'cases': [],
            'order_lines': [],
          });
        });
        await tester.pumpWidget(
          MaterialApp(
            home: BillingPage(api: api, organization: 'own', order: 'order'),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text('Usulkan invoice'), findsNothing);
        expect(find.text('Catat pembayaran'), findsNothing);
        await tester.tap(find.text('Setujui'));
        await tester.pumpAndSettle();
        expect(find.textContaining('123000'), findsOneWidget);
        await tester.enterText(
          find.byType(TextFormField),
          'Sesuai bukti kiriman',
        );
        await tester.tap(find.byType(Checkbox));
        await tester.tap(find.text('Tinjau sebelum mengirim'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Konfirmasi dan kirim'));
        await tester.pumpAndSettle();
        expect(sent!['version'], 6);
        expect(sent!['consent'], true);
        expect(find.text('Setujui'), findsNothing);
        expect(tester.takeException(), isNull);
      },
    );
  }
  test(
    'Financial ACK must contain the reviewed payment or correct credit decision',
    () {
      expect(
        adminAcknowledgement(
          'orders/o/billing/invoices/i/payments',
          {'amount': 12, 'reference': 'proof'},
          {
            'data': {
              'id': 'i',
              'payments': [
                {
                  'invoice_id': 'i',
                  'status': 'verified',
                  'amount': 99,
                  'reference': 'other',
                },
              ],
            },
          },
        ),
        isFalse,
      );
      expect(
        adminAcknowledgement(
          'orders/o/billing/invoices/i/credits/c/accept',
          {},
          {
            'data': {
              'id': 'i',
              'credits': [
                {'invoice_id': 'i', 'id': 'wrong', 'status': 'accepted'},
              ],
            },
          },
        ),
        isFalse,
      );
      expect(
        adminAcknowledgement(
          'orders/o/billing/invoices/i/credits/c/accept',
          {},
          {
            'data': {
              'id': 'i',
              'credits': [
                {'invoice_id': 'i', 'id': 'c', 'status': 'accepted'},
              ],
            },
          },
        ),
        isTrue,
      );
    },
  );
}
