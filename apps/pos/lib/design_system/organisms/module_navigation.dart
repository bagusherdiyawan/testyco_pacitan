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
  Widget build(BuildContext context) {
    final overflow = modules.length > 5;
    final visible = overflow ? modules.take(4).toList() : modules;
    return NavigationBar(
      selectedIndex: overflow && selected >= 4 ? 4 : selected,
      onDestinationSelected: (index) async {
        if (!overflow || index < 4) {
          onSelected(index);
          return;
        }
        final chosen = await showModalBottomSheet<int>(
          context: context,
          builder: (context) => SafeArea(
            child: ListView(
              shrinkWrap: true,
              children: [
                for (var i = 4; i < modules.length; i++)
                  ListTile(
                    leading: Icon(modules[i].icon),
                    title: Text(modules[i].label),
                    selected: i == selected,
                    onTap: () => Navigator.pop(context, i),
                  ),
              ],
            ),
          ),
        );
        if (chosen != null && context.mounted) onSelected(chosen);
      },
      destinations: [
        for (final module in visible)
          NavigationDestination(icon: Icon(module.icon), label: module.label),
        if (overflow)
          NavigationDestination(
            icon: const Icon(Icons.more_horiz),
            label: selected >= 4 ? modules[selected].label : 'Lainnya',
          ),
      ],
    );
  }
}
