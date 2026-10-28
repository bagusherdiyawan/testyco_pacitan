import 'package:flutter/material.dart';

abstract interface class FeatureModule {
  String get id;
  String get label;
  IconData get icon;
  Widget buildPage();
}

class ModuleRegistry {
  ModuleRegistry(List<FeatureModule> modules)
    : modules = List.unmodifiable(modules) {
    if (modules.map((m) => m.id).toSet().length != modules.length) {
      throw ArgumentError('Duplicate feature module');
    }
  }
  final List<FeatureModule> modules;
}
