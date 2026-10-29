import 'package:flutter/material.dart';
import '../../core/modules/feature_module.dart';
import 'data/shifts_repository.dart';
import 'presentation/shifts_page.dart';

class ShiftsModule implements FeatureModule {
  const ShiftsModule({required this.repository, required this.storeId});
  final ShiftsRepository repository;
  final String storeId;
  @override
  String get id => 'shifts';
  @override
  String get label => 'Kas shift';
  @override
  IconData get icon => Icons.account_balance_wallet_outlined;
  @override
  Widget buildPage() => ShiftsPage(repository: repository, storeId: storeId);
}
