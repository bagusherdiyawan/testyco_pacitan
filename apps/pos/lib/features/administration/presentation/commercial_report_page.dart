import 'dart:convert';
import 'package:flutter/material.dart';
import '../../../core/domain/wib_date_range.dart';
import '../../../core/network/api_client.dart';
import 'admin_form.dart';
import 'admin_widgets.dart';

class CommercialReportPage extends StatefulWidget {
  const CommercialReportPage({
    required this.api,
    required this.organization,
    super.key,
  });
  final ApiClient api;
  final String organization;
  @override
  State<CommercialReportPage> createState() => _CommercialReportPageState();
}

class _CommercialReportPageState extends State<CommercialReportPage> {
  final from = TextEditingController(), to = TextEditingController();
  Map<String, dynamic>? report;
  WibDateRange? applied;
  String? error;
  int page = 1;
  bool busy = false;
  @override
  void dispose() {
    from.dispose();
    to.dispose();
    super.dispose();
  }

  Future<void> load({bool refresh = false}) async {
    if (busy) return;
    setState(() {
      busy = true;
      error = null;
      report = null;
    });
    try {
      final period = refresh ? applied! : WibDateRange.form(from.text, to.text);
      final query = Uri(queryParameters: period.params).query;
      final r = await widget.api.request(
        'GET',
        'organizations/${widget.organization}/reports/commercial?$query',
      );
      if (r['organization_id'] != widget.organization ||
          r['currency'] != 'IDR' ||
          !['seller', 'buyer'].contains(r['side']) ||
          r['basis'] != 'document_cohort_current_adjustments' ||
          r['balances_scope'] != 'accepted_invoices_in_selected_period' ||
          r['data'] is! List ||
          (r['data'] as List).length > 10000 ||
          utf8.encode(jsonEncode(r['data'])).length > 8 * 1024 * 1024 ||
          r['metrics'] is! Map ||
          r['balances'] is! Map ||
          (r['data'] as List).any(
            (row) =>
                row['organization_id'] != widget.organization ||
                row['id'] is! String ||
                row['cost_version'] is! int ||
                [
                  'gross_amount',
                  'discount_amount',
                  'refund_credit_amount',
                  'net_goods_amount',
                ].any((k) => row[k] is! int) ||
                ['goods_cost', 'operating_cost', 'profit_amount'].any(
                  (k) =>
                      !row.containsKey(k) || (row[k] != null && row[k] is! int),
                ) ||
                ![
                  'pos_sale',
                  r['side'] == 'seller'
                      ? 'wholesale_invoice'
                      : 'purchase_invoice',
                ].contains(row['source_type']),
          )) {
        throw const FormatException('Lingkup laporan tidak cocok.');
      }
      report = r;
      applied = period;
      page = 1;
    } catch (e) {
      error = '$e';
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Laporan komersial')),
    body: Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 1000),
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            const Text(
              'Periode dokumen dalam WIB (maksimal 31 hari), dengan penyesuaian kumulatif saat laporan dibaca. Laba belum diketahui jika bukti biaya belum lengkap. Saldo hanya invoice diterima dalam periode, bukan seluruh piutang organisasi.',
            ),
            TextField(
              controller: from,
              decoration: const InputDecoration(
                labelText: 'Mulai (YYYY-MM-DD)',
              ),
            ),
            TextField(
              controller: to,
              decoration: const InputDecoration(
                labelText: 'Sampai inklusif (YYYY-MM-DD)',
              ),
            ),
            FilledButton(
              onPressed: busy ? null : load,
              child: const Text('Tampilkan laporan'),
            ),
            if (busy) const LinearProgressIndicator(),
            if (error != null) Text(error!),
            if (report != null) ...[
              Text(
                'Periode ${WibDateRange.day(applied!.from)} – ${WibDateRange.day(applied!.to)} WIB; dibaca ${report!['observed_at']}',
              ),
              for (final type in [
                'pos_sale',
                'wholesale_invoice',
                'purchase_invoice',
              ]) ...[
                Text(
                  {
                    'pos_sale': 'Penjualan POS',
                    'wholesale_invoice': 'Penjualan grosir',
                    'purchase_invoice': 'Pembelian grosir',
                  }[type]!,
                ),
                documentCard(report!['metrics'][type]),
              ],
              documentCard(report!['balances']),
              for (final row
                  in (report!['data'] as List).skip((page - 1) * 50).take(50))
                documentCard(
                  row,
                  actions: [
                    if (row['source_type'] != 'purchase_invoice')
                      TextButton(
                        onPressed: () async {
                          if (await openForm(
                            context,
                            widget.api,
                            'Nyatakan bukti biaya',
                            'organizations/${widget.organization}/reports/commercial/costs',
                            [
                              numberField(
                                'goods_cost',
                                'Biaya barang (Rp, isi nol bila sesuai)',
                              ),
                              numberField(
                                'operating_cost',
                                'Biaya operasional (Rp, isi nol bila sesuai)',
                              ),
                              textField(
                                'evidence_reference',
                                'Rujukan bukti biaya',
                              ),
                              reasonField,
                            ],
                            (r) {
                              final d = r['data'];
                              return d is Map &&
                                  d['id'] is String &&
                                  d['organization_id'] == widget.organization &&
                                  d['source_id'] == row['id'] &&
                                  d['source_type'] == row['source_type'] &&
                                  d['version'] == row['cost_version'] + 1;
                            },
                            fixed: {
                              'source_type': row['source_type'],
                              'source_id': row['id'],
                              'version': row['cost_version'],
                            },
                            contextText: documentText(row),
                          )) {
                            await load(refresh: true);
                          }
                        },
                        child: const Text('Nyatakan bukti biaya'),
                      ),
                  ],
                ),
              Wrap(
                children: [
                  TextButton(
                    onPressed: page > 1 ? () => setState(() => page--) : null,
                    child: const Text('Sebelumnya'),
                  ),
                  Text('Halaman $page'),
                  TextButton(
                    onPressed: page * 50 < (report!['data'] as List).length
                        ? () => setState(() => page++)
                        : null,
                    child: const Text('Berikutnya'),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    ),
  );
}
