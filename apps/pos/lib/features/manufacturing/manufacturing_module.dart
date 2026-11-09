import 'package:flutter/material.dart';
import '../../core/modules/feature_module.dart';
import 'data/erp_repository.dart';
import 'presentation/erp_page.dart';

class ManufacturingModule implements FeatureModule {
  const ManufacturingModule(this.repository, this.store);
  final ErpRepository repository;
  final String store;
  @override
  String get id => 'manufacturing';
  @override
  String get label => 'Produksi ERP';
  @override
  IconData get icon => Icons.factory_outlined;
  @override
  Widget buildPage() => ErpPage(repository: repository, operatorStore: store);
}
