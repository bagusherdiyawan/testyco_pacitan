import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/core/modules/feature_module.dart';
import 'package:testyco_pos/core/domain/wib_date_range.dart';
import 'package:testyco_pos/design_system/templates/responsive_shell.dart';
import 'package:testyco_pos/features/audit/audit_module.dart';
import 'package:testyco_pos/features/audit/data/audit_repository.dart';
import 'package:testyco_pos/features/audit/domain/audit_history.dart';
import 'package:testyco_pos/features/audit/application/audit_controller.dart';
import 'package:testyco_pos/features/audit/presentation/audit_page.dart';
import 'package:testyco_pos/features/identity/domain/store.dart';

class AuditServer {
  bool denied = false, unavailable = false, empty = false;
  String organization = 'ORG',
      rowOrganization = 'ORG',
      visibility = 'organization';
  final queries = <Map<String, String>>[], methods = <String>[];
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example/api/v1/'),
    client: MockClient(handle),
  );
  late final repository = AuditRepository(api);
  Future<http.Response> handle(http.Request r) async {
    expect(r.method, 'GET');
    expect(r.url.host, 'api.example');
    expect(r.url.path, '/api/v1/organizations/ORG/audit');
    methods.add(r.method);
    queries.add(r.url.queryParameters);
    if (denied) return http.Response('{"message":"Tidak berwenang"}', 403);
    if (unavailable) return http.Response('{}', 503);
    return http.Response(
      jsonEncode({
        'organization': {'id': organization, 'name': 'Mitra A'},
        'visibility': visibility,
        'observed_at': '2026-10-30T03:00:00Z',
        'data': empty
            ? []
            : [
                {
                  'id': 'A1',
                  'organization_id': rowOrganization,
                  'action': 'product.updated',
                  'subject_id': 'P1',
                  'actor_id': 7,
                  'occurred_at': '2026-10-29T17:00:00+00:00',
                  'before': '{"name":"Historis","pack_size":8}',
                  'after': {'name': 'Baru', 'pack_size': 10},
                },
              ],
        'current_page': int.parse(r.url.queryParameters['page'] ?? '1'),
        'last_page': 2,
        'next_page_url': 'https://untrusted.test/collect',
      }),
      200,
    );
  }
}

class OtherModule implements FeatureModule {
  const OtherModule(this.id);
  @override
  final String id;
  @override
  String get label => id;
  @override
  IconData get icon => Icons.circle;
  @override
  Widget buildPage() => const Text('Modul lain');
}

