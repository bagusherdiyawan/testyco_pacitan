import 'package:flutter/material.dart';
import '../manufacturing/data/erp_repository.dart';
import '../manufacturing/presentation/erp_page.dart';
import '../../core/modules/feature_module.dart';
import '../../core/network/api_client.dart';
import 'data/admin_repository.dart';
import 'presentation/admin_widgets.dart';
import 'presentation/assets_page.dart';
import 'presentation/billing_page.dart';
import 'presentation/commercial_report_page.dart';
import 'presentation/contracts_page.dart';
import 'presentation/vouchers_page.dart';

class AdministrationModule implements FeatureModule {
  const AdministrationModule(this.api);
  final ApiClient api;
  @override
  String get id => 'administration';
  @override
  String get label => 'Administrasi';
  @override
  IconData get icon => Icons.business_center_outlined;
  @override
  Widget buildPage() => AdministrationOrganizations(api: api);
}

class AdministrationAction extends StatelessWidget {
  const AdministrationAction({required this.api, super.key});
  final ApiClient api;
  @override
  Widget build(BuildContext context) => IconButton(
    tooltip: 'Administrasi organisasi',
    icon: const Icon(Icons.business_center_outlined),
    onPressed: () => Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => AdministrationOrganizations(api: api)),
    ),
  );
}

class AdministrationOrganizations extends StatelessWidget {
  const AdministrationOrganizations({required this.api, super.key});
  final ApiClient api;
  @override
  Widget build(BuildContext context) => AdminList(
    title: 'Administrasi organisasi',
    load: (page) async {
      final r = await api.request('GET', 'organizations?page=$page');
      AdminRepository.pageRows(r, page);
      return r;
    },
    body: (context, data, reload) => [
      const Text(
        'Pilih organisasi dengan keanggotaan pengelola aktif. Setiap tindakan diperiksa kembali oleh server.',
      ),
      for (final org in data['data'])
        if (AdminRepository.manages(org))
          ListTile(
            title: Text(org['name']),
            subtitle: Text('${org['role']}'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => AdministrationHome(
                  api: api,
                  organization: Map<String, dynamic>.from(org),
                ),
              ),
            ),
          ),
      if (!(data['data'] as List).any((row) => AdminRepository.manages(row)))
        const Text(
          'Tidak ada organisasi yang dapat dikelola pada halaman ini.',
        ),
    ],
  );
}

class AdministrationHome extends StatelessWidget {
  const AdministrationHome({
    required this.api,
    required this.organization,
    super.key,
  });
  final ApiClient api;
  final Map<String, dynamic> organization;
  @override
  Widget build(BuildContext context) {
    final id = organization['id'] as String;
    final pages = <String, Widget>{
      if (organization['type'] == 'center' &&
          ['owner', 'admin'].contains(organization['role']) &&
          const bool.fromEnvironment(
            'FEATURE_MANUFACTURING',
            defaultValue: true,
          ))
        'Bahan baku dan produksi': ErpPage(repository: ErpRepository(api, id)),
      'Invoice dan konsinyasi': BillingOrdersPage(
        api: api,
        organization: id,
        center: organization['type'] == 'center',
      ),
      'Kontrak kemitraan': ContractsPage(api: api, organization: id),
      'Konfigurasi voucher': VouchersPage(api: api, organization: id),
      'Administrasi aset': AssetsPage(api: api, organization: id),
      'Laporan komersial': CommercialReportPage(api: api, organization: id),
    };
    return Scaffold(
      appBar: AppBar(title: Text('Administrasi ${organization['name']}')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          for (final page in pages.entries)
            Card(
              child: ListTile(
                title: Text(page.key),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(
                  context,
                ).push(MaterialPageRoute(builder: (_) => page.value)),
              ),
            ),
        ],
      ),
    );
  }
}
