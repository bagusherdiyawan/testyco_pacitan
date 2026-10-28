import 'package:flutter/material.dart';
import '../../core/modules/feature_module.dart';

class ModuleNavigation extends StatelessWidget {
  const ModuleNavigation({
    required this.modules,
    required this.selected,
    required this.onSelected,
    super.key,
  });
  final List<FeatureModule> modules;
  final int selected;
  final ValueChanged<int> onSelected;
  @override
  Widget build(BuildContext context) => NavigationBar(
    selectedIndex: selected,
    onDestinationSelected: onSelected,
    destinations: [
      for (final module in modules)
        NavigationDestination(icon: Icon(module.icon), label: module.label),
    ],
  );
}
