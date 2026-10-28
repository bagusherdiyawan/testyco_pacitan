import 'package:flutter/material.dart';
import '../../core/modules/feature_module.dart';
import 'data/cash_report_repository.dart';
import 'presentation/cash_report_page.dart';

class ReportingModule implements FeatureModule {
  const ReportingModule({
    required this.repository,
    required this.organizationId,
    required this.storeId,
  });
  final CashReportRepository repository;
  final String organizationId, storeId;
  @override
  String get id => 'reporting';
  @override
  String get label => 'Laporan';
  @override
  IconData get icon => Icons.bar_chart;
  @override
  Widget buildPage() => CashReportPage(
    repository: repository,
    organizationId: organizationId,
    storeId: storeId,
  );
}
