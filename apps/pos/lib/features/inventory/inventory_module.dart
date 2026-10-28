import 'package:flutter/material.dart';
import '../../core/modules/feature_module.dart';
import 'data/inventory_repository.dart';
import 'presentation/inventory_page.dart';

class InventoryModule implements FeatureModule {
  const InventoryModule({required this.repository, required this.storeId});
  final InventoryRepository repository;
  final String storeId;
  @override
  String get id => 'inventory';
  @override
  String get label => 'Stok';
  @override
  IconData get icon => Icons.inventory_2_outlined;
  @override
  Widget buildPage() => InventoryPage(repository: repository, storeId: storeId);
}
