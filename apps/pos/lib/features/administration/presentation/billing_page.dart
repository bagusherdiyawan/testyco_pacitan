import 'package:flutter/material.dart';
import '../../../core/network/api_client.dart';
import '../application/admin_mutation.dart';
import '../data/admin_repository.dart';
import 'admin_form.dart';
import 'admin_widgets.dart';
import 'contracts_page.dart';

class BillingOrdersPage extends StatelessWidget {
  const BillingOrdersPage({
    required this.api,
    required this.organization,
    required this.center,
    this.store,
    super.key,
  });
  final ApiClient api;
  final String organization;
  final bool center;
  final String? store;
  @override
  Widget build(BuildContext context) {
    if (!center && store == null) {
      return AdminList(
        title: 'Pilih toko untuk invoice',
        load: (page) async {
          final r = await api.request(
            'GET',
            'organizations/$organization/stores?page=$page',
          );
          final rows = AdminRepository.pageRows(r, page);
          if (rows.any((row) => row['organization_id'] != organization)) {
            throw const FormatException('Lingkup toko tidak cocok.');
          }
          return r;
        },
        body: (context, data, reload) => [
          for (final row in data['data'])
            if (row['organization_id'] == organization)
              ListTile(
                title: Text(row['name']),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) => BillingOrdersPage(
                      api: api,
                      organization: organization,
                      center: false,
                      store: row['id'],
                    ),
                  ),
                ),
              ),
        ],
      );
    }
    final path = center ? 'supply/orders' : 'stores/$store/orders';
    return AdminList(
      title: 'Invoice per pesanan grosir',
      load: (page) async {
        final r = await api.request('GET', '$path?page=$page');
        final rows = AdminRepository.pageRows(r, page);
        if (!center &&
            rows.any(
              (x) =>
                  x['organization_id'] != organization ||
                  x['store_id'] != store,
            )) {
          throw const FormatException('Lingkup pesanan berbeda.');
        }
        return {
          ...r,
          'data': center
              ? rows
                    .where((x) => x['supplier_organization_id'] == organization)
                    .toList()
              : rows,
        };
      },
      body: (context, data, reload) => [
        for (final row in data['data'])
          if (row['kind'] == 'wholesale' && row['approved_at'] != null)
            ListTile(
              title: Text(row['number'] ?? row['id']),
              subtitle: Text('${row['status']}'),
              onTap: () => Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (_) => BillingPage(
                    api: api,
                    organization: organization,
                    order: row['id'],
                  ),
                ),
              ),
            ),
      ],
    );
  }
}

class BillingPage extends StatefulWidget {
  const BillingPage({
    required this.api,
    required this.organization,
    required this.order,
    super.key,
  });
  final ApiClient api;
  final String organization, order;
  @override
  State<BillingPage> createState() => _BillingPageState();
}

