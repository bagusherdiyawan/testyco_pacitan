import 'package:flutter/material.dart';
import '../../core/modules/feature_module.dart';
import 'data/orders_repository.dart';
import 'presentation/orders_page.dart';

class OrderingModule implements FeatureModule {
  const OrderingModule({required this.repository, required this.storeId});
  final OrdersRepository repository;
  final String storeId;
  @override
  String get id => 'ordering';
  @override
  String get label => 'Pesanan';
  @override
  IconData get icon => Icons.local_shipping_outlined;
  @override
  Widget buildPage() => OrdersPage(repository: repository, storeId: storeId);
}