void main() {
  test(
    'Only organization managers get navigation hint; assignment is not organization authority',
    () {
      for (final role in [
        'owner',
        'admin',
        'partner_lead',
        'store_manager',
        'cashier',
        'warehouse',
      ]) {
        final s = AssignedStore(
          id: 'S',
          name: 'Toko',
          active: true,
          role: role,
          organizationId: 'ORG',
        );
        expect(
          s.canViewAudit,
          ['owner', 'admin', 'partner_lead'].contains(role),
        );
      }
      expect(
        const AssignedStore(
          id: 'S',
          name: 'Toko',
          active: true,
          role: 'owner',
        ).canViewAudit,
        false,
      );
    },
  );
  test(
    'Filter uses calendar WIB and exact opaque references with validated actor ID',
    () {
      final f = AuditFilter.form(
        '2026-10-30',
        '2026-10-31',
        ' product.updated ',
        ' P1 ',
        ' 7 ',
      );
      expect(f.params, {
        'from': '2026-10-30T00:00:00+07:00',
        'to': '2026-11-01T00:00:00+07:00',
        'action': 'product.updated',
        'subject_id': 'P1',
        'actor_id': '7',
      });
      for (final actor in ['-1', '0', 'abc', '1.5']) {
        expect(
          () => AuditFilter.form('2026-10-30', '2026-10-30', '', '', actor),
          throwsFormatException,
        );
      }
      expect(
        () => AuditFilter.form('2026-02-30', '2026-03-01', '', '', ''),
        throwsFormatException,
      );
      expect(
        () => AuditFilter.form('2026-10-01', '2026-11-01', '', '', ''),
        throwsFormatException,
      );
      expect(
        () => AuditFilter.form('2026-10-01', '2026-10-01', 'a' * 81, '', ''),
        throwsFormatException,
      );
    },
  );
  test(
    'Scoped pagination ignores external next URL and preserves historical JSON and UTC',
    () async {
      final s = AuditServer();
      addTearDown(s.api.close);
      final f = AuditFilter.form(
        '2026-10-30',
        '2026-10-30',
        'product.updated',
        'P1',
        '7',
      );
      final r = await s.repository.load('ORG', f, 2);
      expect(s.queries.single, {...f.params, 'page': '2'});
      expect(r.rows.single.subject, 'P1');
      expect(DateTime.parse(r.rows.single.occurredAt).isUtc, true);
      expect(r.rows.single.before, contains('Historis'));
      expect(s.methods, ['GET']);
    },
  );
  test(
    'Error clears old data, retry keeps filter/page, mismatched envelope and row are denied',
    () async {
      final s = AuditServer();
      addTearDown(s.api.close);
      final m = AuditController(s.repository, 'ORG');
      addTearDown(m.dispose);
      await m.load(next: AuditFilter(WibDateRange.today(), subject: 'P1'));
      await m.load(nextPage: 2);
      expect(m.result, isNotNull);
      s.unavailable = true;
      await m.load();
      expect(m.result, isNull);
      expect(m.error, isNotNull);
      s.unavailable = false;
      s.organization = 'OTHER';
      await m.load();
      expect(m.result, isNull);
      s.organization = 'ORG';
      s.rowOrganization = 'OTHER';
      await m.load();
      expect(m.result, isNull);
      s.rowOrganization = 'ORG';
      await m.load();
      expect(m.result!.page, 2);
      expect(s.queries.last['subject_id'], 'P1');
      s.denied = true;
      await m.load();
      expect(m.result, isNull);
      expect(m.error, contains('Tidak berwenang'));
    },
  );
  for (final setting in [(390.0, 1.5), (1200.0, 1.0)]) {
    testWidgets(
      'Dynamic navigation, filters, snapshots and paging at ${setting.$1}',
      (t) async {
        t.view.physicalSize = Size(setting.$1, 1800);
        t.view.devicePixelRatio = 1;
        addTearDown(t.view.resetPhysicalSize);
        addTearDown(t.view.resetDevicePixelRatio);
        final s = AuditServer();
        addTearDown(s.api.close);
        await t.pumpWidget(
          MaterialApp(
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(
                context,
              ).copyWith(textScaler: TextScaler.linear(setting.$2)),
              child: child!,
            ),
            home: ResponsiveShell(
              registry: ModuleRegistry([
                for (var i = 0; i < 7; i++) OtherModule('F$i'),
                AuditModule(repository: s.repository, organization: 'ORG'),
              ]),
            ),
          ),
        );
        await t.pumpAndSettle();
        if (setting.$1 < 720) {
          await t.tap(find.text('Lainnya'));
          await t.pumpAndSettle();
        }
        await t.tap(find.text('Audit'));
        await t.pumpAndSettle();
        expect(find.text('Jejak audit'), findsOneWidget);
        expect(find.text('2026-10-30 00:00 WIB'), findsOneWidget);
        await t.tap(find.text('Filter audit'));
        await t.pumpAndSettle();
        await t.enterText(
          find.widgetWithText(TextField, 'Tanggal awal WIB (YYYY-MM-DD)'),
          '2026-10-30',
        );
        await t.enterText(
          find.widgetWithText(TextField, 'Tanggal akhir WIB (YYYY-MM-DD)'),
          '2026-10-31',
        );
        await t.enterText(
          find.widgetWithText(TextField, 'Aksi persis (opsional)'),
          'product.updated',
        );
        await t.enterText(
          find.widgetWithText(TextField, 'ID objek persis (opsional)'),
          'P1',
        );
        if (setting.$1 < 720) {
          t.view.viewInsets = const FakeViewPadding(bottom: 300);
          addTearDown(t.view.resetViewInsets);
          await t.pump();
        }
        await t.ensureVisible(find.text('Terapkan filter'));
        await t.tap(find.text('Terapkan filter'));
        await t.pumpAndSettle();
        expect(s.queries.last['to'], '2026-11-01T00:00:00+07:00');
        await t.enterText(
          find.widgetWithText(TextField, 'ID objek persis (opsional)'),
          'UNAPPLIED',
        );
        final scroll = find
            .descendant(
              of: find.byKey(const Key('audit-scroll')),
              matching: find.byType(Scrollable),
            )
            .first;
        await t.scrollUntilVisible(
          find.text('Perubahan sebelum / sesudah'),
          250,
          scrollable: scroll,
        );
        await t.tap(find.text('Perubahan sebelum / sesudah'));
        await t.pumpAndSettle();
        expect(find.textContaining('Historis'), findsOneWidget);
        expect(find.textContaining('"pack_size": 8'), findsOneWidget);
        await t.scrollUntilVisible(
          find.text('Berikutnya'),
          250,
          scrollable: scroll,
        );
        await t.tap(find.text('Berikutnya'));
        await t.pumpAndSettle();
        expect(s.queries.last['page'], '2');
        expect(s.queries.last['subject_id'], 'P1');
        expect(s.methods.toSet(), {'GET'});
        expect(t.takeException(), isNull);
      },
    );
  }
  testWidgets(
    'Forged module entry still shows API denial; retry distinguishes empty result',
    (t) async {
      final s = AuditServer()..denied = true;
      addTearDown(s.api.close);
      await t.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: AuditPage(repository: s.repository, organization: 'ORG'),
          ),
        ),
      );
      await t.pumpAndSettle();
      expect(find.text('Tidak ada audit pada filter ini.'), findsNothing);
      expect(find.textContaining('Tidak berwenang'), findsOneWidget);
      s.denied = false;
      s.empty = true;
      s.visibility = 'onboarding';
      await t.tap(find.text('Coba kembali'));
      await t.pumpAndSettle();
      expect(find.text('Tidak ada audit pada filter ini.'), findsOneWidget);
      expect(find.textContaining('Hanya data onboarding'), findsOneWidget);
    },
  );
}
