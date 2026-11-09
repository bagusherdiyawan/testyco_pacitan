import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/administration/application/admin_mutation.dart';
import 'package:testyco_pos/features/manufacturing/data/erp_repository.dart';
import 'package:testyco_pos/features/manufacturing/presentation/erp_page.dart';

ApiClient apiFor(Future<http.Response> Function(http.Request) f) {
  final a = ApiClient(
    baseUrl: Uri.parse('https://api.test/api/v1/'),
    client: MockClient(f),
  );
  a.acceptLogin({
    'token': 'private',
    'user': {'id': 1, 'name': 'Owner'},
  });
  return a;
}

http.Response response(Object data, [int status = 200]) => http.Response(
  jsonEncode(data),
  status,
  headers: {'content-type': 'application/json'},
);
Map<String, dynamic> field(
  String key,
  String kind,
  String label, {
  Map<String, String>? options,
}) => {
  'key': key,
  'kind': kind,
  'label': label,
  'options': options ?? [],
  'optional': false,
};
Map<String, dynamic> fixture({int page = 1}) => {
  'organization_id': 'ORG',
  'section': 'materials',
  'can_manage': true,
  'current_page': page,
  'next_page_url': page == 1 ? 'https://foreign.test/stolen' : null,
  'filter_stores': [],
  'filter_materials': [],
  'forms': [
    {
      'operation': 'recipe',
      'label': 'Buat resep produksi',
      'key': 'recipe-',
      'fixed': [],
      'expected_id': null,
      'expected_version': null,
      'fields': [
        field('title', 'text', 'Nama resep'),
        field(
          'ingredients',
          'quantity_map',
          'Komposisi bahan',
          options: {'FLOUR': 'Terigu (kg)', 'EGG': 'Telur (pcs)'},
        ),
        field('output_packs', 'integer', 'Hasil kemasan'),
      ],
    },
  ],
  'data': [
    {
      'id': 'MAT',
      'organization_id': 'ORG',
      'summary': 'Terigu tersedia sesuai satuan kg',
      'forms': [],
    },
  ],
};
void main() {
  test('ERP validates scope and never forwards pagination URL', () async {
    final urls = <Uri>[];
    final api = apiFor((r) async {
      urls.add(r.url);
      return response(fixture(page: int.parse(r.url.queryParameters['page']!)));
    });
    final repo = ErpRepository(api, 'ORG');
    await repo.load('materials', 1, {});
    await repo.load('materials', 2, {});
    expect(urls.last.host, 'api.test');
    expect(urls.last.queryParameters['page'], '2');
    await expectLater(
      ErpRepository(
        apiFor(
          (r) async => response({...fixture(), 'organization_id': 'OTHER'}),
        ),
        'ORG',
      ).load('materials', 1, {}),
      throwsFormatException,
    );
    expect(
      () => ErpRepository.fields({
        ...((fixture()['forms'] as List).first as Map<String, dynamic>),
        'operation': 'https://foreign.test',
      }),
      throwsFormatException,
    );
  });
  test('ERP ACK requires original UUID; changed actor cannot replay', () async {
    final bodies = <String>[];
    final api = apiFor((r) async {
      bodies.add(r.body);
      return response({
        'data': {
          'id': 'R',
          'organization_id': 'ORG',
          'version': 1,
          'operation_id': 'wrong',
        },
      });
    });
    final p = AdminMutation(api, 'organizations/ORG/erp/recipe', {
      'ingredients': {'FLOUR': '1.25'},
    }, (_) => true);
    await expectLater(p.send(), throwsFormatException);
    expect(p.uncertain, true);
    await expectLater(p.send(), throwsFormatException);
    expect(bodies.first, bodies.last);
    api.acceptLogin({
      'token': 'other',
      'user': {'id': 2, 'name': 'Other'},
    });
    await expectLater(p.send(), throwsA(isA<ApiException>()));
    expect(bodies.length, 2);
  });
  for (final width in [390.0, 1200.0]) {
    testWidgets(
      'ERP quantity maps review decimals and restore draft at $width',
      (tester) async {
        tester.view.physicalSize = Size(width, 2200);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        Map<String, dynamic>? posted;
        final api = apiFor((r) async {
          if (r.method == 'POST') {
            posted = jsonDecode(r.body);
            return response({
              'data': {
                'id': 'R',
                'organization_id': 'ORG',
                'version': 1,
                'operation_id': posted!['operation_id'],
              },
            });
          }
          return response(fixture());
        });
        await tester.pumpWidget(
          MaterialApp(home: ErpPage(repository: ErpRepository(api, 'ORG'))),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Buat resep produksi'));
        await tester.pumpAndSettle();
        final inputs = find.byType(TextFormField);
        expect(inputs, findsNWidgets(4));
        await tester.enterText(inputs.at(0), 'Dimsum ayam');
        await tester.enterText(inputs.at(1), '1.25');
        await tester.enterText(inputs.at(2), '2');
        await tester.enterText(inputs.at(3), '5');
        await tester.ensureVisible(find.text('Tinjau sebelum mengirim'));
        await tester.tap(find.text('Tinjau sebelum mengirim'));
        await tester.pumpAndSettle();
        expect(posted, isNull);
        expect(find.textContaining('Terigu (kg): 1.25'), findsOneWidget);
        await tester.tap(find.text('Ubah data'));
        await tester.pumpAndSettle();
        expect(
          tester
              .widget<TextFormField>(find.byType(TextFormField).at(1))
              .initialValue,
          '1.25',
        );
        await tester.ensureVisible(find.text('Tinjau sebelum mengirim'));
        await tester.tap(find.text('Tinjau sebelum mengirim'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Konfirmasi dan kirim'));
        await tester.pumpAndSettle();
        expect(posted!['ingredients'], {'FLOUR': '1.25', 'EGG': '2'});
        expect(posted!['output_packs'], 5);
        expect(tester.takeException(), isNull);
      },
    );
    testWidgets(
      'Unknown ERP submit freezes quantities and navigation at $width',
      (tester) async {
        tester.view.physicalSize = Size(width, 2200);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final sent = <String>[];
        final api = apiFor((r) async {
          if (r.method == 'POST') {
            sent.add(r.body);
            return response({}, 503);
          }
          return response(fixture());
        });
        await tester.pumpWidget(
          MaterialApp(home: ErpPage(repository: ErpRepository(api, 'ORG'))),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Buat resep produksi'));
        await tester.pumpAndSettle();
        final inputs = find.byType(TextFormField);
        await tester.enterText(inputs.at(0), 'Resep');
        await tester.enterText(inputs.at(1), '0.5');
        await tester.enterText(inputs.at(3), '1');
        await tester.ensureVisible(find.text('Tinjau sebelum mengirim'));
        await tester.tap(find.text('Tinjau sebelum mengirim'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Konfirmasi dan kirim'));
        await tester.pumpAndSettle();
        expect(tester.widget<PopScope>(find.byType(PopScope)).canPop, false);
        await tester.ensureVisible(
          find.text('Periksa / kirim ulang permintaan yang sama'),
        );
        await tester.tap(
          find.text('Periksa / kirim ulang permintaan yang sama'),
        );
        await tester.pumpAndSettle();
        expect(sent.length, 2);
        expect(sent.first, sent.last);
        expect(find.text('Ubah data'), findsNothing);
        expect(tester.takeException(), isNull);
      },
    );
  }
}
