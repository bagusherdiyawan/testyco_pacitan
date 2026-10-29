import 'package:flutter/material.dart';
import '../../../design_system/molecules/minimum_change_review.dart';
import '../../../design_system/molecules/stock_minimum_card.dart';
import '../application/stock_minimum_controller.dart';
import '../data/inventory_repository.dart';

class StockMinimumEditor extends StatefulWidget {
  const StockMinimumEditor({
    required this.repository,
    required this.storeId,
    required this.productId,
    super.key,
  });
  final InventoryRepository repository;
  final String storeId, productId;
  @override
  State<StockMinimumEditor> createState() => _StockMinimumEditorState();
}

class _StockMinimumEditorState extends State<StockMinimumEditor> {
  late final StockMinimumController model;
  final threshold = TextEditingController(), reason = TextEditingController();
  bool enabled = false;
  int epoch = 0;
  @override
  void initState() {
    super.initState();
    model = StockMinimumController(
      widget.repository,
      widget.storeId,
      widget.productId,
    )..addListener(_changed);
    model.load();
  }

  void _changed() {
    if (epoch != model.formEpoch) {
      epoch = model.formEpoch;
      enabled = model.detail!.row.minimum != null;
      threshold.text = model.detail!.row.minimum?.toString() ?? '';
      reason.clear();
    }
  }

  @override
  void dispose() {
    model.removeListener(_changed);
    model.dispose();
    threshold.dispose();
    reason.dispose();
    super.dispose();
  }

  Widget review(MinimumChange draft) => MinimumChangeReview(
    sku: model.detail!.row.sku,
    previous: model.detail!.row.minimum,
    next: draft.body['minimum_pieces'] as int?,
    version: draft.body['version'] as int,
    reason: draft.body['reason'] as String,
  );
  void feedback() {
    if (!mounted) return;
    final text = model.error ?? (model.saved ? 'Ambang tersimpan.' : null);
    if (text != null) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
    }
  }

  Future<void> confirm() async {
    try {
      final draft = model.prepare(enabled, threshold.text, reason.text);
      final accepted = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Tinjau perubahan ambang'),
          content: SingleChildScrollView(child: review(draft)),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Kembali'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Konfirmasi ambang'),
            ),
          ],
        ),
      );
      if (accepted == true && mounted) {
        await model.confirm(draft);
        feedback();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('$e')));
      }
    }
  }

  Future<void> retry() async {
    await model.retry();
    feedback();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) {
      final d = model.detail, row = d?.row;
      return PopScope(
        canPop: !model.busy && model.pending == null,
        child: Scaffold(
          appBar: AppBar(
            title: const Text('Atur stok minimum'),
            actions: [
              IconButton(
                tooltip: 'Muat ulang ambang dan buang isian',
                onPressed: model.busy || model.pending != null
                    ? null
                    : model.load,
                icon: const Icon(Icons.refresh),
              ),
            ],
          ),
          body: ListView(
            key: const Key('minimum-editor-scroll'),
            padding: const EdgeInsets.all(16),
            children: [
              if (model.busy) const LinearProgressIndicator(),
              if (model.error != null)
                Text(
                  model.error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              if (model.saved) const Text('Perubahan ambang telah tersimpan.'),
              if (row != null) ...[
                StockMinimumCard(
                  sku: row.sku,
                  name: row.name,
                  available: row.available,
                  usable: row.usable,
                  opened: row.opened,
                  ready: row.ready,
                  minimum: row.minimum,
                  shortfall: row.shortfall,
                  isLow: row.isLow,
                ),
                Text(
                  'Versi ${row.version ?? 'tidak tersedia'} · Diamati ${d!.observedAt}',
                ),
              ],
              if (model.pending != null) ...[
                const Text(
                  'Hasil penyimpanan belum diketahui. Ulangi operasi yang sama sebelum membuat perubahan lain.',
                ),
                review(model.pending!),
                FilledButton(
                  onPressed: model.busy ? null : retry,
                  child: const Text('Ulangi perubahan yang sama'),
                ),
              ] else if (d?.canManage == true &&
                  model.fresh &&
                  row?.version != null) ...[
                SwitchListTile(
                  title: const Text('Pantau SKU ini'),
                  value: enabled,
                  onChanged: model.editable
                      ? (v) => setState(() => enabled = v)
                      : null,
                ),
                TextField(
                  controller: threshold,
                  enabled: model.editable && enabled,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(
                    labelText: 'Ambang satuan dasar (biji)',
                  ),
                ),
                TextField(
                  controller: reason,
                  enabled: model.editable,
                  maxLength: 2000,
                  decoration: const InputDecoration(
                    labelText: 'Alasan perubahan',
                  ),
                ),
                const Text(
                  'Tidak ada nilai ambang bawaan. 0 tetap aktif; matikan pemantauan untuk menonaktifkan ambang. Saldo bukan snapshot yang dibekukan.',
                ),
                FilledButton(
                  onPressed: model.editable ? confirm : null,
                  child: const Text('Tinjau perubahan ambang'),
                ),
              ] else if (d != null)
                const Text(
                  'Perubahan tidak tersedia. Periksa izin dan muat ulang konfigurasi sebelum mengubah lagi.',
                ),
              const SizedBox(height: 16),
              const Text(
                'Pending hanya di memori halaman. Setelah restart atau sesi berakhir, periksa konfigurasi dan audit sebelum membuat pengganti. Belum ada antrean offline atau pesanan otomatis.',
              ),
            ],
          ),
        ),
      );
    },
  );
}
