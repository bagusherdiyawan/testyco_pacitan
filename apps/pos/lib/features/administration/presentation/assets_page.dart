import 'package:flutter/material.dart';
import '../../../core/network/api_client.dart';
import '../application/admin_mutation.dart';
import '../data/admin_repository.dart';
import 'admin_form.dart';
import 'admin_widgets.dart';
import 'contracts_page.dart';

class AssetsPage extends StatelessWidget {
  const AssetsPage({required this.api, required this.organization, super.key});
  final ApiClient api;
  final String organization;
  @override
  Widget build(BuildContext context) {
    final path = 'organizations/$organization/assets';
    return AdminList(
      title: 'Administrasi aset',
      load: (p) => scopedPage(api, path, organization, p, rowScope: false),
      body: (context, data, reload) => [
        FilledButton(
          onPressed: () async {
            if (await openForm(
              context,
              api,
              'Daftarkan aset',
              path,
              [
                textField('number', 'Nomor aset'),
                choiceField('kind', 'Jenis', {
                  'freezer': 'Freezer',
                  'pos_device': 'Perangkat POS',
                  'other': 'Lainnya',
                }),
                textField('brand', 'Merek'),
                textField('serial_number', 'Nomor seri'),
                textField('condition_note', 'Kondisi'),
                textField('ownership_reference', 'Bukti kepemilikan'),
              ],
              (r) => documentAck(
                r,
                scopeKey: 'organization_id',
                scope: organization,
                version: 1,
                status: 'available',
              ),
            )) {
              reload();
            }
          },
          child: const Text('Daftarkan aset'),
        ),
        for (final row in data['data'])
          documentCard(
            row,
            actions: [
              TextButton(
                onPressed: () async {
                  await Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => AssetDetailPage(api: api, id: row['id']),
                    ),
                  );
                  reload();
                },
                child: const Text('Penempatan, pengembalian dan insiden'),
              ),
            ],
          ),
      ],
    );
  }
}

class AssetDetailPage extends StatefulWidget {
  const AssetDetailPage({required this.api, required this.id, super.key});
  final ApiClient api;
  final String id;
  @override
  State<AssetDetailPage> createState() => _AssetDetailPageState();
}

