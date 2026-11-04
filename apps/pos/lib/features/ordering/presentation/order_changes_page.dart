import 'package:flutter/material.dart';
import '../../../design_system/formatters.dart';
import '../../../design_system/organisms/order_change_card.dart';
import '../../../design_system/organisms/order_change_review.dart';
import '../application/order_changes_controller.dart';
import '../data/order_changes_repository.dart';
import '../domain/order_change.dart';

class OrderChangesPage extends StatefulWidget {
  const OrderChangesPage({
    required this.repository,
    required this.orderId,
    super.key,
  });
  final OrderChangesRepository repository;
  final String orderId;
  @override
  State<OrderChangesPage> createState() => _OrderChangesPageState();
}

class _OrderChangesPageState extends State<OrderChangesPage> {
  late final OrderChangesController model;
  final reason = TextEditingController();
  final scroll = ScrollController();
  PendingOrderChange? lastPending;
  final quantities = <String, String>{};
  final additions = <Map<String, dynamic>>[];
  int epoch = 0;
  @override
  void initState() {
    super.initState();
    model = OrderChangesController(widget.repository, widget.orderId)
      ..addListener(changed);
    model.load();
  }

  void changed() {
    final resetScroll = epoch != model.epoch || lastPending != model.pending;
    lastPending = model.pending;
    if (epoch != model.epoch) {
      epoch = model.epoch;
      reason.clear();
      quantities.clear();
      additions.clear();
      for (final l in model.context?.original.lines ?? <ChangeLine>[]) {
        quantities[l.id] = '${l.packs}';
      }
    }
    if (resetScroll) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && scroll.hasClients) scroll.jumpTo(0);
      });
    }
  }

  @override
  void dispose() {
    model.removeListener(changed);
    model.dispose();
    reason.dispose();
    scroll.dispose();
    super.dispose();
  }

  void feedback(Object error) {
    if (mounted) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('$error')));
    }
  }

  Future<void> review(PendingOrderChange operation) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(operation.review['title'] as String),
        content: SingleChildScrollView(
          child: OrderChangeReview(review: operation.review),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Kembali'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Konfirmasi pencatatan'),
          ),
        ],
      ),
    );
    if (ok == true && mounted) await model.confirm(operation);
  }

  Future<void> propose() async {
    try {
      await review(
        widget.repository.advanced
            ? await model.revise(quantities, additions, reason.text)
            : model.propose(quantities, reason.text),
      );
    } catch (e) {
      feedback(e);
    }
  }

  Future<void> decide(OrderChangeRecord row, String action) async {
    final note = await showDialog<String>(
      context: context,
      builder: (_) => _ChangeNoteDialog(action: action),
    );
    if (note != null && mounted) {
      try {
        await review(model.decide(row, action, note));
      } catch (e) {
        feedback(e);
      }
    }
  }

  Widget form(OrderChangeContext c) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            widget.repository.advanced
                ? 'Revisi SKU dan sisa pesanan'
                : 'Usulkan pengurangan',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          Text(
            widget.repository.advanced
                ? 'Pertahankan minimal jumlah yang sudah dikirim; penghapusan memakai0 pada baris belum terkirim. Tambahan ditinjau dengan quote baru.'
                : 'Isi semua baris. Minimal satu kemasan per baris dan setidaknya satu baris berkurang. Harga, SKU dan kemasan tetap.',
          ),
          for (final l in c.original.lines) ...[
            Text(
              '${l.sku} → ${l.localSku} · semula ${l.packs} kemasan · isi ${l.size} · ${formatRupiah(l.price)}/kemasan',
            ),
            TextFormField(
              key: ValueKey('change-quantity-${model.epoch}-${l.id}'),
              initialValue: '${l.packs}',
              enabled: model.editable,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(labelText: 'Jumlah usulan ${l.sku}'),
              onChanged: (v) => quantities[l.id] = v,
            ),
          ],
          if (widget.repository.advanced) ...[
            const Text(
              'Jumlah lama minimal yang sudah dikirim; tambahan memakai quote server dan persetujuan pihak lawan.',
            ),
            for (final row in additions)
              Text('Tambah ${row['quantity_packs']} kemasan'),
            OutlinedButton(
              onPressed: model.editable
                  ? () async {
                      final row = await showDialog<Map<String, dynamic>>(
                        context: context,
                        builder: (_) => _RevisionAddition(contextData: c),
                      );
                      if (row != null && mounted) {
                        setState(() => additions.add(row));
                      }
                    }
                  : null,
              child: const Text('Tambah SKU / substitusi'),
            ),
            if (additions.isNotEmpty)
              TextButton(
                onPressed: model.editable
                    ? () => setState(additions.clear)
                    : null,
                child: const Text('Hapus semua tambahan'),
              ),
          ],
          TextField(
            key: const Key('change-proposal-reason'),
            controller: reason,
            enabled: model.editable,
            maxLength: 2000,
            decoration: const InputDecoration(labelText: 'Alasan pengurangan'),
          ),
          FilledButton(
            onPressed: model.editable ? propose : null,
            child: const Text('Tinjau usulan pengurangan'),
          ),
        ],
      ),
    ),
  );
  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) {
      final c = model.context;
      return PopScope(
        canPop: !model.busy && model.pending == null,
        child: Scaffold(
          appBar: AppBar(
            title: Text(
              widget.repository.advanced
                  ? 'Revisi SKU dan jumlah'
                  : 'Persetujuan ulang',
            ),
            actions: [
              IconButton(
                tooltip: 'Muat ulang usulan',
                onPressed: model.busy || model.pending != null
                    ? null
                    : () => model.load(),
                icon: const Icon(Icons.refresh),
              ),
            ],
          ),
          body: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 960),
              child: ListView(
                key: const Key('order-changes-scroll'),
                controller: scroll,
                padding: const EdgeInsets.all(16),
                children: [
                  if (model.busy) const LinearProgressIndicator(),
                  if (model.error != null)
                    Semantics(
                      liveRegion: true,
                      child: Text(
                        model.error!,
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ),
                  if (model.saved)
                    const Text(
                      'Respons pencatatan diterima. Riwayat terbaru ditampilkan bila berhasil dimuat.',
                    ),
                  const Text(
                    'Pengurangan jumlah setelah persetujuan, sebelum pengiriman pertama. Retur dan penggantian SKU belum tersedia.',
                  ),
                  if (c != null) ...[
                    Text(
                      c.destination,
                      style: Theme.of(context).textTheme.headlineSmall,
                    ),
                    Text(
                      '${c.status} · Versi ${c.version} · Total pesanan ${formatRupiah(c.original.total)}',
                    ),
                    if (c.held)
                      const Text(
                        'Pesanan ditahan: proses, pengiriman dan pembatalan menunggu keputusan usulan. Jumlah pesanan belum berubah.',
                      ),
                  ],
                  if (model.pending case final op?) ...[
                    const Text(
                      'Hasil belum pasti. Pertahankan halaman ini dan ulangi request yang sama; jangan buat pengganti.',
                    ),
                    OrderChangeReview(review: op.review),
                    FilledButton(
                      onPressed: model.busy ? null : model.retry,
                      child: const Text('Ulangi request yang sama'),
                    ),
                  ] else if (c != null && model.fresh) ...[
                    if (c.allows('propose')) form(c),
                    const Text('Riwayat usulan dan keputusan'),
                    if (c.rows.isEmpty)
                      const Text('Belum ada usulan perubahan.'),
                    for (final row in c.rows)
                      OrderChangeCard(
                        record: row,
                        actions: [
                          if (row.status == 'pending' &&
                              row.pendingVersion == c.version)
                            for (final action in [
                              'approve',
                              'reject',
                              'withdraw',
                            ])
                              if (c.allows(action))
                                OutlinedButton(
                                  onPressed: model.editable
                                      ? () => decide(row, action)
                                      : null,
                                  child: Text(
                                    {
                                      'approve': 'Setujui jumlah revisi',
                                      'reject': 'Tolak usulan',
                                      'withdraw': 'Tarik usulan',
                                    }[action]!,
                                  ),
                                ),
                        ],
                      ),
                    Text(
                      'Halaman ${c.page} dari ${c.lastPage} · ${c.total} usulan. Pindah halaman memuat ulang konteks dan mengosongkan isian.',
                    ),
                    Wrap(
                      spacing: 8,
                      children: [
                        OutlinedButton(
                          onPressed: model.editable && c.page > 1
                              ? () => model.load(page: c.page - 1)
                              : null,
                          child: const Text('Sebelumnya'),
                        ),
                        OutlinedButton(
                          onPressed: model.editable && c.page < c.lastPage
                              ? () => model.load(page: c.page + 1)
                              : null,
                          child: const Text('Berikutnya'),
                        ),
                      ],
                    ),
                  ],
                  if (!model.fresh && !model.busy && model.pending == null)
                    const Text(
                      'Detail terbaru belum tersedia. Muat ulang untuk meninjau kembali.',
                    ),
                  const Text(
                    'Online. Request tertahan hanya selama halaman hidup; setelah aplikasi ditutup atau sesi berakhir, periksa riwayat sebelum mencatat ulang.',
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    },
  );
}

class _ChangeNoteDialog extends StatefulWidget {
  const _ChangeNoteDialog({required this.action});
  final String action;
  @override
  State<_ChangeNoteDialog> createState() => _ChangeNoteDialogState();
}

class _ChangeNoteDialogState extends State<_ChangeNoteDialog> {
  final note = TextEditingController();
  @override
  void dispose() {
    note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Catatan keputusan'),
    content: SingleChildScrollView(
      child: TextField(
        key: const Key('change-decision-note'),
        controller: note,
        maxLength: 2000,
        decoration: InputDecoration(
          labelText: widget.action == 'approve'
              ? 'Catatan opsional'
              : 'Alasan wajib',
        ),
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Kembali'),
      ),
      FilledButton(
        onPressed: () => Navigator.pop(context, note.text),
        child: const Text('Tinjau keputusan'),
      ),
    ],
  );
}

class _RevisionAddition extends StatefulWidget {
  const _RevisionAddition({required this.contextData});
  final OrderChangeContext contextData;
  @override
  State<_RevisionAddition> createState() => _RevisionAdditionState();
}

class _RevisionAdditionState extends State<_RevisionAddition> {
  String? supplier, local, error;
  final quantity = TextEditingController();
  @override
  void dispose() {
    quantity.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Tambahan atau substitusi SKU'),
    content: SingleChildScrollView(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          DropdownButtonFormField<String>(
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'SKU pusat'),
            items: [
              for (final p in widget.contextData.supplierProducts)
                DropdownMenuItem(
                  value: p['id'] as String,
                  child: Text(
                    '${p['sku']} · Isi ${p['pack_size']}',
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
            ],
            onChanged: (v) => supplier = v,
          ),
          DropdownButtonFormField<String>(
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'SKU lokal'),
            items: [
              for (final p in widget.contextData.localProducts)
                DropdownMenuItem(
                  value: p['id'] as String,
                  child: Text(
                    '${p['sku']} · Isi ${p['pack_size']}',
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
            ],
            onChanged: (v) => local = v,
          ),
          TextField(
            controller: quantity,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(
              labelText: 'Jumlah kemasan tambahan',
            ),
          ),
          if (error != null) Text(error!),
          const Text(
            'Isi dan kondisi SKU harus sama. Quote baru ditinjau dari server; histori barang terkirim tetap.',
          ),
        ],
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Batal'),
      ),
      FilledButton(
        onPressed: () {
          final n = int.tryParse(quantity.text);
          if (supplier == null ||
              local == null ||
              n == null ||
              n < 1 ||
              n > 10000) {
            setState(() => error = 'Pilih kedua SKU dan jumlah1–10000.');
            return;
          }
          Navigator.pop(context, {
            'supplier_product_id': supplier,
            'local_product_id': local,
            'quantity_packs': n,
          });
        },
        child: const Text('Tambahkan untuk ditinjau'),
      ),
    ],
  );
}
