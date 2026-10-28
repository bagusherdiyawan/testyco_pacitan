import 'package:flutter/material.dart';
import '../../core/modules/feature_module.dart';
import 'data/catalog_repository.dart';
import 'presentation/catalog_page.dart';

class CatalogModule implements FeatureModule {
  const CatalogModule({required this.repository, required this.storeId});
  final CatalogRepository repository;
  final String storeId;
  @override
  String get id => 'catalog';
  @override
  String get label => 'Katalog';
  @override
  IconData get icon => Icons.restaurant_menu;
  @override
  Widget buildPage() => CatalogPage(repository: repository, storeId: storeId);
}
