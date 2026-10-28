import 'package:flutter/material.dart';
import '../../core/modules/feature_module.dart';
import '../../design_system/molecules/status_panel.dart';

class SalesModule implements FeatureModule {
  @override
  String get id => 'sales';
  @override
  String get label => 'POS';
  @override
  IconData get icon => Icons.point_of_sale;
  @override
  Widget buildPage() => const StatusPanel(
    title: 'Kasir toko',
    description:
        'Checkout belum aktif. Identitas, toko, katalog, stok, dan shift harus tersedia sebelum transaksi dapat dibuat.',
  );
}
