import 'package:flutter/material.dart';
import '../../core/modules/feature_module.dart';
import '../organisms/module_navigation.dart';

class ResponsiveShell extends StatefulWidget {
  const ResponsiveShell({
    required this.registry,
    this.title = 'Testyco POS',
    this.actions,
    super.key,
  });
  final ModuleRegistry registry;
  final String title;
  final List<Widget>? actions;
  @override
  State<ResponsiveShell> createState() => _ResponsiveShellState();
}

class _ResponsiveShellState extends State<ResponsiveShell> {
  int selected = 0;
  @override
  Widget build(BuildContext context) {
    final modules = widget.registry.modules;
    if (modules.isEmpty) {
      return const Scaffold(body: Center(child: Text('Tidak ada modul aktif')));
    }
    final index = selected < modules.length ? selected : 0;
    return LayoutBuilder(
      builder: (context, constraints) {
        final tablet = constraints.maxWidth >= 720;
        final content = modules[index].buildPage();
        return Scaffold(
          appBar: AppBar(title: Text(widget.title), actions: widget.actions),
          body: tablet && modules.length > 1
              ? Row(
                  children: [
                    NavigationRail(
                      selectedIndex: index,
                      onDestinationSelected: (value) =>
                          setState(() => selected = value),
                      labelType: NavigationRailLabelType.all,
                      destinations: [
                        for (final m in modules)
                          NavigationRailDestination(
                            icon: Icon(m.icon),
                            label: Text(m.label),
                          ),
                      ],
                    ),
                    Expanded(child: content),
                  ],
                )
              : content,
          bottomNavigationBar: tablet || modules.length < 2
              ? null
              : ModuleNavigation(
                  modules: modules,
                  selected: index,
                  onSelected: (value) => setState(() => selected = value),
                ),
        );
      },
    );
  }
}
