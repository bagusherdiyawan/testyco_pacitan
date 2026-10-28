import 'package:flutter/material.dart';
import '../../core/modules/feature_module.dart';
import 'data/pos_repository.dart';
import 'presentation/pos_page.dart';

class SalesModule implements FeatureModule {
  const SalesModule({required this.repository, required this.storeId});
  final PosRepository repository;
  final String storeId;
  @override
  String get id => 'sales';
  @override
  String get label => 'POS';
  @override
  IconData get icon => Icons.point_of_sale;
  @override
  Widget buildPage() => PosPage(repository: repository, storeId: storeId);
}
