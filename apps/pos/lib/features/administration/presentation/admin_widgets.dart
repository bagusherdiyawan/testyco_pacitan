import 'package:flutter/material.dart';
import '../../../design_system/molecules/document_summary.dart';
import '../../../core/network/api_client.dart';
import '../data/admin_repository.dart';
import 'admin_form.dart';

Map<String, String> choices(Iterable rows, {String label = 'name'}) => {
  for (final row in rows)
    row['id'] as String:
        '${row[label] ?? row['number'] ?? row['sku'] ?? row['id']}',
};

const documentLabels = <String, String>{
  'title': 'Judul',
  'number': 'Nomor',
  'code': 'Kode',
  'name': 'Nama',
  'sku': 'SKU',
  'status': 'Status',
  'version': 'Versi',
  'mode': 'Skema',
  'basis': 'Dasar',
  'starts_on': 'Mulai',
  'ends_on': 'Berakhir',
  'terms': 'Ketentuan',
  'brand_terms': 'Ketentuan merek',
  'data_scope': 'Lingkup data',
  'facilities': 'Fasilitas',
  'consent_name': 'Nama penyetuju',
  'decision_note': 'Catatan keputusan',
  'terms_sha256': 'Sidik dokumen SHA256',
  'attachment_sha256': 'Sidik PDF SHA256',
  'kind': 'Jenis',
  'brand': 'Merek',
  'serial_number': 'Nomor seri',
  'condition_note': 'Kondisi',
  'ownership_reference': 'Bukti kepemilikan',
  'store_name': 'Toko',
  'responsible_name': 'Penanggung jawab',
  'handed_over_on': 'Tanggal serah terima',
  'agreement_reference': 'Rujukan kesepakatan',
  'evidence_reference': 'Rujukan bukti',
  'returned_on': 'Tanggal pengembalian',
  'return_condition': 'Kondisi pengembalian',
  'return_reference': 'Bukti pengembalian',
  'inspection_note': 'Hasil pemeriksaan',
  'disposition': 'Status setelah pemeriksaan',
  'description': 'Uraian',
  'reported_on': 'Tanggal laporan',
  'resolution': 'Penyelesaian',
  'cost_basis_reference': 'Dasar biaya',
  'owner_cost': 'Biaya pemilik',
  'recipient_cost': 'Biaya penerima',
  'completed_on': 'Tanggal selesai',
  'due_on': 'Jatuh tempo',
  'subtotal': 'Subtotal (Rp)',
  'discount_amount': 'Diskon (Rp)',
  'shipping_amount': 'Ongkir (Rp)',
  'tax_basis_points': 'Pajak (basis poin)',
  'tax_amount': 'Pajak (Rp)',
  'total_amount': 'Total (Rp)',
  'rounding': 'Pembulatan',
  'note': 'Catatan',
  'credit_amount': 'Kredit (Rp)',
  'collected_amount': 'Diterima (Rp)',
  'refunded_amount': 'Dikembalikan (Rp)',
  'signed_balance': 'Saldo bertanda (Rp)',
  'outstanding_amount': 'Piutang / utang (Rp)',
  'customer_credit_amount': 'Kredit pelanggan (Rp)',
  'base_amount': 'Pokok kredit (Rp)',
  'method': 'Metode',
  'amount': 'Nominal (Rp)',
  'reference': 'Referensi',
  'paid_on': 'Tanggal pembayaran',
  'reason': 'Alasan',
  'period_start': 'Awal periode',
  'period_end': 'Akhir periode',
  'quantity_packs': 'Jumlah kemasan',
  'quantity_pieces': 'Jumlah potong',
  'unit_price': 'Harga satuan',
  'line_total': 'Total baris',
  'pack_size': 'Isi kemasan',
  'active': 'Aktif',
  'value': 'Nilai (Rp / basis poin sesuai jenis)',
  'maximum_discount': 'Batas diskon (Rp)',
  'minimum_subtotal': 'Minimum belanja (Rp)',
  'usage_limit': 'Kuota',
  'used_count': 'Terpakai',
  'restore_policy': 'Pemulihan kuota',
  'source_type': 'Jenis dokumen',
  'occurred_at': 'Waktu dokumen',
  'gross_amount': 'Bruto (Rp)',
  'refund_credit_amount': 'Refund / kredit (Rp)',
  'net_goods_amount': 'Barang bersih (Rp)',
  'goods_cost': 'Biaya barang (Rp)',
  'operating_cost': 'Biaya operasional (Rp)',
  'profit_amount': 'Laba tercakup (Rp)',
  'cost_version': 'Versi biaya',
  'cost_evidence': 'Bukti biaya',
  'document_count': 'Jumlah dokumen',
  'costed_document_count': 'Dokumen dengan biaya',
  'covered_profit_amount': 'Laba dokumen berbiaya (Rp)',
  'overdue_amount': 'Lewat jatuh tempo (Rp)',
};
String documentText(Map row) => documentLabels.entries
    .where((e) => row.containsKey(e.key))
    .map((e) => '${e.value}: ${row[e.key] ?? 'Belum diketahui'}')
    .join('\n');