class _AssetDetailPageState extends State<AssetDetailPage> {
  int incidentsPage = 1;
  @override
  Widget build(BuildContext context) {
    final api = widget.api, path = 'assets/${widget.id}';
    return AdminList(
      title: 'Detail aset',
      load: (p) async {
        final r = await api.request(
          'GET',
          '$path?page=$p&incidents_page=$incidentsPage',
        );
        if (r['is_owner'] is! bool ||
            r['can_confirm'] is! bool ||
            r['asset'] is! Map ||
            r['asset']['id'] != widget.id ||
            r['asset']['version'] is! int) {
          throw const FormatException('Aset tidak cocok.');
        }
        final active = r['active_placement'];
        if (active != null &&
            (active is! Map ||
                active['business_asset_id'] != widget.id ||
                active['active_asset_id'] != widget.id ||
                active['id'] is! String)) {
          throw const FormatException('Penempatan aktif tidak cocok.');
        }
        for (final key in ['placements', 'incidents']) {
          final rows = AdminRepository.pageRows(
            Map<String, dynamic>.from(r[key]),
            key == 'placements' ? p : incidentsPage,
          );
          if (rows.any((x) => x['business_asset_id'] != widget.id)) {
            throw const FormatException('Riwayat aset tidak cocok.');
          }
        }
        return {
          ...r,
          'current_page': p,
          'next_page_url': r['placements']['next_page_url'],
        };
      },
      body: (context, data, reload) {
        final a = data['asset'] as Map;
        final owner = data['is_owner'] == true,
            recipient = data['can_confirm'] == true;
        final p = data['active_placement'] as Map?;
        Future<void> edit(
          String title,
          String suffix,
          List<AdminField> fields, {
          String? childKey,
          String? childId,
          String? childStatus,
        }) async {
          if (await openForm(
            context,
            api,
            title,
            '$path/$suffix',
            fields,
            (r) {
              final d = r['data'];
              if (d is! Map ||
                  d['asset'] is! Map ||
                  d['asset']['id'] != widget.id ||
                  d['asset']['version'] != a['version'] + 1 ||
                  d['asset']['organization_id'] != a['organization_id']) {
                return false;
              }
              if (childKey != null) {
                final child = d[childKey];
                if (child is! Map ||
                    child['business_asset_id'] != widget.id ||
                    child['id'] is! String ||
                    (childId != null && child['id'] != childId) ||
                    (childStatus != null && child['status'] != childStatus)) {
                  return false;
                }
              }
              return true;
            },
            fixed: {'version': a['version']},
            contextText:
                '${documentText(a)}\n${p == null ? '' : documentText(p)}',
          )) {
            reload();
          }
        }

        return [
          documentCard(a),
          if (owner && a['status'] == 'available' && p == null)
            FilledButton(
              onPressed: () => edit(
                'Usulkan penempatan',
                'placements',
                [
                  choiceField(
                    'store_id',
                    'Toko penerima',
                    choices(data['placement_options']['stores']),
                  ),
                  choiceField(
                    'contract_id',
                    'Kontrak lintas organisasi (wajib untuk mitra)',
                    choices(
                      data['placement_options']['contracts'],
                      label: 'title',
                    ),
                    optional: true,
                  ),
                  textField('responsible_name', 'Penanggung jawab'),
                  dateField('handed_over_on', 'Serah terima'),
                  textField('condition_note', 'Kondisi fisik'),
                  textField('agreement_reference', 'Rujukan kesepakatan'),
                  textField('evidence_reference', 'Bukti serah terima'),
                ],
                childKey: 'placement',
                childStatus: 'pending',
              ),
              child: const Text('Usulkan penempatan'),
            ),
          if (p != null)
            documentCard(
              p,
              actions: [
                for (final action in [
                  if (p['status'] == 'pending' && recipient) ...[
                    'accept',
                    'reject',
                  ],
                  if (p['status'] == 'pending' && owner) 'withdraw',
                  if (p['status'] == 'active' && recipient) 'request_return',
                  if (p['status'] == 'return_pending' && owner)
                    'confirm_return',
                ])
                  TextButton(
                    onPressed: () => edit(
                      actionLabel(action),
                      'placements/${p['id']}/$action',
                      [
                        reasonField,
                        if (['accept', 'confirm_return'].contains(action))
                          consentField,
                        if (action == 'request_return') ...[
                          dateField('returned_on', 'Pengembalian fisik'),
                          textField('return_condition', 'Kondisi kembali'),
                          textField('return_reference', 'Bukti pengembalian'),
                        ],
                        if (action == 'confirm_return') ...[
                          choiceField('disposition', 'Hasil pemeriksaan', {
                            'available': 'Tersedia',
                            'maintenance': 'Perawatan',
                          }),
                          textField(
                            'inspection_note',
                            'Hasil pemeriksaan fisik',
                          ),
                        ],
                      ],
                      childKey: 'placement',
                      childId: p['id'],
                      childStatus: {
                        'accept': 'active',
                        'reject': 'rejected',
                        'withdraw': 'withdrawn',
                        'request_return': 'return_pending',
                        'confirm_return': 'returned',
                      }[action],
                    ),
                    child: Text(actionLabel(action)),
                  ),
              ],
            ),
          if ((owner || recipient) && p?['status'] != 'pending')
            FilledButton(
              onPressed: () => edit(
                'Laporkan insiden',
                'incidents',
                [
                  textField('description', 'Uraian insiden'),
                  textField('evidence_reference', 'Bukti'),
                  dateField('reported_on', 'Tanggal kejadian'),
                ],
                childKey: 'incident',
                childStatus: 'open',
              ),
              child: const Text('Laporkan insiden'),
            ),
          const Text('Riwayat penempatan'),
          for (final row in data['placements']['data']) documentCard(row),
          const Text('Insiden'),
          for (final row in data['incidents']['data'])
            documentCard(
              row,
              actions: [
                if (owner && row['status'] == 'open')
                  TextButton(
                    onPressed: () => edit(
                      'Selesaikan insiden',
                      'incidents/${row['id']}/resolve',
                      [
                        textField('resolution', 'Penyelesaian'),
                        textField(
                          'cost_basis_reference',
                          'Dasar biaya yang disepakati',
                        ),
                        numberField(
                          'owner_cost',
                          'Biaya pemilik (Rp, isi nol jika sesuai)',
                        ),
                        numberField(
                          'recipient_cost',
                          'Biaya penerima (Rp, isi nol jika sesuai)',
                        ),
                        dateField('completed_on', 'Selesai'),
                      ],
                      childKey: 'incident',
                      childId: row['id'],
                      childStatus: 'resolved',
                    ),
                    child: const Text('Selesaikan insiden'),
                  ),
              ],
            ),
          Wrap(
            children: [
              TextButton(
                onPressed: incidentsPage > 1
                    ? () {
                        incidentsPage--;
                        reload();
                      }
                    : null,
                child: const Text('Insiden sebelumnya'),
              ),
              Text('Insiden halaman $incidentsPage'),
              TextButton(
                onPressed: data['incidents']['next_page_url'] != null
                    ? () {
                        incidentsPage++;
                        reload();
                      }
                    : null,
                child: const Text('Insiden berikutnya'),
              ),
            ],
          ),
        ];
      },
    );
  }
}
