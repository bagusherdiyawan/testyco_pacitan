import 'package:flutter/material.dart';
import 'receipt_history_page.dart';
import 'refund_page.dart';
import '../application/pos_controller.dart';
import '../data/pos_repository.dart';
import '../domain/cart_entry.dart';
import '../../../design_system/molecules/error_panel.dart';
import '../../../design_system/organisms/sale_receipt.dart';

class PosPage extends StatefulWidget {
  const PosPage({required this.repository, required this.storeId, super.key});
  final PosRepository repository;
  final String storeId;
  @override
  State<PosPage> createState() => _PosPageState();
}

class _PosPageState extends State<PosPage> {
  late final PosController model;
  String paymentMethod = 'cash';
  bool paymentVerified = false;
  final device = TextEditingController(),
      opening = TextEditingController(),
      cash = TextEditingController(),
      voucher = TextEditingController(),
      paymentReference = TextEditingController(),
      verifiedAmount = TextEditingController(),
      counted = TextEditingController(),
      note = TextEditingController(),
      cancelReason = TextEditingController();
  @override
  void initState() {
    super.initState();
    model = PosController(widget.repository, widget.storeId);
    model.load();
  }

  @override
  void dispose() {
    model.dispose();
    for (final c in [
      device,
      opening,
      cash,
      counted,
      note,
      cancelReason,
      voucher,
      paymentReference,
      verifiedAmount,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  Widget field(
    String label,
    TextEditingController controller, {
    bool number = false,
    bool enabled = true,
  }) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: TextField(
      controller: controller,
      enabled: enabled,
      decoration: InputDecoration(labelText: label),
      keyboardType: number ? TextInputType.number : TextInputType.text,
    ),
  );
  List<Widget> header() => [
    Row(
      children: [
        Expanded(
          child: Text(
            'Kasir toko',
            style: Theme.of(context).textTheme.titleLarge,
          ),
        ),
        IconButton(
          tooltip: 'Muat ulang POS',
          onPressed: model.busy ? null : model.load,
          icon: const Icon(Icons.refresh),
        ),
      ],
    ),
    TextButton.icon(
      onPressed:
          model.busy ||
              model.openingUncertain ||
              model.creatingUncertain ||
              model.paymentUncertain ||
              model.closingUncertain
          ? null
          : () => Navigator.of(context).push(
              MaterialPageRoute<void>(
                builder: (_) => ReceiptHistoryPage(
                  repository: widget.repository,
                  store: widget.storeId,
                ),
              ),
            ),
      icon: const Icon(Icons.receipt_long),
      label: const Text('Riwayat struk'),
    ),
    if (model.busy) const LinearProgressIndicator(),
    if (model.error != null)
      Padding(
        padding: const EdgeInsets.all(12),
        child: Text(
          model.error!,
          style: TextStyle(color: Theme.of(context).colorScheme.error),
        ),
      ),
    if (model.shift != null)
      Text(
        'Shift ${model.shift!['device_name']} · Kas seharusnya Rp ${model.shift!['expected_cash']}',
      ),
  ];
  Widget cartTile(CartEntry entry) => Card(
    key: ValueKey('cart-${entry.product.id}'),
    child: Padding(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text(entry.product.name)),
              IconButton(
                tooltip: 'Hapus ${entry.product.sku}',
                onPressed: model.cartLocked
                    ? null
                    : () => model.remove(entry.product.id),
                icon: const Icon(Icons.delete_outline),
              ),
            ],
          ),
          if (entry.product.isBundle) ...[
            const Text(
              'Satu unit = satu paket. Stok komponen dipotong saat pembayaran.',
            ),
            for (final component in entry.product.components) ...[
              Text(
                '${component['sku']} · ${component['pieces']} biji per paket',
              ),
              DropdownButtonFormField<String>(
                key: ValueKey(
                  'bundle-stock-${entry.product.id}-${component['product_id']}',
                ),
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Batch komponen'),
                items: [
                  for (final b in model.componentChoices(component))
                    DropdownMenuItem(
                      value: b['key'] as String,
                      child: Text(
                        b['label'] as String,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                ],
                onChanged: model.cartLocked
                    ? null
                    : (v) => model.componentStock(
                        entry,
                        component['product_id'] as String,
                        v,
                      ),
              ),
            ],
          ],
          if (!entry.product.isBundle)
            DropdownButtonFormField<String>(
              isExpanded: true,
              initialValue: entry.unit,
              decoration: const InputDecoration(labelText: 'Satuan'),
              items: const [
                DropdownMenuItem(value: 'piece', child: Text('Biji')),
                DropdownMenuItem(value: 'portion', child: Text('Porsi')),
                DropdownMenuItem(value: 'pack', child: Text('Kemasan')),
              ],
              onChanged: model.cartLocked
                  ? null
                  : (v) {
                      if (v != null) {
                        model.unit(entry, v);
                      }
                    },
            ),
          TextFormField(
            key: ValueKey('qty-${entry.product.id}'),
            initialValue: '${entry.quantity}',
            decoration: const InputDecoration(labelText: 'Jumlah unit'),
            keyboardType: TextInputType.number,
            enabled: !model.cartLocked,
            onChanged: (v) => model.quantity(entry, v),
          ),
          if (!entry.product.isBundle)
            DropdownButtonFormField<String>(
              key: ValueKey('${entry.product.id}-${entry.unit}-${entry.stock}'),
              initialValue: entry.stock,
              isExpanded: true,
              decoration: const InputDecoration(
                labelText: 'Batch / keadaan stok',
              ),
              items: [
                for (final c in model.choices(entry))
                  DropdownMenuItem(
                    value: c['key'] as String,
                    child: Text(
                      c['label'] as String,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
              ],
              onChanged: model.cartLocked ? null : (v) => model.stock(entry, v),
            ),
          if (entry.stock != null)
            Text(
              'Expiry kemasan: ${model.choices(entry).where((c) => c["key"] == entry.stock).firstOrNull?["expiry"] ?? "—"}',
            ),
        ],
      ),
    ),
  );
  List<Widget> cartWidgets() => [
    Text('Keranjang', style: Theme.of(context).textTheme.titleLarge),
    for (final entry in model.cart.values) cartTile(entry),
    if (model.cart.isEmpty)
      const Text('Pilih produk dan sumber stok. Total final dihitung server.'),
    field(
      'Kode voucher (opsional)',
      voucher,
      enabled: !model.busy && !model.creatingUncertain,
    ),
    FilledButton(
      onPressed: model.busy
          ? null
          : () => model.review(voucherCode: voucher.text),
      child: Text(
        model.creatingUncertain
            ? 'Ulangi pembuatan draft yang sama'
            : 'Tinjau pembayaran',
      ),
    ),
  ];
  Widget review() => ListView(
    padding: const EdgeInsets.all(16),
    children: [
      ...header(),
      Text('Draft ${model.intent!['id']}'),
      for (final item in model.intent!['items'] as List)
        ListTile(
          title: Text(item['name'] as String),
          subtitle: Text(
            '${item['quantity']} ${item['unit']} × Rp ${item['unit_price']}',
          ),
        ),
      Text(
        'Total Rp ${model.intent!['total_amount']}',
        style: Theme.of(context).textTheme.headlineSmall,
      ),
      if (model.intent!['voucher_snapshot'] != null)
        Text(
          'Voucher ${model.intent!['voucher_snapshot']['code']} · Diskon Rp ${model.intent!['discount_amount']}',
        ),
      DropdownButtonFormField<String>(
        isExpanded: true,
        initialValue: paymentMethod,
        decoration: const InputDecoration(labelText: 'Metode pembayaran'),
        items: const [
          DropdownMenuItem(value: 'cash', child: Text('Tunai')),
          DropdownMenuItem(
            value: 'qris_manual',
            child: Text('QRIS · verifikasi manual'),
          ),
          DropdownMenuItem(
            value: 'bank_transfer',
            child: Text('Transfer · verifikasi manual'),
          ),
        ],
        onChanged: model.busy || model.paymentUncertain
            ? null
            : (v) => setState(() {
                paymentMethod = v!;
                paymentVerified = false;
              }),
      ),
      if (paymentMethod == 'cash')
        field(
          'Tunai diterima (Rp)',
          cash,
          number: true,
          enabled: !model.busy && !model.paymentUncertain,
        ),
      if (paymentMethod != 'cash') ...[
        field(
          'Referensi bukti pembayaran',
          paymentReference,
          enabled: !model.busy && !model.paymentUncertain,
        ),
        field(
          'Nominal aktual terverifikasi (Rp)',
          verifiedAmount,
          number: true,
          enabled: !model.busy && !model.paymentUncertain,
        ),
        CheckboxListTile(
          value: paymentVerified,
          onChanged: model.busy || model.paymentUncertain
              ? null
              : (v) => setState(() => paymentVerified = v!),
          title: const Text(
            'Saya telah memeriksa bukti dan pembayaran aktual. Pemilihan metode saja tidak berarti lunas.',
          ),
        ),
      ],
      FilledButton(
        onPressed: model.busy
            ? null
            : () => model.pay(
                cash.text,
                method: paymentMethod,
                reference: paymentReference.text,
                verified: paymentVerified,
                verifiedAmount: verifiedAmount.text,
              ),
        child: Text(
          model.paymentUncertain
              ? 'Coba ulang pembayaran yang sama'
              : (paymentMethod == 'cash'
                    ? 'Konfirmasi tunai'
                    : 'Konfirmasi pembayaran diverifikasi'),
        ),
      ),
      if (model.paymentUncertain)
        const Text(
          'Hasil pembayaran belum pasti. Ulangi request yang sama atau muat ulang/pulihkan draft; jangan membuat transaksi baru.',
        ),
      field(
        'Alasan pembatalan',
        cancelReason,
        enabled: !model.busy && !model.paymentUncertain,
      ),
      TextButton(
        onPressed: model.busy || model.paymentUncertain
            ? null
            : () => model.cancel(cancelReason.text),
        child: const Text('Batalkan draft'),
      ),
    ],
  );
  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) {
      if (model.context == null) {
        if (model.error != null) {
          return ErrorPanel(message: model.error!, onRetry: model.load);
        }
        return const Center(child: CircularProgressIndicator());
      }
      if (model.receipt != null) {
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ...header(),
            SaleReceipt(sale: model.receipt!),
            if (model.context?['can_manage'] == true)
              OutlinedButton(
                onPressed: model.busy
                    ? null
                    : () async {
                        await Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) => RefundPage(
                              api: widget.repository.api,
                              sale: model.receipt!['id'],
                            ),
                          ),
                        );
                        if (mounted) {
                          await model.showReceipt(model.receipt!['id']);
                        }
                      },
                child: const Text('Tinjau refund'),
              ),
            FilledButton(
              onPressed: model.busy
                  ? null
                  : () {
                      cash.clear();
                      voucher.clear();
                      paymentReference.clear();
                      verifiedAmount.clear();
                      paymentMethod = 'cash';
                      paymentVerified = false;
                      model.newSale();
                    },
              child: const Text('Transaksi baru'),
            ),
          ],
        );
      }
      if (model.intent != null) {
        return review();
      }
      final contextData = model.context!;
      if ((contextData['store'] as Map)['active'] != true) {
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ...header(),
            const Text('Toko nonaktif. Transaksi baru ditolak.'),
          ],
        );
      }
      if (model.shift == null) {
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ...header(),
            if (model.closedShift != null)
              Text(
                'Shift ditutup · Selisih Rp ${model.closedShift!['difference_cash']}',
              ),
            field(
              'Nama terminal',
              device,
              enabled: !model.busy && !model.openingUncertain,
            ),
            field(
              'Kas awal (Rp)',
              opening,
              number: true,
              enabled: !model.busy && !model.openingUncertain,
            ),
            FilledButton(
              onPressed: model.busy
                  ? null
                  : () => model.open(device.text, opening.text),
              child: Text(
                model.openingUncertain
                    ? 'Ulangi buka shift yang sama'
                    : 'Buka shift',
              ),
            ),
          ],
        );
      }
      final pendingWidgets = <Widget>[
        for (final p in model.pending)
          ListTile(
            title: Text('Pulihkan draft Rp ${p['total_amount']}'),
            subtitle: Text(p['id'] as String),
            onTap: model.busy ? null : () => model.recover(p['id'] as String),
          ),
      ];
      final recent = (contextData['recent_sales'] as List)
          .cast<Map<String, dynamic>>();
      final footer = <Widget>[
        ExpansionTile(
          title: const Text('Struk terbaru'),
          children: [
            for (final s in recent)
              ListTile(
                title: Text(s['number'] as String),
                subtitle: Text('Rp ${s['total_amount']}'),
                onTap: model.busy
                    ? null
                    : () => model.showReceipt(s['id'] as String),
              ),
          ],
        ),
        ExpansionTile(
          title: const Text('Tutup shift'),
          children: [
            field(
              'Kas fisik hasil hitungan (Rp)',
              counted,
              number: true,
              enabled: !model.busy,
            ),
            field('Catatan selisih', note, enabled: !model.busy),
            FilledButton(
              onPressed: model.cartLocked || model.pending.isNotEmpty
                  ? null
                  : () => model.close(counted.text, note.text),
              child: const Text('Konfirmasi tutup shift'),
            ),
          ],
        ),
      ];
      return LayoutBuilder(
        builder: (context, constraints) {
          final catalog = <Widget>[
            TextField(
              decoration: const InputDecoration(labelText: 'Cari produk POS'),
              onChanged: model.search,
            ),
            for (final p in model.filtered)
              Card(
                child: ListTile(
                  title: Text(p.name),
                  subtitle: Text('${p.sku} · Rp ${p.retailPrice}/biji'),
                  trailing: IconButton(
                    tooltip: 'Tambah ${p.sku}',
                    icon: const Icon(Icons.add),
                    onPressed: model.cartLocked ? null : () => model.add(p),
                  ),
                ),
              ),
          ];
          if (constraints.maxWidth >= 900) {
            return Column(
              children: [
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(children: header()),
                ),
                Expanded(
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: ListView(
                          padding: const EdgeInsets.all(16),
                          children: catalog,
                        ),
                      ),
                      Expanded(
                        child: ListView(
                          padding: const EdgeInsets.all(16),
                          children: [
                            ...pendingWidgets,
                            ...cartWidgets(),
                            ...footer,
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            );
          }
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              ...header(),
              ...pendingWidgets,
              ...catalog,
              ...cartWidgets(),
              ...footer,
            ],
          );
        },
      );
    },
  );
}
