import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:printing/printing.dart';
import '../../../core/network/api_client.dart';
import '../application/admin_mutation.dart';
import '../data/admin_repository.dart';
import 'admin_form.dart';
import 'admin_widgets.dart';

class ContractsPage extends StatelessWidget {
  const ContractsPage({
    required this.api,
    required this.organization,
    super.key,
  });
  final ApiClient api;
  final String organization;
  @override
  Widget build(BuildContext context) {
    final path = 'organizations/$organization/contracts';
    return AdminList(
      title: 'Kontrak kemitraan',
      load: (p) async {
        final result = await scopedPage(
          api,
          path,
          organization,
          p,
          rowScope: false,
        );
        if ((result['data'] as List).any(
          (r) =>
              r['organization_id'] != organization &&
              r['partner_id'] != organization,
        )) {
          throw const FormatException('Pihak kontrak tidak cocok.');
        }
        return result;
      },
      body: (context, data, reload) => [
        if (data['can_create'] == true)
          FilledButton(
            onPressed: () async {
              try {
                final partners = (await AdminRepository(api).all(
                  'organizations',
                )).where((r) => r['type'] == 'partner' && r['active'] == true);
                if (!context.mounted) return;
                if (await openForm(
                  context,
                  api,
                  'Buat kontrak',
                  path,
                  [
                    choiceField('partner_id', 'Mitra', choices(partners)),
                    textField('title', 'Judul'),
                    choiceField('mode', 'Skema', {
                      'buyout': 'Putus beli',
                      'consignment': 'Konsinyasi',
                    }),
                    dateField('starts_on', 'Mulai'),
                    dateField('ends_on', 'Berakhir'),
                    textField('terms', 'Ketentuan lengkap'),
                    textField('brand_terms', 'Ketentuan merek'),
                    textField('data_scope', 'Lingkup data'),
                    textField('facilities', 'Fasilitas'),
                    const AdminField(
                      'attachment_base64',
                      'Pilih lampiran PDF (opsional)',
                      kind: 'pdf',
                      optional: true,
                    ),
                  ],
                  (r) => documentAck(
                    r,
                    scopeKey: 'organization_id',
                    scope: organization,
                    version: 1,
                    status: 'draft',
                  ),
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
            child: const Text('Buat kontrak'),
          ),
        for (final row in data['data'])
          documentCard(
            row,
            actions: [
              if (row['has_attachment'] == true)
                TextButton(
                  onPressed: () async {
                    try {
                      final bytes = await api.pdf(
                        '$path/${row['id']}/attachment',
                      );
                      if (context.mounted) {
                        Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) => Scaffold(
                              appBar: AppBar(
                                title: const Text('Lampiran kontrak'),
                              ),
                              body: PdfPreview(
                                build: (_) async => Uint8List.fromList(bytes),
                                allowPrinting: false,
                                allowSharing: false,
                                canChangeOrientation: false,
                                canChangePageFormat: false,
                              ),
                            ),
                          ),
                        );
                      }
                    } catch (e) {
                      if (context.mounted) {
                        ScaffoldMessenger.of(
                          context,
                        ).showSnackBar(SnackBar(content: Text('$e')));
                      }
                    }
                  },
                  child: const Text('Baca PDF'),
                ),
              for (final action in [
                if (data['can_create'] == true && row['status'] == 'draft')
                  'publish',
                if (data['can_create'] == true &&
                    ['draft', 'published'].contains(row['status']))
                  'withdraw',
                if (data['can_decide'] == true &&
                    row['status'] == 'published') ...[
                  'accept',
                  'reject',
                ],
              ])
                TextButton(
                  onPressed: () async {
                    if (await openForm(
                      context,
                      api,
                      actionLabel(action),
                      '$path/${row['id']}/$action',
                      [
                        if (['withdraw', 'reject'].contains(action))
                          reasonField,
                        if (action == 'accept') ...[
                          textField('consent_name', 'Nama penyetuju'),
                          consentField,
                        ],
                      ],
                      (r) => documentAck(
                        r,
                        scopeKey: 'organization_id',
                        scope: row['organization_id'],
                        id: row['id'],
                        version: row['version'] + 1,
                        status: {
                          'publish': 'published',
                          'withdraw': 'withdrawn',
                          'accept': 'accepted',
                          'reject': 'rejected',
                        }[action],
                      ),
                      fixed: {'version': row['version']},
                      contextText: documentText(row),
                    )) {
                      reload();
                    }
                  },
                  child: Text(actionLabel(action)),
                ),
            ],
          ),
      ],
    );
  }
}

String actionLabel(String a) =>
    const {
      'publish': 'Terbitkan',
      'withdraw': 'Tarik usulan',
      'accept': 'Setujui',
      'reject': 'Tolak',
      'request_return': 'Ajukan pengembalian',
      'confirm_return': 'Konfirmasi pemeriksaan pengembalian',
    }[a] ??
    a;
