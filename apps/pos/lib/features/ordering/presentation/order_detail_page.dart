import 'package:flutter/material.dart';
import '../application/recipient_order_controller.dart';
import '../data/orders_repository.dart';
import '../domain/recipient_order.dart';
import 'shipment_receipt_form.dart';
import 'order_changes_page.dart';
import '../data/order_changes_repository.dart';
import 'order_entry_page.dart';
import '../data/order_entry_repository.dart';

class OrderDetailPage extends StatefulWidget {
  const OrderDetailPage({
    required this.repository,
    required this.id,
    super.key,
  });
  final OrdersRepository repository;
  final String id;
  @override
  State<OrderDetailPage> createState() => _OrderDetailPageState();
}

class _OrderDetailPageState extends State<OrderDetailPage> {
  late final RecipientOrderController model;
  @override
  void initState() {
    super.initState();
    model = RecipientOrderController(widget.repository, widget.id);
    model.load();
  }

  @override
  void dispose() {
    model.dispose();
    super.dispose();
  }

  Future<void> decide(RecipientOrder snapshot, String action) async {
    final reason = await showDialog<String>(
      context: context,
      builder: (_) => _OrderDecisionDialog(order: snapshot, action: action),
    );
    if (reason != null && mounted) {
      await model.transition(action, reason, version: snapshot.version);
    }
  }

  Future<void> amend(RecipientOrder order) async {
    await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) => OrderEntryPage(
          repository: OrderEntryRepository(widget.repository.api),
          storeId: order.storeId,
          orderId: order.id,
        ),
      ),
    );
    if (mounted) {
      await model.load();
    }
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) => PopScope(
      canPop: !model.busy && model.pending == null,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Detail pesanan'),
          actions: [
            IconButton(
              tooltip: 'Muat ulang detail pesanan',
              onPressed: model.busy ? null : model.load,
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
        body: ListView(
          key: const Key('order-detail-scroll'),
          padding: const EdgeInsets.all(16),
          children: [
            if (model.busy) const LinearProgressIndicator(),
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
                'Detail terbaru belum tersedia. Muat ulang sebelum tindakan berikutnya.',
              ),
            if (model.pending != null)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Hasil pencatatan belum diketahui. Pertahankan halaman ini dan ulangi operasi yang sama; jangan membuat penerimaan atau keputusan pengganti.',
                      ),
                      FilledButton(
                        onPressed: model.busy ? null : model.retry,
                        child: const Text('Ulangi pencatatan yang sama'),
                      ),
                    ],
                  ),
                ),
              ),
            if (model.order case final o?) ...[
              Text(
                o.destination,
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              Text('${o.status} · Versi ${o.version}'),
              Text('Total: Rp ${o.total}'),
              if (o.kind == 'internal_transfer')
                const Text('Transfer internal, bukan pembelian grosir.'),
              if (o.decisionNote != null)
                Text('Alasan keputusan: ${o.decisionNote}'),
              for (final line in o.lines)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Text(
                      '${line.sku} → ${line.localSku}\n${line.quantity} kemasan × ${line.packSize} biji · Rp ${line.unitPrice}/kemasan',
                    ),
                  ),
                ),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  OutlinedButton(
                    onPressed: model.canAct
                        ? () async {
                            await Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) => OrderChangesPage(
                                  repository: OrderChangesRepository(
                                    widget.repository.api,
                                  ),
                                  orderId: widget.id,
                                ),
                              ),
                            );
                            if (mounted) await model.load();
                          }
                        : null,
                    child: const Text('Persetujuan ulang dan riwayat'),
                  ),
                  if (o.allows('amend') && o.storeId.isNotEmpty)
                    OutlinedButton(
                      onPressed: model.canAct ? () => amend(o) : null,
                      child: const Text('Ubah pengajuan'),
                    ),
                  if (o.allows('approve'))
                    FilledButton(
                      onPressed: model.canAct
                          ? () => decide(o, 'approve')
                          : null,
                      child: const Text('Setujui pengajuan'),
                    ),
                  if (o.allows('reject'))
                    OutlinedButton(
                      onPressed: model.canAct
                          ? () => decide(o, 'reject')
                          : null,
                      child: const Text('Tolak pengajuan'),
                    ),
                  if (o.allows('cancel'))
                    OutlinedButton(
                      onPressed: model.canAct
                          ? () => decide(o, 'cancel')
                          : null,
                      child: const Text('Batalkan pesanan'),
                    ),
                ],
              ),
              if (!o.fulfillmentEnabled)
                const Text('Modul pengiriman belum aktif pada layanan ini.'),
              const Text('Pengiriman'),
              if (model.fresh && model.shipments.isEmpty)
                const Text('Belum ada kiriman untuk pesanan ini.'),
              for (final s in model.shipments)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          s.receivedAt == null
                              ? 'Menunggu penerimaan'
                              : 'Penerimaan dikonfirmasi',
                        ),
                        if (s.handler.isNotEmpty)
                          Text('Petugas pengiriman: ${s.handler}'),
                        for (final l in s.lines) ...[
                          Text(
                            'Batch ${l.batch} · dikirim ${l.sent} · baik ${l.good ?? '-'} · rusak ${l.damaged ?? '-'}',
                          ),
                          if (l.conditionNote != null)
                            Text('Kondisi: ${l.conditionNote}'),
                        ],
                        if (s.receiptNote != null)
                          Text('Catatan penerimaan: ${s.receiptNote}'),
                        if (o.allows('receive') &&
                            s.receivedAt == null &&
                            s.canIdentify)
                          OutlinedButton(
                            onPressed: model.canAct
                                ? () => model.select(s)
                                : null,
                            child: Text('Isi penerimaan ${s.id}'),
                          ),
                        if (model.selected == s)
                          ShipmentReceiptForm(
                            key: ValueKey('${s.id}:${o.version}'),
                            model: model,
                            shipment: s,
                            version: o.version,
                          ),
                      ],
                    ),
                  ),
                ),
            ],
            const Text(
              'Pengajuan baru tersedia dari daftar Pesanan. Perubahan sebelum persetujuan tersedia bagi pimpinan berwenang. Pemilihan sumber dan pengiriman tersedia melalui modul Gudang. Penerimaan/keputusan ini online; pemulihan otomatis setelah aplikasi ditutup paksa belum tersedia.',
            ),
          ],
        ),
      ),
    ),
  );
}

