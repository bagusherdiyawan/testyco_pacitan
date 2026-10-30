import 'package:flutter/material.dart';
import '../../core/modules/feature_module.dart';
import 'data/warehouse_repository.dart';
import 'presentation/warehouse_inbox_page.dart';

class FulfillmentModule implements FeatureModule {
  const FulfillmentModule({required this.repository, required this.store});
  final WarehouseRepository repository;
  final String store;
  @override
  String get id => 'fulfillment';
  @override
  String get label => 'Gudang';
  @override
  IconData get icon => Icons.local_shipping_outlined;
  @override
  Widget buildPage() =>
      WarehouseInboxPage(repository: repository, store: store);
}
