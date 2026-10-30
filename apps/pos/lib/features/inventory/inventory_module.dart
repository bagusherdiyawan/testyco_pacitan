import 'package:flutter/material.dart';
import '../../core/modules/feature_module.dart';
import 'data/inventory_repository.dart';
import 'presentation/inventory_page.dart';

class InventoryModule implements FeatureModule {
  const InventoryModule({
    required this.repository,
    required this.storeId,
    this.canControl = false,
    this.canIntake = false,
  });
  final InventoryRepository repository;
  final String storeId;
  final bool canControl, canIntake;
  @override
  String get id => 'inventory';
  @override
  String get label => 'Stok';
  @override
  IconData get icon => Icons.inventory_2_outlined;
  @override
  Widget buildPage() => InventoryPage(
    repository: repository,
    storeId: storeId,
    canControl: canControl,
    canIntake: canIntake,
  );
}
