import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/identity/application/session.dart';
import 'package:testyco_pos/features/identity/application/password_change_controller.dart';
import 'package:testyco_pos/features/identity/presentation/password_change_page.dart';
import 'package:testyco_pos/features/identity/presentation/login_page.dart';
import 'package:testyco_pos/main.dart';

const old = ' CurrentFixture123 ', next = ' NewFixturePassword456 ';

class PasswordServer {
  int status = 204;
  bool lost = false, malformed = false, hasStore = false;
  Completer<void>? gate;
  final posts = <Map<String, dynamic>>[], methods = <String>[];
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example/api/v1/'),
    client: MockClient(handle),
  );
  late final session = Session(api);
  http.Response reply(Object body, int code) =>
      http.Response(jsonEncode(body), code);
  Future<http.Response> handle(http.Request r) async {
    methods.add('${r.method} ${r.url.path}');
    if (r.url.path.endsWith('/auth/login')) {
      return reply({
        'token': 'fixture',
        'user': {'name': 'Kasir'},
      }, 200);
    }
    expect(r.headers['authorization'], 'Bearer fixture');
    if (r.url.path.endsWith('/stores')) {
      return reply({
        'data': hasStore
            ? [
                {'id': 'S1', 'name': 'Pacitan', 'active': true},
              ]
            : [],
      }, 200);
    }
    if (r.url.path.endsWith('/catalog')) return reply({'data': []}, 200);
    if (!r.url.path.endsWith('/auth/password') || r.method != 'POST') {
      throw StateError('Unexpected request');
    }
    posts.add(jsonDecode(r.body) as Map<String, dynamic>);
    if (gate != null) await gate!.future;
    if (lost) throw http.ClientException('Lost response');
    if (malformed) return http.Response('broken JSON', 200);
    return status == 204
        ? http.Response('', 204)
        : reply({'message': 'Echoed $old $next'}, status);
  }

  Future<void> login() => session.login('fixture@example.test', old);
}

Finder input(String label) => find.widgetWithText(TextFormField, label);
Future<void> fill(WidgetTester t) async {
  await t.enterText(input('Kata sandi saat ini'), old);
  await t.enterText(input('Kata sandi baru'), next);
  await t.enterText(input('Ulangi kata sandi baru'), next);
}

