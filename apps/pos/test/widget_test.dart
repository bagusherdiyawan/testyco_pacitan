import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/inventory/data/inventory_repository.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:testyco_pos/features/inventory/inventory_module.dart';
import 'package:testyco_pos/core/modules/feature_module.dart';

import 'package:testyco_pos/design_system/templates/responsive_shell.dart';

void main() {
  test('Registry rejects duplicate module identifiers', () {
    expect(
      () => ModuleRegistry([_ShellFixture(), _ShellFixture()]),
      throwsArgumentError,
    );
  });
  testWidgets('Single enabled module works on tablet', (tester) async {
    tester.view.physicalSize = const Size(1024, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(
      MaterialApp(
        home: ResponsiveShell(registry: ModuleRegistry([_ShellFixture()])),
      ),
    );
    expect(find.text('Kasir toko'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  for (final width in [390.0, 1024.0]) {
    testWidgets('Navigation works at width $width', (tester) async {
      tester.view.physicalSize = Size(width, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(
        MaterialApp(
          home: ResponsiveShell(
            registry: ModuleRegistry([
              _ShellFixture(),
              InventoryModule(
                repository: InventoryRepository(
                  ApiClient(
                    baseUrl: Uri.parse('https://test.example/api/v1/'),
                    client: MockClient(
                      (_) async => http.Response('{"data":[]}', 200),
                    ),
                  ),
                ),
                storeId: 'test-store',
              ),
            ]),
          ),
        ),
      );
      expect(find.text('Kasir toko'), findsOneWidget);
      await tester.tap(find.text('Stok'));
      await tester.pumpAndSettle();
      expect(find.text('Stok dan batch'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}

class _ShellFixture implements FeatureModule {
  @override
  String get id => 'fixture';
  @override
  String get label => 'POS';
  @override
  IconData get icon => Icons.point_of_sale;
  @override
  Widget buildPage() => const Center(child: Text('Kasir toko'));
}
