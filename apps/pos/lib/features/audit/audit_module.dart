import 'package:flutter/material.dart';
import '../../core/modules/feature_module.dart';
import 'data/audit_repository.dart';
import 'presentation/audit_page.dart';

class AuditModule implements FeatureModule {
  const AuditModule({required this.repository, required this.organization});
  final AuditRepository repository;
  final String organization;
  @override
  String get id => 'audit';
  @override
  String get label => 'Audit';
  @override
  IconData get icon => Icons.history;
  @override
  Widget buildPage() =>
      AuditPage(repository: repository, organization: organization);
}
