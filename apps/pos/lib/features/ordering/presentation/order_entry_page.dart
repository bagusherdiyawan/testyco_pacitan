import 'package:flutter/material.dart';
import '../application/order_entry_controller.dart';
import '../data/order_entry_repository.dart';
import '../domain/order_entry.dart';
import '../../../design_system/formatters.dart';
import '../../../design_system/molecules/order_line_editor.dart';
import '../../../design_system/organisms/order_review_card.dart';

class OrderEntryPage extends StatefulWidget {
  const OrderEntryPage({
    required this.repository,
    required this.storeId,
    this.orderId,
    super.key,
  });
  final OrderEntryRepository repository;
  final String storeId;
  final String? orderId;
  @override
  State<OrderEntryPage> createState() => _OrderEntryPageState();
}

class _OrderEntryPageState extends State<OrderEntryPage> {
  late final OrderEntryController model;
  final search = TextEditingController(), note = TextEditingController();
  @override
  void initState() {
    super.initState();
    model = OrderEntryController(
      widget.repository,
      widget.storeId,
      orderId: widget.orderId,
    );
    model.addListener(_syncSeed);
    model.load();
  }

  @override
  void dispose() {
    search.dispose();
    note.dispose();
    model.removeListener(_syncSeed);
    model.dispose();
    super.dispose();
  }

  int _seenEpoch = 0;
  void _syncSeed() {
    if (_seenEpoch != model.compositionEpoch) {
      _seenEpoch = model.compositionEpoch;
      note.text = model.note;
    }
  }

