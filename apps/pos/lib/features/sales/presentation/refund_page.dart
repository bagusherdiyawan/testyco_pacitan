import 'package:flutter/material.dart';
import '../../../core/network/api_client.dart';
import '../../../design_system/organisms/refund_review.dart';
import '../application/refund_controller.dart';

class RefundPage extends StatefulWidget {
  const RefundPage({required this.api, required this.sale, super.key});
  final ApiClient api;
  final String sale;
  @override
  State<RefundPage> createState() => _RefundPageState();
}

class _RefundPageState extends State<RefundPage> {
  late final RefundController model;
  final reference = TextEditingController(), reason = TextEditingController();
  final quantities = <String, String>{};
  String? shift, disposition;
  bool verified = false;
  int? seenVersion;
  @override
  void initState() {
    super.initState();
    model = RefundController(widget.api, widget.sale);
    model.load();
  }

  @override
  void dispose() {
    model.dispose();
    reference.dispose();
    reason.dispose();
    super.dispose();
  }

  void resetForm() {
    quantities.clear();
    shift = null;
    disposition = null;
    verified = false;
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) {
      final version = model.context?['sale']['version'] as int?;
      if (version != null && version != seenVersion) {
        resetForm();
        reference.clear();
        reason.clear();
        seenVersion = version;
      }
      return PopScope(
        canPop: !model.busy && !model.uncertain,
        child: Scaffold(
          appBar: AppBar(
            title: const Text('Refund penjualan'),
            actions: [
              IconButton(
                tooltip: 'Muat ulang dan periksa lagi',
                onPressed: model.busy || model.uncertain || model.review != null
                    ? null
                    : () {
                        resetForm();
                        reference.clear();
                        reason.clear();
                        seenVersion = null;
                        model.load();
                      },
                icon: const Icon(Icons.refresh),
              ),
            ],
          ),
          body: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              if (model.busy) const LinearProgressIndicator(),
              if (model.error != null)
                Text(
                  model.error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              if (model.review case final r?) ...[
                RefundReview(review: r),
                if (model.uncertain) ...[
                  const Text(
                    'Hasil belum pasti. Jangan mengembalikan uang dua kali. Pertahankan halaman dan ulangi request yang sama.',
                  ),
                  FilledButton(
                    onPressed: model.busy ? null : model.retry,
                    child: const Text('Ulangi request yang sama'),
                  ),
                ] else ...[
                  CheckboxListTile(
                    value: verified,
                    onChanged: model.busy
                        ? null
                        : (v) => setState(() => verified = v!),
                    title: const Text(
                      'Saya telah memeriksa uang yang dikembalikan melalui metode asal dan jumlah fisik sesuai review ini',
                    ),
                  ),
                  FilledButton(
                    onPressed: model.busy || !verified ? null : model.confirm,
                    child: const Text('Konfirmasi refund dan posting sekali'),
                  ),
                  TextButton(
                    onPressed: model.busy
                        ? null
                        : () {
                            resetForm();
                            model.discard();
                          },
                    child: const Text('Kembali tanpa posting'),
                  ),
                ],
              ] else if (model.context case final c?) ...[
                Text(
                  '${c['sale']['number']} · Metode asal ${c['sale']['payment_method']}',
                ),
                DropdownButtonFormField<String>(
                  key: ValueKey('shift:${c['sale']['version']}'),
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Shift terbuka untuk rekonsiliasi',
                  ),
                  items: [
                    for (final s in c['open_shifts'])
                      DropdownMenuItem(
                        value: s['id'] as String,
                        child: Text(
                          '${s['device_name']} · ${s['cashier_name']}',
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                  ],
                  onChanged: model.editable ? (v) => shift = v : null,
                ),
                DropdownButtonFormField<String>(
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Penanganan fisik aktual',
                  ),
                  items: const [
                    DropdownMenuItem(
                      value: 'quarantine',
                      child: Text('Barang kembali ke karantina'),
                    ),
                    DropdownMenuItem(
                      value: 'no_stock',
                      child: Text('Tanpa pengembalian stok'),
                    ),
                  ],
                  onChanged: model.editable ? (v) => disposition = v : null,
                ),
                for (final l in c['sale']['lines'])
                  TextField(
                    key: ValueKey('${c['sale']['version']}:${l['id']}'),
                    enabled: model.editable,
                    keyboardType: TextInputType.number,
                    decoration: InputDecoration(
                      labelText:
                          '${l['sku']} · Unit refund (${l['unit']}; kosong/0 dilewati)',
                    ),
                    onChanged: (v) => quantities[l['id']] = v,
                  ),
                TextField(
                  controller: reference,
                  enabled: model.editable,
                  maxLength: 160,
                  decoration: const InputDecoration(
                    labelText: 'Referensi refund unik',
                  ),
                ),
                TextField(
                  controller: reason,
                  enabled: model.editable,
                  maxLength: 1800,
                  decoration: const InputDecoration(
                    labelText: 'Alasan dan hasil pemeriksaan fisik',
                  ),
                ),
                FilledButton(
                  onPressed: !model.editable
                      ? null
                      : () {
                          verified = false;
                          final q = <String, int>{};
                          for (final e in quantities.entries) {
                            final n = e.value.trim().isEmpty
                                ? 0
                                : int.tryParse(e.value);
                            if (n == null || n < 0) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                const SnackBar(
                                  content: Text(
                                    'Isi jumlah unit berupa bilangan bulat.',
                                  ),
                                ),
                              );
                              return;
                            }
                            q[e.key] = n;
                          }
                          model.preview(
                            shift ?? '',
                            disposition ?? '',
                            reference.text,
                            reason.text,
                            q,
                          );
                        },
                  child: const Text('Tinjau nominal dan perubahan stok'),
                ),
                const Text('Riwayat refund'),
                for (final r in c['refunds'])
                  Text(
                    '${r['reference']} · Rp ${r['amount']} · ${r['payment_method']}',
                  ),
              ] else if (!model.busy)
                TextButton(
                  onPressed: model.load,
                  child: const Text('Muat ulang'),
                ),
              const Text(
                'Online. Request belum pasti tersimpan selama halaman hidup. Setelah aplikasi ditutup, rekonsiliasi riwayat sebelum membuat request baru.',
              ),
            ],
          ),
        ),
      );
    },
  );
}
