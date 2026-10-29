import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:testyco_pos/core/modules/feature_module.dart';
import 'package:testyco_pos/design_system/organisms/module_navigation.dart';
import 'package:testyco_pos/features/identity/domain/store.dart';

class FixtureModule implements FeatureModule {
  const FixtureModule(this.number);
  final int number;
  @override
  String get id => '$number';
  @override
  String get label => 'Fitur $number';
  @override
  IconData get icon => Icons.store;
  @override
  Widget buildPage() => const SizedBox();
}

void main() {
  test('Only manager roles receive native shift management entry', () {
    for (final role in ['owner', 'admin', 'partner_lead', 'store_manager']) {
      expect(
        AssignedStore(
          id: 'S',
          name: 'Store',
          active: true,
          role: role,
        ).canManageShifts,
        true,
      );
    }
    for (final role in ['warehouse', 'cashier', null]) {
      expect(
        AssignedStore(
          id: 'S',
          name: 'Store',
          active: true,
          role: role,
        ).canManageShifts,
        false,
      );
    }
  });
  testWidgets(
    'Mobile overflow exposes every module with selected destination preserved',
    (tester) async {
      tester.view.physicalSize = const Size(390, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      var selected = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: StatefulBuilder(
            builder: (context, setState) => Scaffold(
              bottomNavigationBar: ModuleNavigation(
                modules: List.generate(7, FixtureModule.new),
                selected: selected,
                onSelected: (v) => setState(() => selected = v),
              ),
            ),
          ),
        ),
      );
      expect(find.byType(NavigationDestination), findsNWidgets(5));
      await tester.tap(find.text('Lainnya'));
      await tester.pumpAndSettle();
      expect(find.text('Fitur 4'), findsOneWidget);
      expect(find.text('Fitur 5'), findsOneWidget);
      await tester.tap(find.text('Fitur 6'));
      await tester.pumpAndSettle();
      expect(selected, 6);
      expect(
        tester.widget<NavigationBar>(find.byType(NavigationBar)).selectedIndex,
        4,
      );
      expect(find.text('Fitur 6'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );
}
