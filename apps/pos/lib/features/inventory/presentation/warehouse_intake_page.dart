import 'package:flutter/material.dart';
import '../../../design_system/organisms/intake_review_card.dart';
import '../application/warehouse_intake_controller.dart';
import '../data/warehouse_intake_repository.dart';

class WarehouseIntakePage extends StatefulWidget {
  const WarehouseIntakePage({
    required this.repository,
    required this.store,
    super.key,
  });
  final WarehouseIntakeRepository repository;
  final String store;
  @override
  State<WarehouseIntakePage> createState() => _WarehouseIntakePageState();
}

class _WarehouseIntakePageState extends State<WarehouseIntakePage> {
  late final WarehouseIntakeController model;
  String? selected;
  String search = '';
  final batch = TextEditingController(),
      expiry = TextEditingController(),
      quantity = TextEditingController(),
      note = TextEditingController();
  @override
  void initState() {
    super.initState();
    model = WarehouseIntakeController(widget.repository, widget.store);
    model.load();
  }

  @override
  void dispose() {
    model.dispose();
    batch.dispose();
    expiry.dispose();
    quantity.dispose();
    note.dispose();
    super.dispose();
  }

  Widget field(
    String label,
    TextEditingController value, {
    bool number = false,
    int? maxLength,
  }) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: TextField(
      controller: value,
      enabled: model.editable,
      maxLength: maxLength,
      keyboardType: number ? TextInputType.number : TextInputType.text,
      decoration: InputDecoration(labelText: label),
    ),
  );
  Future<void> confirm() async {
    final accepted = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Konfirmasi penerimaan fisik'),
        content: SingleChildScrollView(
          child: IntakeReviewCard(snapshot: model.review!.snapshot),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Kembali'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Simpan penerimaan'),
          ),
        ],
      ),
    );
    if (accepted == true && mounted) await model.confirm();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) {
      final products = model.catalogue?.products ?? [];
      final selectedExists = products.any((p) => p.id == selected);
      final visible = products
          .where(
            (p) =>
                p.id == selected ||
                '${p.sku} ${p.name}'.toLowerCase().contains(
                  search.toLowerCase(),
                ),
          )
          .toList();
      return PopScope(
        canPop: !model.busy && model.pending == null,
        child: Scaffold(
          appBar: AppBar(
            title: const Text('Penerimaan awal gudang'),
            actions: [
              IconButton(
                tooltip: 'Muat ulang SKU dan buang tinjauan',
                onPressed:
                    model.busy || model.pending != null || model.savedId != null
                    ? null
                    : model.load,
                icon: const Icon(Icons.refresh),
              ),
            ],
          ),
          body: ListView(
            key: const Key('intake-scroll'),
            padding: const EdgeInsets.all(16),
            children: [
              if (model.busy) const LinearProgressIndicator(),
              if (model.error != null)
                Text(
                  model.error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              if (model.savedId != null) ...[
                Text('Penerimaan tersimpan · batch ${model.savedId}'),
                IntakeReviewCard(snapshot: model.review!.snapshot),
                FilledButton(
                  onPressed: () => Navigator.pop(context, true),
                  child: const Text('Kembali dan muat stok terkini'),
                ),
              ] else if (model.pending != null) ...[
                const Text(
                  'Hasil penerimaan belum diketahui. Ulangi operasi yang sama, jangan mencatat pengganti.',
                ),
                IntakeReviewCard(snapshot: model.review!.snapshot),
                FilledButton(
                  onPressed: model.busy ? null : model.retry,
                  child: const Text('Ulangi penerimaan yang sama'),
                ),
              ] else if (model.review != null) ...[
                IntakeReviewCard(snapshot: model.review!.snapshot),
                FilledButton(
                  onPressed: model.busy ? null : confirm,
                  child: const Text('Konfirmasi penerimaan'),
                ),
                TextButton(
                  onPressed: model.busy ? null : model.edit,
                  child: const Text('Ubah isian'),
                ),
              ] else if (model.catalogue != null && model.fresh) ...[
                Text('Gudang ${model.catalogue!.storeName}'),
                const Text(
                  'Untuk stok awal pusat yang benar-benar diterima baik. Toko mitra memakai penerimaan pesanan. Isi berdasarkan kemasan fisik; tidak ada jumlah atau tanggal bawaan.',
                ),
                TextField(
                  enabled: model.editable,
                  decoration: const InputDecoration(
                    labelText: 'Cari SKU penerimaan',
                  ),
                  onChanged: (v) => setState(() => search = v),
                ),
                DropdownButtonFormField<String>(
                  key: ValueKey(
                    '$selected:${products.map((p) => p.id).join(',')}',
                  ),
                  initialValue: selectedExists ? selected : null,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'SKU pusat'),
                  items: [
                    for (final p in visible)
                      DropdownMenuItem(
                        value: p.id,
                        child: Text(
                          '${p.sku} · ${p.name} · ${p.packSize} biji/kemasan',
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                  ],
                  onChanged: model.editable
                      ? (v) => setState(() => selected = v)
                      : null,
                ),
                if (products.isEmpty)
                  const Text('Belum ada SKU aktif untuk penerimaan.'),
                field('Kode batch', batch, maxLength: 80),
                field('Kedaluwarsa (YYYY-MM-DD)', expiry),
                field('Kemasan diterima baik', quantity, number: true),
                field('Referensi / catatan pemeriksaan', note, maxLength: 2000),
                FilledButton(
                  onPressed: model.editable
                      ? () => model.preview(
                          selected,
                          batch.text,
                          expiry.text,
                          quantity.text,
                          note.text,
                        )
                      : null,
                  child: const Text('Tinjau penerimaan di server'),
                ),
              ],
              const SizedBox(height: 16),
              const Text(
                'Pending hanya di memori halaman. Setelah restart atau sesi berakhir, periksa batch/ledger/audit sebelum membuat pengganti. Belum ada antrean offline atau blok kedaluwarsa otomatis.',
              ),
            ],
          ),
        ),
      );
    },
  );
}