  Future<void> restart() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Muat versi pengajuan terbaru?'),
        content: const Text(
          'Pilihan dan jumlah lokal yang belum disimpan akan dibuang. Periksa ulang seluruh item setelah memuat versi terbaru.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Kembali'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Muat versi terbaru'),
          ),
        ],
      ),
    );
    if (confirmed == true && mounted) {
      await model.restartFromServer();
    }
  }

  Widget catalogue() => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Text('Produk pusat', style: Theme.of(context).textTheme.titleLarge),
      TextField(
        controller: search,
        onChanged: model.search,
        decoration: const InputDecoration(labelText: 'Cari SKU / nama pusat'),
      ),
      if (model.fresh && model.filtered.isEmpty)
        const Text('Tidak ada produk pusat yang sesuai.'),
      for (final p in model.filtered)
        Card(
          child: ListTile(
            title: Text('${p.sku} · ${p.name}'),
            subtitle: Text('${p.condition} · ${p.packSize} biji/kemasan'),
            trailing: IconButton(
              tooltip: 'Tambah ${p.sku}',
              onPressed: model.canEdit ? () => model.add(p) : null,
              icon: const Icon(Icons.add),
            ),
          ),
        ),
    ],
  );
  Widget cart() => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Text('Pengajuan toko', style: Theme.of(context).textTheme.titleLarge),
      if (model.cart.isEmpty)
        const Text(
          'Pilih produk pusat, SKU lokal yang cocok dan jumlah kemasan. Harga ditampilkan pada tinjauan server.',
        ),
      for (final line in model.cart) mapping(line),
      DropdownButtonFormField<String>(
        key: ValueKey(model.mode),
        initialValue: model.mode,
        isExpanded: true,
        decoration: const InputDecoration(labelText: 'Metode penerimaan'),
        items: const [
          DropdownMenuItem(value: 'pickup', child: Text('Ambil di gudang')),
          DropdownMenuItem(value: 'delivery', child: Text('Antar ke toko')),
        ],
        onChanged: model.canEdit ? (value) => model.delivery(value!) : null,
      ),
      TextField(
        controller: note,
        onChanged: model.setNote,
        enabled: model.canEdit,
        maxLength: 2000,
        decoration: const InputDecoration(
          labelText: 'Catatan pengajuan (opsional)',
        ),
      ),
      FilledButton(
        onPressed: model.canEdit ? model.review : null,
        child: const Text('Tinjau pengajuan di server'),
      ),
    ],
  );
  Widget mapping(DraftOrderLine line) {
    final p = model.supplier(line.supplierId), choices = model.candidates(line);
    final selected = choices.any((p) => p.id == line.localId)
        ? line.localId
        : null;
    return OrderLineEditor(
      key: ValueKey('draft-${line.supplierId}:${model.compositionEpoch}'),
      title: p == null
          ? 'SKU pusat tidak tersedia; hapus atau muat ulang.'
          : '${p.sku} · ${p.packSize} biji/kemasan · ${p.condition}',
      quantity: line.quantity,
      choices: choices.map((p) => (p.id, '${p.sku} · ${p.name}')).toList(),
      localId: selected,
      enabled: model.canEdit,
      onQuantity: (value) => model.quantity(line, value),
      onLocal: (value) => model.local(line, value),
      onRemove: () => model.remove(line),
    );
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) => PopScope(
      canPop: !model.busy && model.pending == null,
      child: Scaffold(
        appBar: AppBar(
          title: Text(
            widget.orderId == null ? 'Buat pengajuan' : 'Ubah pengajuan',
          ),
          actions: [
            IconButton(
              tooltip: 'Muat ulang katalog pengajuan',
              onPressed: model.busy ? null : model.load,
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
        body: ListView(
          key: const Key('order-entry-scroll'),
          padding: const EdgeInsets.all(16),
          children: [
            if (model.busy) const LinearProgressIndicator(),
            if (model.context != null)
              Text(
                model.context!.name,
                style: Theme.of(context).textTheme.headlineSmall,
              ),
            if (model.context?.canCreate == false)
              const Text(
                'Pengajuan belum tersedia untuk toko/akun ini. Hubungi pengelola.',
              ),
            if (model.error != null)
              Semantics(
                liveRegion: true,
                child: Text(
                  model.error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            if (!model.fresh && !model.busy)
              const Text(
                'Data belum terbaru. Muat ulang sebelum membuat pengajuan.',
              ),
            if (model.orderChanged && model.pending == null)
              OutlinedButton(
                onPressed: model.busy ? null : restart,
                child: const Text('Mulai ulang dari versi terbaru'),
              ),
            if (model.pending != null)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Hasil pengiriman belum diketahui. Pertahankan halaman ini dan retry permintaan yang sama. Jangan membuat pengajuan pengganti sebelum status diperiksa.',
                      ),
                      FilledButton(
                        onPressed: model.busy ? null : model.retry,
                        child: const Text('Ulangi pengajuan yang sama'),
                      ),
                    ],
                  ),
                ),
              ),
            if (model.createdId != null) ...[
              Text(model.message ?? 'Pengajuan tersimpan.'),
              Text('Nomor pengajuan ${model.createdId}'),
              FilledButton(
                onPressed: () => Navigator.pop(context, model.createdId),
                child: const Text('Lihat detail pengajuan'),
              ),
            ] else if (model.preview case final preview?) ...[
              OrderReviewCard(
                notice: model.isAmend
                    ? 'Pratinjau belum mengubah pengajuan. Perubahan tersimpan kembali menunggu persetujuan.'
                    : 'Belum membuat pesanan atau mereservasi stok. Setelah dikirim, pengajuan menunggu persetujuan yang berwenang.',
                destination: preview.name,
                address: preview.address,
                mode: preview.mode == 'delivery'
                    ? 'Antar ke toko'
                    : 'Ambil di gudang',
                kind: preview.kind == 'internal_transfer'
                    ? 'Transfer internal · nilai komersial nol'
                    : 'Pembelian grosir dari pusat',
                total: preview.total,
                observedAt: preview.observedAt,
                children: [
                  for (final line in preview.lines)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      child: Text(
                        '${line.sku} → ${line.localSku} · ${line.quantity} kemasan × ${line.packSize} biji · ${formatRupiah(line.price)}/kemasan',
                      ),
                    ),
                ],
              ),
              if (model.pending == null)
                Wrap(
                  spacing: 8,
                  children: [
                    OutlinedButton(
                      onPressed: model.busy ? null : model.edit,
                      child: const Text('Ubah pilihan'),
                    ),
                    FilledButton(
                      onPressed:
                          !model.busy &&
                              model.fresh &&
                              model.context?.canCreate == true
                          ? model.confirm
                          : null,
                      child: Text(
                        model.isAmend
                            ? 'Konfirmasi perubahan'
                            : 'Konfirmasi pengajuan',
                      ),
                    ),
                  ],
                ),
            ] else if (model.context?.canCreate == true)
              LayoutBuilder(
                builder: (context, box) => box.maxWidth >= 900
                    ? Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(child: catalogue()),
                          const SizedBox(width: 16),
                          Expanded(child: cart()),
                        ],
                      )
                    : Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          catalogue(),
                          const SizedBox(height: 16),
                          cart(),
                        ],
                      ),
              ),
            const Text(
              'SKU lokal dipilih eksplisit dengan isi kemasan/kondisi cocok. Persetujuan, stok dan penerimaan tetap diproses terpisah. Belum ada antrean pengajuan yang tahan restart/offline.',
            ),
          ],
        ),
      ),
    ),
  );
}