class _BillingPageState extends State<BillingPage> {
  int reportsPage = 1;
  @override
  Widget build(BuildContext context) {
    final api = widget.api,
        order = widget.order,
        path = 'orders/${widget.order}/billing';
    return AdminList(
      title: 'Invoice dan laporan konsinyasi',
      load: (page) async {
        final r = await api.request(
          'GET',
          '$path?page=$page&reports_page=$reportsPage',
        );
        if (r['can_create'] is! bool ||
            r['can_confirm'] is! bool ||
            r['order_id'] != order ||
            ![
              r['organization_id'],
              r['partner_id'],
            ].contains(widget.organization)) {
          throw const FormatException('Lingkup penagihan tidak cocok.');
        }
        for (final key in ['invoices', 'reports']) {
          final rows = AdminRepository.pageRows(
            Map<String, dynamic>.from(r[key]),
            key == 'invoices' ? page : reportsPage,
          );
          if (rows.any(
            (x) =>
                x['supply_order_id'] != order ||
                x['version'] is! int ||
                x['lines'] is! List ||
                (key == 'invoices' &&
                    (x['organization_id'] != r['organization_id'] ||
                        x['partner_id'] != r['partner_id'])),
          )) {
            throw const FormatException('Dokumen pesanan tidak cocok.');
          }
        }
        for (final invoice in r['invoices']['data']) {
          for (final key in ['payments', 'credits']) {
            if (invoice[key] is! List ||
                (invoice[key] as List).any(
                  (x) => x['invoice_id'] != invoice['id'],
                )) {
              throw const FormatException('Rincian invoice tidak cocok.');
            }
          }
        }
        for (final key in ['shipments', 'cases', 'order_lines']) {
          if (r[key] is! List ||
              (r[key] as List).any((x) => x['supply_order_id'] != order)) {
            throw const FormatException('Rujukan pesanan tidak cocok.');
          }
        }
        return {
          ...r,
          'current_page': page,
          'next_page_url': r['invoices']['next_page_url'],
        };
      },
      body: (context, data, reload) {
        final source = data['can_create'] == true,
            target = data['can_confirm'] == true;
        bool invoiceAck(Map<String, dynamic> r, Map row, {String? status}) =>
            documentAck(
              r,
              scopeKey: 'supply_order_id',
              scope: order,
              id: row['id'],
              version: row['version'] + 1,
              status: status,
            );
        Future<void> mutate(
          String title,
          String suffix,
          List<AdminField> fields,
          bool Function(Map<String, dynamic>) ack, {
          Map<String, dynamic> fixed = const {},
          String snapshot = '',
          Map<String, dynamic> Function(Map<String, dynamic>)? transform,
        }) async {
          if (await openForm(
            context,
            api,
            title,
            '$path/$suffix',
            fields,
            ack,
            fixed: fixed,
            contextText: snapshot,
            transform: transform,
          )) {
            reload();
          }
        }

        return [
          const Text(
            'Proposisi memerlukan persetujuan pihak lawan. Pembayaran dicatat setelah bukti diverifikasi; tidak mengirim dana ke rekening.',
          ),
          if (source)
            FilledButton(
              onPressed: () => mutate(
                'Usulkan invoice',
                'invoices',
                [
                  choiceField('mode', 'Skema', {
                    'buyout': 'Putus beli',
                    'consignment': 'Konsinyasi',
                  }),
                  choiceField('basis', 'Dasar tagihan', {
                    'dispatched': 'Dikirim',
                    'good_received': 'Diterima baik',
                    'consignment_report': 'Laporan konsinyasi disetujui',
                  }),
                  multiField('shipment_ids', 'Kiriman (putus beli)', {
                    for (final s in data['shipments'])
                      s['id']:
                          '${s['number'] ?? s['id']} · ${s['dispatched_at'] ?? ''}',
                  }, optional: true),
                  choiceField(
                    'report_id',
                    'Laporan konsinyasi disetujui',
                    choices(
                      (data['reports']['data'] as List).where(
                        (r) => r['status'] == 'accepted',
                      ),
                      label: 'period_start',
                    ),
                    optional: true,
                  ),
                  dateField('due_on', 'Jatuh tempo'),
                  numberField(
                    'discount_amount',
                    'Diskon (Rp, isi nol bila sesuai)',
                  ),
                  numberField(
                    'shipping_amount',
                    'Ongkir (Rp, isi nol bila sesuai)',
                  ),
                  numberField(
                    'tax_basis_points',
                    'Pajak (100 basis poin = 1%, isi nol bila sesuai)',
                  ),
                  choiceField('rounding', 'Pembulatan', {
                    'half_up': 'Setengah ke atas',
                  }),
                  textField('note', 'Catatan'),
                ],
                (r) => documentAck(
                  r,
                  scopeKey: 'supply_order_id',
                  scope: order,
                  version: 1,
                  status: 'pending',
                ),
              ),
              child: const Text('Usulkan invoice'),
            ),
          if (target && (data['contracts'] as List).isNotEmpty)
            FilledButton(
              onPressed: () => mutate(
                'Laporan penjualan konsinyasi manual',
                'reports',
                [
                  choiceField(
                    'contract_id',
                    'Kontrak',
                    choices(data['contracts'], label: 'title'),
                  ),
                  dateField('period_start', 'Awal periode'),
                  dateField('period_end', 'Akhir periode'),
                  textField('note', 'Catatan'),
                  for (final line in data['order_lines'])
                    numberField(
                      'qty_${line['id']}',
                      '${line['sku']} ${line['name']} terjual (potong, isi nol jika tidak terjual)',
                    ),
                ],
                (r) => documentAck(
                  r,
                  scopeKey: 'supply_order_id',
                  scope: order,
                  version: 1,
                  status: 'pending',
                ),
                transform: (p) {
                  final lines = <Map<String, dynamic>>[];
                  for (final line in data['order_lines']) {
                    final qty = p.remove('qty_${line['id']}');
                    if (qty > 0) {
                      lines.add({
                        'order_line_id': line['id'],
                        'quantity_pieces': qty,
                      });
                    }
                  }
                  if (lines.isEmpty) {
                    throw const FormatException(
                      'Isi setidaknya satu produk terjual.',
                    );
                  }
                  return {...p, 'lines': lines};
                },
              ),
              child: const Text('Laporkan penjualan konsinyasi manual'),
            ),
          for (final row in data['invoices']['data']) ...[
            documentCard(
              row,
              actions: [
                if (row['status'] == 'pending')
                  for (final action in [
                    if (source) 'withdraw',
                    if (target) ...['accept', 'reject'],
                  ])
                    TextButton(
                      onPressed: () => mutate(
                        actionLabel(action),
                        'invoices/${row['id']}/$action',
                        [reasonField, if (action == 'accept') consentField],
                        (r) => invoiceAck(
                          r,
                          row,
                          status: {
                            'accept': 'accepted',
                            'reject': 'rejected',
                            'withdraw': 'withdrawn',
                          }[action],
                        ),
                        fixed: {'version': row['version']},
                        snapshot: documentText(row),
                      ),
                      child: Text(actionLabel(action)),
                    ),
                if (source && row['status'] == 'accepted') ...[
                  TextButton(
                    onPressed: () => mutate(
                      'Catat pembayaran terverifikasi',
                      'invoices/${row['id']}/payments',
                      [
                        choiceField('kind', 'Alokasi', {
                          'collection': 'Penerimaan',
                          'credit_refund': 'Pengembalian kredit pelanggan',
                        }),
                        choiceField('method', 'Metode', {
                          'cash': 'Tunai',
                          'bank_transfer': 'Transfer terverifikasi',
                          'qris_manual': 'QRIS terverifikasi manual',
                        }),
                        numberField('amount', 'Nominal (Rp)'),
                        textField('reference', 'Referensi bukti'),
                        dateField('paid_on', 'Tanggal pembayaran'),
                        const AdminField(
                          'verified',
                          'Saya telah memverifikasi bukti pembayaran',
                          kind: 'consent',
                        ),
                        textField('note', 'Catatan'),
                      ],
                      (r) => invoiceAck(r, row, status: 'accepted'),
                      fixed: {'version': row['version']},
                      snapshot: documentText(row),
                    ),
                    child: const Text('Catat pembayaran'),
                  ),
                  if ((data['cases'] as List).isNotEmpty)
                    TextButton(
                      onPressed: () => mutate(
                        'Usulkan nota kredit',
                        'invoices/${row['id']}/credits',
                        [
                          choiceField('case_id', 'Kasus kiriman', {
                            for (final c in data['cases'])
                              c['id']:
                                  '${c['status']} · ${c['created_at'] ?? c['id']}',
                          }),
                          numberField('base_amount', 'Pokok kredit (Rp)'),
                          numberField(
                            'tax_amount',
                            'Pajak kredit (Rp, isi nol bila sesuai)',
                          ),
                          reasonField,
                        ],
                        (r) => invoiceAck(r, row, status: 'accepted'),
                        fixed: {'version': row['version']},
                        snapshot: documentText(row),
                      ),
                      child: const Text('Usulkan nota kredit'),
                    ),
                ],
              ],
            ),
            for (final p in row['payments'])
              documentCard(
                p,
                actions: [
                  if (p['invoice_id'] == row['id'] &&
                      source &&
                      p['status'] == 'verified')
                    TextButton(
                      onPressed: () => mutate(
                        'Balik pencatatan pembayaran',
                        'invoices/${row['id']}/payments/${p['id']}/reverse',
                        [reasonField],
                        (r) => invoiceAck(r, row),
                        fixed: {'version': row['version']},
                        snapshot: '${documentText(row)}\n${documentText(p)}',
                      ),
                      child: const Text('Balik pencatatan'),
                    ),
                ],
              ),
            for (final c in row['credits'])
              documentCard(
                c,
                actions: [
                  if (c['invoice_id'] == row['id'] && c['status'] == 'pending')
                    for (final action in [
                      if (source) 'withdraw',
                      if (target) ...['accept', 'reject'],
                    ])
                      TextButton(
                        onPressed: () => mutate(
                          '${actionLabel(action)} nota kredit',
                          'invoices/${row['id']}/credits/${c['id']}/$action',
                          [reasonField, if (action == 'accept') consentField],
                          (r) => invoiceAck(r, row),
                          fixed: {'version': row['version']},
                          snapshot: '${documentText(row)}\n${documentText(c)}',
                        ),
                        child: Text('${actionLabel(action)} nota kredit'),
                      ),
                ],
              ),
          ],
          const Text('Laporan konsinyasi manual'),
          for (final row in data['reports']['data'])
            documentCard(
              row,
              actions: [
                if (row['status'] == 'pending')
                  for (final action in [
                    if (target) 'withdraw',
                    if (source) ...['accept', 'reject'],
                  ])
                    TextButton(
                      onPressed: () => mutate(
                        '${actionLabel(action)} laporan',
                        'reports/${row['id']}/$action',
                        [reasonField, if (action == 'accept') consentField],
                        (r) => documentAck(
                          r,
                          scopeKey: 'supply_order_id',
                          scope: order,
                          id: row['id'],
                          version: row['version'] + 1,
                          status: {
                            'accept': 'accepted',
                            'reject': 'rejected',
                            'withdraw': 'withdrawn',
                          }[action],
                        ),
                        fixed: {'version': row['version']},
                        snapshot: documentText(row),
                      ),
                      child: Text(actionLabel(action)),
                    ),
              ],
            ),
          Wrap(
            children: [
              TextButton(
                onPressed: reportsPage > 1
                    ? () {
                        reportsPage--;
                        reload();
                      }
                    : null,
                child: const Text('Laporan sebelumnya'),
              ),
              Text('Laporan halaman $reportsPage'),
              TextButton(
                onPressed: data['reports']['next_page_url'] != null
                    ? () {
                        reportsPage++;
                        reload();
                      }
                    : null,
                child: const Text('Laporan berikutnya'),
              ),
            ],
          ),
        ];
      },
    );
  }
}