void main() {
  test(
    'Success sends exact untrimmed body and clears local token without extra logout',
    () async {
      final s = PasswordServer();
      addTearDown(s.session.dispose);
      await s.login();
      final m = PasswordChangeController(s.session);
      addTearDown(m.dispose);
      await m.submit(old, next, next);
      expect(s.posts.single, {
        'current_password': old,
        'password': next,
        'password_confirmation': next,
      });
      expect(s.session.authenticated, false);
      expect(s.api.authenticated, false);
      expect(s.session.notice, contains('berhasil'));
      expect(s.session.notice, contains('Seluruh sesi'));
      expect(s.methods.any((m) => m.endsWith('/auth/logout')), false);
      await s.login();
      expect(s.session.notice, isNull);
    },
  );
  test(
    'Empty short and mismatched local input does not send requests',
    () async {
      final s = PasswordServer();
      addTearDown(s.session.dispose);
      await s.login();
      final m = PasswordChangeController(s.session);
      addTearDown(m.dispose);
      for (final v in [
        ('', next, next),
        (old, 'short', 'short'),
        (old, next, 'different'),
      ]) {
        await m.submit(v.$1, v.$2, v.$3);
        expect(m.error, isNotNull);
        expect(s.posts, isEmpty);
        expect(s.session.authenticated, true);
      }
    },
  );
  test(
    '422 403 and 429 retain session without reflecting secret server messages',
    () async {
      final s = PasswordServer();
      addTearDown(s.session.dispose);
      await s.login();
      final m = PasswordChangeController(s.session);
      addTearDown(m.dispose);
      for (final code in [422, 403, 429]) {
        s.status = code;
        await m.submit(old, next, next);
        expect(s.session.authenticated, true);
        expect(m.error, isNotNull);
        expect(m.error, isNot(contains(old)));
        expect(m.error, isNot(contains(next)));
      }
      s.status = 204;
      await m.submit(old, next, next);
      expect(s.session.authenticated, false);
    },
  );
  for (final mode in ['network', 'malformed', 'server']) {
    test(
      'Unknown $mode clears session with no automatic resubmission',
      () async {
        final s = PasswordServer()
          ..lost = mode == 'network'
          ..malformed = mode == 'malformed'
          ..status = mode == 'server' ? 500 : 204;
        addTearDown(s.session.dispose);
        await s.login();
        final m = PasswordChangeController(s.session);
        addTearDown(m.dispose);
        await m.submit(old, next, next);
        expect(s.posts.length, 1);
        expect(s.session.authenticated, false);
        expect(s.session.notice, contains('belum dapat dipastikan'));
        expect(s.session.notice, isNot(contains(next)));
        expect(m.error, isNull);
        await m.submit(old, next, next);
        expect(s.posts.length, 1);
      },
    );
  }
  test('Busy blocks duplicate requests and 401 clears session', () async {
    final s = PasswordServer()
      ..gate = Completer<void>()
      ..status = 401;
    addTearDown(s.session.dispose);
    await s.login();
    final m = PasswordChangeController(s.session);
    addTearDown(m.dispose);
    final first = m.submit(old, next, next);
    await Future<void>.delayed(Duration.zero);
    expect(m.busy, true);
    await m.submit('different', next, next);
    expect(s.posts.length, 1);
    s.gate!.complete();
    await first;
    expect(s.session.authenticated, false);
    expect(s.session.notice, contains('Sesi berakhir'));
  });
  for (final setting in [(390.0, 1.5), (1200.0, 1.0)]) {
    testWidgets(
      'Picker or store-shell entry and route reset at ${setting.$1}',
      (t) async {
        t.view.physicalSize = Size(setting.$1, 1100);
        t.view.devicePixelRatio = 1;
        t.platformDispatcher.textScaleFactorTestValue = setting.$2;
        addTearDown(t.view.resetPhysicalSize);
        addTearDown(t.view.resetDevicePixelRatio);
        addTearDown(t.platformDispatcher.clearTextScaleFactorTestValue);
        final s = PasswordServer()..hasStore = setting.$1 > 500;
        addTearDown(s.session.dispose);
        await s.login();
        await t.pumpWidget(TestycoApp(session: s.session));
        await t.pumpAndSettle();
        if (s.hasStore) {
          await t.tap(find.text('Pacitan'));
          await t.pumpAndSettle();
        }
        await t.tap(find.byTooltip('Ganti kata sandi'));
        await t.pumpAndSettle();
        expect(
          MediaQuery.textScalerOf(
            t.element(find.byType(PasswordChangePage)),
          ).scale(10),
          10 * setting.$2,
        );
        for (final f in t.widgetList<TextField>(find.byType(TextField))) {
          expect(f.obscureText, true);
          expect(f.autocorrect, false);
          expect(f.enableSuggestions, false);
        }
        await fill(t);
        await t.ensureVisible(find.byTooltip('Tampilkan kata sandi baru'));
        await t.tap(find.byTooltip('Tampilkan kata sandi baru'));
        await t.pump();
        expect(
          t
              .widget<TextField>(
                find.descendant(
                  of: input('Kata sandi baru'),
                  matching: find.byType(TextField),
                ),
              )
              .obscureText,
          false,
        );
        if (setting.$1 < 500) {
          t.view.viewInsets = const FakeViewPadding(bottom: 280);
          addTearDown(t.view.resetViewInsets);
          await t.pump();
        }
        await t.ensureVisible(find.text('Ubah dan keluar dari seluruh sesi'));
        await t.tap(find.text('Ubah dan keluar dari seluruh sesi'));
        await t.pumpAndSettle();
        expect(find.byType(LoginPage), findsOneWidget);
        expect(find.byType(PasswordChangePage), findsNothing);
        expect(
          find.textContaining('Kata sandi berhasil diubah'),
          findsOneWidget,
        );
        expect(find.textContaining(next), findsNothing);
        expect(
          t.state<NavigatorState>(find.byType(Navigator).first).canPop(),
          false,
        );
        expect(s.posts.length, 1);
        expect(t.takeException(), isNull);
      },
    );
  }
  testWidgets(
    'Busy blocks pop, lost response clears page and password controllers',
    (t) async {
      final s = PasswordServer()
        ..gate = Completer<void>()
        ..lost = true;
      addTearDown(s.session.dispose);
      await s.login();
      await t.pumpWidget(TestycoApp(session: s.session));
      await t.pumpAndSettle();
      await t.tap(find.byTooltip('Ganti kata sandi'));
      await t.pumpAndSettle();
      await fill(t);
      await t.ensureVisible(find.text('Ubah dan keluar dari seluruh sesi'));
      await t.tap(find.text('Ubah dan keluar dari seluruh sesi'));
      await t.pump();
      expect(t.widget<PopScope>(find.byType(PopScope)).canPop, false);
      await t.state<NavigatorState>(find.byType(Navigator).first).maybePop();
      await t.pump();
      expect(find.byType(PasswordChangePage), findsOneWidget);
      expect(
        t
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'Mengubah kata sandi…'),
            )
            .onPressed,
        isNull,
      );
      s.gate!.complete();
      await t.pumpAndSettle();
      expect(find.byType(LoginPage), findsOneWidget);
      expect(find.byType(PasswordChangePage), findsNothing);
      expect(find.textContaining('belum dapat dipastikan'), findsOneWidget);
      expect(s.posts.length, 1);
      for (final f in t.widgetList<TextField>(find.byType(TextField))) {
        expect(f.controller!.text, isEmpty);
      }
      expect(t.takeException(), isNull);
    },
  );
}
