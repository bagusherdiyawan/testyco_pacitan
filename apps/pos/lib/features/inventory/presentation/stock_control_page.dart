import 'package:flutter/material.dart';
import '../application/stock_control_controller.dart';
import '../data/stock_control_repository.dart';
import '../domain/stock_control.dart';
import 'stock_action_form.dart';
import '../../../design_system/molecules/stock_batch_card.dart';
import '../../../design_system/organisms/stock_count_card.dart';

class StockControlPage extends StatefulWidget {
  const StockControlPage({
    required this.repository,
    required this.storeId,
    super.key,
  });
  final StockControlRepository repository;
  final String storeId;
  @override
  State<StockControlPage> createState() => _StockControlPageState();
}

class _StockControlPageState extends State<StockControlPage> {
  late final StockControlController model;
  @override
  void initState() {
    super.initState();
    model = StockControlController(widget.repository, widget.storeId);
    model.load();
  }

  @override
  void dispose() {
    model.dispose();
    super.dispose();
  }

  Future<void> review(StockCount count, String action) async {
    final note = await showDialog<String>(
      context: context,
      builder: (_) => _ReviewDialog(count: count, action: action),
    );
    if (note != null && mounted) {
      await model.review(count, action, note);
    }
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) => PopScope(
      canPop: !model.busy && model.pending == null,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Kontrol stok'),
          actions: [
            IconButton(
              tooltip: 'Muat ulang kontrol stok',
              onPressed: model.busy ? null : model.load,
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
        body: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (model.busy) const LinearProgressIndicator(),
            const Text(
              'Buka/kukus hanya untuk batch beku. Masa simpan siap jual mengikuti SOP; tanggal batch bukan jaminan mutu hasil olahan.',
            ),
            if (model.context != null)
              Text(
                model.context!.name,
                style: Theme.of(context).textTheme.titleLarge,
              ),
            if (model.context?.active == false)
              const Text(
                'Toko nonaktif. Riwayat dapat dibaca, pencatatan dinonaktifkan.',
              ),
            if (model.error != null)
              Semantics(
                liveRegion: true,
                child: Text(
                  model.error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            if (model.message != null)
              Semantics(liveRegion: true, child: Text(model.message!)),
            if (!model.fresh && !model.busy)
              const Text(
                'Snapshot belum tersedia/terbaru. Muat ulang sebelum tindakan berikutnya.',
              ),
            if (model.pending != null)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Hasil operasi belum diketahui. Pertahankan halaman ini; retry memakai ID dan data yang sama. Jangan mencatat proses pengganti.',
                      ),
                      FilledButton(
                        onPressed: model.busy ? null : model.retry,
                        child: const Text('Ulangi operasi yang sama'),
                      ),
                    ],
                  ),
                ),
              ),
            if (model.selected != null && model.context?.active == true)
              StockActionForm(
                key: ValueKey(
                  '${model.selected!.id}:${model.selected!.revision}',
                ),
                model: model,
                batch: model.selected!,
              ),
            if (model.fresh && model.batches.isEmpty)
              const Text('Belum ada batch stok.'),
            if (model.context?.active == true)
              Text(
                'Pilih snapshot batch',
                style: Theme.of(context).textTheme.titleMedium,
              ),
            for (final b in model.batches)
              Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  StockBatchCard(
                    sku: b.sku,
                    name: b.name,
                    batch: b.code,
                    expiry: b.expiry,
                    packSize: b.packSize,
                    usable: b.usable,
                    quarantined: b.quarantined,
                    opened: b.opened,
                    ready: b.ready,
                    waste: b.waste,
                    revision: b.revision,
                  ),
                  if (model.context?.active == true)
                    OutlinedButton(
                      onPressed: model.canAct
                          ? () {
                              model.select(b);
                            }
                          : null,
                      child: Text('Pilih batch ${b.code}'),
                    ),
                ],
              ),
            Text(
              'Pengajuan opname dan susut',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const Text(
              'Pengusul dan peninjau harus berbeda. Susut memakai jumlah fisik tersisa; pengajuan belum mengubah saldo.',
            ),
            if (model.fresh && model.counts.isEmpty)
              const Text('Belum ada pengajuan hitung fisik.'),
            for (final c in model.counts)
              StockCountCard(
                batch: c.batch.code,
                purpose: c.purpose,
                bucket: c.bucket,
                status: c.status,
                reason: c.reason,
                expected: c.expected,
                counted: c.counted,
                difference: c.difference,
                revision: c.revision,
                stale: c.isStale,
                reviewNote: c.reviewNote,
                onApprove: model.canAct && c.canReview && !c.isStale
                    ? () => review(c, 'approve')
                    : null,
                onReject: model.canAct && c.canReview
                    ? () => review(c, 'reject')
                    : null,
                onCancel: model.canAct && c.canCancel
                    ? () => review(c, 'cancel')
                    : null,
              ),
            Text(
              'Riwayat pengolahan',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            if (model.fresh && model.processes.isEmpty)
              const Text('Belum ada proses buka/kukus.'),
            for (final p in model.processes)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(
                    '${p.kind} · ${p.batchCode}\nInput ${p.input} · Hasil ${p.output} · Susut ${p.waste} biji\n${p.reason}',
                  ),
                ),
              ),
          ],
        ),
      ),
    ),
  );
}

class _ReviewDialog extends StatefulWidget {
  const _ReviewDialog({required this.count, required this.action});
  final StockCount count;
  final String action;
  @override
  State<_ReviewDialog> createState() => _ReviewDialogState();
}

class _ReviewDialogState extends State<_ReviewDialog> {
  final note = TextEditingController();
  @override
  void dispose() {
    note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.count;
    final label = switch (widget.action) {
      'approve' => 'Setujui penyesuaian',
      'reject' => 'Tolak pengajuan',
      _ => 'Batalkan pengajuan sendiri',
    };
    return AlertDialog(
      title: Text(label),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              '${c.batch.code} · ${c.purpose} · ${c.bucket}\nSistem ${c.expected} → Fisik ${c.counted} biji · Selisih ${c.difference}\nSnapshot ${c.revision} · Versi ${c.version}',
            ),
            TextField(
              key: const Key('stock-review-note'),
              controller: note,
              maxLength: 2000,
              decoration: const InputDecoration(
                labelText: 'Catatan review wajib',
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Kembali'),
        ),
        FilledButton(
          onPressed: () => Navigator.pop(context, note.text),
          child: const Text('Konfirmasi review'),
        ),
      ],
    );
  }
}
