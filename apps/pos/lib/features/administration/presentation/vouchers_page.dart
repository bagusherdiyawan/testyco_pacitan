import 'package:flutter/material.dart';
import '../../../core/network/api_client.dart';
import '../application/admin_mutation.dart';
import '../data/admin_repository.dart';
import 'admin_form.dart';
import 'admin_widgets.dart';

class VouchersPage extends StatelessWidget {
  const VouchersPage({
    required this.api,
    required this.organization,
    super.key,
  });
  final ApiClient api;
  final String organization;
  @override
  Widget build(BuildContext context) {
    final path = 'organizations/$organization/vouchers';
    return AdminList(
      title: 'Konfigurasi voucher',
      load: (p) => scopedPage(api, path, organization, p),
      body: (context, data, reload) => [
        FilledButton(
          onPressed: () async {
            try {
              final repo = AdminRepository(api);
              final options = await Future.wait([
                repo.all('organizations/$organization/stores'),
                repo.all('organizations/$organization/products'),
              ]);
              if (options
                  .expand((x) => x)
                  .any((r) => r['organization_id'] != organization)) {
                throw const FormatException('Pilihan berbeda organisasi.');
              }
              if (!context.mounted) return;
              if (await openForm(
                context,
                api,
                'Buat voucher',
                path,
                [
                  textField('code', 'Kode (huruf besar / angka / _ / -)'),
                  choiceField(
                    'funding_organization_id',
                    'Pendanaan eksplisit',
                    {organization: 'Organisasi penerbit sendiri'},
                  ),
                  choiceField('kind', 'Jenis diskon', {
                    'fixed': 'Nominal rupiah',
                    'percent': 'Persentase dalam basis poin (100 = 1%)',
                  }),
                  numberField('value', 'Nilai diskon'),
                  numberField('maximum_discount', 'Maksimal diskon (Rp)'),
                  numberField('minimum_subtotal', 'Minimal belanja (Rp)'),
                  numberField('usage_limit', 'Kuota penggunaan'),
                  dateField('starts_on', 'Mulai'),
                  dateField('ends_on', 'Berakhir'),
                  multiField(
                    'store_ids',
                    'Toko berlaku',
                    choices(options[0].where((r) => r['active'] == true)),
                  ),
                  multiField(
                    'product_ids',
                    'Produk berlaku (kosong = semua)',
                    choices(options[1].where((r) => r['active'] == true)),
                    optional: true,
                  ),
                  choiceField('restore_policy', 'Pemulihan kuota', {
                    'never': 'Tidak dipulihkan',
                    'on_full_refund': 'Setelah refund penuh',
                  }),
                  textField('terms', 'Ketentuan voucher'),
                ],
                (r) => documentAck(
                  r,
                  scopeKey: 'organization_id',
                  scope: organization,
                  version: 1,
                ),
                contextText:
                    'Voucher reguler didanai penerbit. Voucher bersponsor mengikuti perjanjian lintas organisasi yang telah disetujui.',
              )) {
                reload();
              }
            } catch (e) {
              if (context.mounted) {
                ScaffoldMessenger.of(
                  context,
                ).showSnackBar(SnackBar(content: Text('$e')));
              }
            }
          },
          child: const Text('Buat voucher reguler'),
        ),
        for (final row in data['data'])
          documentCard(
            row,
            actions: [
              if (row['commercial_agreement_id'] != null)
                const Text(
                  'Voucher bersponsor: ketentuan mengikuti perjanjian yang disetujui.',
                ),
              TextButton(
                onPressed: () async {
                  final active = row['active'] != true;
                  if (await openForm(
                    context,
                    api,
                    active ? 'Aktifkan voucher' : 'Nonaktifkan voucher',
                    '$path/${row['id']}/status',
                    [reasonField],
                    (r) =>
                        documentAck(
                          r,
                          scopeKey: 'organization_id',
                          scope: organization,
                          id: row['id'],
                          version: row['version'] + 1,
                        ) &&
                        r['data']['active'] == active,
                    fixed: {'version': row['version'], 'active': active},
                    contextText: documentText(row),
                  )) {
                    reload();
                  }
                },
                child: Text(row['active'] == true ? 'Nonaktifkan' : 'Aktifkan'),
              ),
            ],
          ),
      ],
    );
  }
}
