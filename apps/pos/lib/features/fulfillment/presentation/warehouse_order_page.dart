import 'package:flutter/material.dart';
import '../../../design_system/molecules/dispatch_batch_field.dart';
import '../../../design_system/molecules/dispatch_review.dart';
import '../application/warehouse_controller.dart';
import '../data/warehouse_repository.dart';
import '../domain/warehouse_context.dart';

class WarehouseOrderPage extends StatefulWidget {
  const WarehouseOrderPage({
    required this.repository,
    required this.store,
    required this.order,
    super.key,
  });
  final WarehouseRepository repository;
  final String store, order;
  @override
  State<WarehouseOrderPage> createState() => _WarehouseOrderPageState();
}

class _WarehouseOrderPageState extends State<WarehouseOrderPage> {
  late final WarehouseController model;
  final handler = TextEditingController(), note = TextEditingController();
  int epoch = 0;
  @override
  void initState() {
    super.initState();
    model = WarehouseController(widget.repository, widget.store, widget.order)
      ..addListener(changed);
    model.load();
  }

  void changed() {
    if (epoch != model.epoch) {
      epoch = model.epoch;
      handler.clear();
      note.clear();
    }
  }

  @override
  void dispose() {
    model.removeListener(changed);
    model.dispose();
    handler.dispose();
    note.dispose();
    super.dispose();
  }

  void feedback() {
    if (!mounted) return;
    final text =
        model.error ?? (model.saved ? 'Pencatatan gudang tersimpan.' : null);
    if (text != null) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
    }
  }

  Future<void> confirm(bool process) async {
    try {
      final operation = process
          ? model.process()
          : model.dispatch(handler.text, note.text);
      final accepted = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text(operation.review['title'] as String),
          content: SingleChildScrollView(
            child: DispatchReview(review: operation.review),
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
      if (accepted == true && mounted) {
        await model.confirm(operation);
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

  Widget dispatchForm(WarehouseContext c) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      const Text(
        'Isi kemasan per batch yang benar-benar dikirim. Kosong/0 tidak dipilih. Tidak ada pilihan batch atau jumlah otomatis.',
      ),
      for (final line in c.lines)
        if ((line['remaining_packs'] as int? ?? 0) > 0)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('${line['sku']} · ${line['name']}'),
                  Text(
                    'Sisa ${line['remaining_packs']} kemasan · isi historis ${line['pack_size']} biji',
                  ),
                  if (c.choices(line).isEmpty)
                    const Text('Tidak ada stok utuh cocok pada gudang ini.'),
                  for (final batch in c.choices(line))
                    DispatchBatchField(
                      key: ValueKey('${model.epoch}:${model.key(line, batch)}'),
                      batch: batch['batch_code'] as String,
                      expiry: '${batch['expires_on']}',
                      packs:
                          (batch['usable_pieces'] as int) ~/
                          (batch['pack_size'] as int),
                      enabled: model.editable,
                      onChanged: (v) =>
                          model.quantity(model.key(line, batch), v),
                    ),
                ],
              ),
            ),
          ),
      TextField(
        controller: handler,
        enabled: model.editable,
        maxLength: 120,
        decoration: const InputDecoration(
          labelText: 'Petugas / kurir / serah terima',
        ),
      ),
      TextField(
        controller: note,
        enabled: model.editable,
        maxLength: 2000,
        decoration: const InputDecoration(labelText: 'Catatan pengiriman'),
      ),
      FilledButton(
        onPressed: model.editable ? () => confirm(false) : null,
        child: const Text('Tinjau pengiriman'),
      ),
    ],
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
            title: const Text('Pemenuhan gudang'),
            actions: [
              IconButton(
                tooltip: 'Muat ulang dan periksa batch lagi',
                onPressed: model.busy || model.pending != null
                    ? null
                    : model.load,
                icon: const Icon(Icons.refresh),
              ),
            ],
          ),
          body: ListView(
            key: const Key('warehouse-order-scroll'),
            padding: const EdgeInsets.all(16),
            children: [
              if (model.busy) const LinearProgressIndicator(),
              if (model.error != null)
                Text(
                  model.error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              if (model.saved) const Text('Pencatatan gudang telah tersimpan.'),
              if (c != null) ...[
                Text(
                  'Order ${c.id} · ${c.order['status']} · versi ${c.version}',
                ),
                Text(
                  'Gudang ${c.source['name']} → ${c.order['destination_name']}',
                ),
                Text(
                  'Metode ${c.order['delivery_mode']} · ${c.order['destination_address'] ?? '—'}',
                ),
                Text(
                  'Diamati ${c.observedAt}. Stok tidak direservasi; periksa tanggal/kondisi sesuai SOP.',
                ),
                for (final line in c.lines)
                  Text(
                    '${line['sku']}: diminta ${line['quantity_packs']} · terkirim ${line['sent_packs']} · sisa ${line['remaining_packs']} kemasan',
                  ),
              ],
              if (model.pending != null) ...[
                const Text(
                  'Hasil belum diketahui. Ulangi operasi yang sama; jangan membuat kiriman pengganti.',
                ),
                DispatchReview(review: model.pending!.review),
                FilledButton(
                  onPressed: model.busy ? null : retry,
                  child: const Text('Ulangi pencatatan yang sama'),
                ),
              ] else if (c != null && model.fresh) ...[
                if (c.canProcess)
                  FilledButton(
                    onPressed: model.editable ? () => confirm(true) : null,
                    child: const Text('Gunakan gudang ini'),
                  ),
                if (c.canDispatch) dispatchForm(c),
                if (!c.canProcess && !c.canDispatch)
                  const Text(
                    'Tidak ada tindakan gudang yang tersedia. Tidak ada kuota pengganti otomatis untuk barang rusak/hilang.',
                  ),
              ],
              if (c != null) ...[
                const SizedBox(height: 16),
                const Text('Riwayat kiriman'),
                if (c.shipments.isEmpty) const Text('Belum ada kiriman.'),
                for (final s in c.shipments)
                  Card(
                    child: ListTile(
                      title: Text('Kiriman ${s['id']}'),
                      subtitle: Text(
                        'Petugas ${s['handler']} · ${s['received_at'] == null ? 'Belum diterima' : 'Penerimaan tercatat'}\n${s['note'] ?? ''}',
                      ),
                    ),
                  ),
              ],
              const Text(
                'Pending hanya di memori halaman. Setelah restart/sesi berakhir, rekonsiliasi riwayat kiriman dan ledger sebelum mengganti operasi. Belum ada offline, FEFO, retur atau pengganti otomatis.',
              ),
            ],
          ),
        ),
      );
    },
  );
}