Widget documentCard(Map row, {List<Widget> actions = const []}) =>
    DocumentSummary(
      text: documentText(row),
      details: [
        for (final line in row['lines'] as List? ?? [])
          Text(documentText(line)),
      ],
      actions: actions,
    );

Future<bool> openForm(
  BuildContext context,
  ApiClient api,
  String title,
  String path,
  List<AdminField> fields,
  bool Function(Map<String, dynamic>) accept, {
  Map<String, dynamic> fixed = const {},
  String contextText = '',
  Map<String, dynamic> Function(Map<String, dynamic>)? transform,
}) async =>
    await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => AdminForm(
          api: api,
          title: title,
          path: path,
          fields: fields,
          fixed: fixed,
          accept: accept,
          contextText: contextText,
          transform: transform,
        ),
      ),
    ) ==
    true;

class AdminList extends StatefulWidget {
  const AdminList({
    required this.title,
    required this.load,
    required this.body,
    super.key,
  });
  final String title;
  final Future<Map<String, dynamic>> Function(int page) load;
  final List<Widget> Function(BuildContext, Map<String, dynamic>, VoidCallback)
  body;
  @override
  State<AdminList> createState() => _AdminListState();
}

class _AdminListState extends State<AdminList> {
  int page = 1;
  late Future<Map<String, dynamic>> future;
  @override
  void initState() {
    super.initState();
    reload();
  }

  void reload() {
    setState(() {
      future = widget.load(page);
    });
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(widget.title),
      actions: [
        IconButton(
          onPressed: reload,
          icon: const Icon(Icons.refresh),
          tooltip: 'Muat ulang',
        ),
      ],
    ),
    body: FutureBuilder<Map<String, dynamic>>(
      future: future,
      builder: (context, s) {
        if (s.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        if (s.hasError) return Center(child: Text('${s.error}'));
        try {
          final row = s.data!;
          return Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 1000),
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  ...widget.body(context, row, reload),
                  if (row.containsKey('current_page'))
                    Wrap(
                      spacing: 12,
                      children: [
                        TextButton(
                          onPressed: page > 1
                              ? () {
                                  page--;
                                  reload();
                                }
                              : null,
                          child: const Text('Sebelumnya'),
                        ),
                        Text('Halaman $page'),
                        TextButton(
                          onPressed: row['next_page_url'] != null
                              ? () {
                                  page++;
                                  reload();
                                }
                              : null,
                          child: const Text('Berikutnya'),
                        ),
                      ],
                    ),
                ],
              ),
            ),
          );
        } catch (e) {
          return Center(child: Text('Dokumen tidak dapat ditampilkan: $e'));
        }
      },
    ),
  );
}

Future<Map<String, dynamic>> scopedPage(
  ApiClient api,
  String path,
  String org,
  int page, {
  bool rowScope = true,
}) async {
  final result = await api.request('GET', '$path?page=$page');
  final rows = AdminRepository.pageRows(result, page, organization: org);
  if (rowScope && rows.any((r) => r['organization_id'] != org)) {
    throw const FormatException('Dokumen organisasi tidak cocok.');
  }
  return result;
}