class _OrderDecisionDialog extends StatefulWidget {
  const _OrderDecisionDialog({required this.order, required this.action});
  final RecipientOrder order;
  final String action;
  @override
  State<_OrderDecisionDialog> createState() => _OrderDecisionDialogState();
}

class _OrderDecisionDialogState extends State<_OrderDecisionDialog> {
  final reason = TextEditingController();
  @override
  void dispose() {
    reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final o = widget.order;
    final action = widget.action;
    final title = switch (action) {
      'approve' => 'Setujui pengajuan',
      'reject' => 'Tolak pengajuan',
      _ => 'Batalkan pesanan',
    };
    return AlertDialog(
      title: Text(title),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('${o.destination} · Versi ${o.version} · Total Rp ${o.total}'),
            for (final line in o.lines)
              Text(
                '${line.sku} → ${line.localSku} · ${line.quantity} kemasan × ${line.packSize} biji · Rp ${line.unitPrice}/kemasan',
              ),
            if (action == 'approve')
              const Text(
                'Harga dan konversi akan diperiksa lagi oleh server. Persetujuan belum menambah stok tujuan.',
              ),
            if (action != 'approve')
              TextField(
                key: const Key('order-decision-reason'),
                controller: reason,
                maxLength: 2000,
                decoration: const InputDecoration(labelText: 'Alasan wajib'),
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
          onPressed: () =>
              Navigator.pop(context, action == 'approve' ? '' : reason.text),
          child: const Text('Konfirmasi keputusan'),
        ),
      ],
    );
  }
}
