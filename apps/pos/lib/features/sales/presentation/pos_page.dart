import 'dart:typed_data';
import '../../printing/application/receipt_printer.dart';
import '../../printing/presentation/receipt_print_page.dart';
import 'package:flutter/material.dart';
import '../../catalog/domain/catalog_product.dart';
import 'receipt_history_page.dart';
import 'refund_page.dart';
import 'offline_page.dart';
import '../data/offline_repository.dart';
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
  String conditionFilter = 'all';
  bool paymentVerified = false;
  bool printingShift = false;
  String? shiftPrintMessage;
  final Map<String, TextEditingController> _qtyControllers = {};
  final device = TextEditingController(),
      opening = TextEditingController(),
      openingNote = TextEditingController(),
      cash = TextEditingController(),
      voucher = TextEditingController(),
      paymentReference = TextEditingController(),
      verifiedAmount = TextEditingController(),
      counted = TextEditingController(),
      retained = TextEditingController(),
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
      openingNote,
      cash,
      counted,
      retained,
      note,
      cancelReason,
      voucher,
      paymentReference,
      verifiedAmount,
    ]) {
      c.dispose();
    }
    for (final c in _qtyControllers.values) {
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
    if (widget.repository.deviceState != null &&
        widget.repository.api.identity?['id'] is int)
      OutlinedButton(
        onPressed:
            model.busy || model.paymentUncertain || model.creatingUncertain
            ? null
            : () async {
                await Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) => OfflinePage(
                      repository: OfflineRepository(
                        widget.repository.api,
                        widget.repository.deviceState!,
                      ),
                      storeId: widget.storeId,
                      shiftId: model.shift?['id'],
                    ),
                  ),
                );
                if (mounted) await model.load();
              },
        child: const Text('Offline tunai dan antrean terenkripsi'),
      ),
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
        model.canManage && model.shift!['expected_cash'] != null
            ? 'Shift ${model.shift!['device_name']} · Kas seharusnya Rp ${model.shift!['expected_cash']}'
            : 'Shift ${model.shift!['device_name']} · Mode hitung buta aktif',
      ),
  ];
  Widget productThumb(CatalogProduct p) {
    if (!p.hasImage) {
      return Container(
        height: 56,
        width: double.infinity,
        decoration: BoxDecoration(
          color: const Color(0xFFFEF2F2),
          border: Border.all(color: const Color(0xFFFECACA)),
          borderRadius: BorderRadius.circular(10),
        ),
        alignment: Alignment.center,
        child: Text(
          p.sku,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            fontWeight: FontWeight.w800,
            fontSize: 12,
            color: Theme.of(context).colorScheme.primary,
          ),
        ),
      );
    }
    return FutureBuilder<Uint8List?>(
      future: widget.repository.productImage(widget.storeId, p.id),
      builder: (context, snapshot) {
        final bytes = snapshot.data;
        if (bytes == null) {
          return Container(
            height: 56,
            width: double.infinity,
            decoration: BoxDecoration(
              color: const Color(0xFFF1F5F9),
              borderRadius: BorderRadius.circular(10),
            ),
            alignment: Alignment.center,
            child: Icon(
              Icons.fastfood_outlined,
              color: Theme.of(context).colorScheme.primary,
            ),
          );
        }
        return ClipRRect(
          borderRadius: BorderRadius.circular(10),
          child: Image.memory(
            bytes,
            height: 56,
            width: double.infinity,
            fit: BoxFit.cover,
          ),
        );
      },
    );
  }

  Widget productCard(CatalogProduct p) => Card(
    child: InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: model.cartLocked ? null : () => model.add(p),
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            productThumb(p),
            const SizedBox(height: 8),
            Text(
              p.name,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13),
            ),
            const SizedBox(height: 2),
            Text(
              '${p.sku} · ${p.isBundle ? 'Paket' : (p.condition == 'ready' ? 'Siap' : 'Beku')}',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 6),
            Row(
              children: [
                Expanded(
                  child: Text(
                    'Rp ${p.retailPrice}/biji',
                    style: TextStyle(
                      fontWeight: FontWeight.w800,
                      fontSize: 13,
                      color: Theme.of(context).colorScheme.primary,
                    ),
                  ),
                ),
                IconButton(
                  tooltip: 'Tambah ${p.sku}',
                  visualDensity: VisualDensity.compact,
                  icon: Icon(
                    Icons.add_circle,
                    color: Theme.of(context).colorScheme.primary,
                  ),
                  onPressed: model.cartLocked ? null : () => model.add(p),
                ),
              ],
            ),
          ],
        ),
      ),
    ),
  );

  List<Widget> buildCatalogWidgets(double availableWidth) {
    final items = model.filtered.where((p) {
      if (conditionFilter == 'frozen') return !p.isBundle && p.condition == 'frozen';
      if (conditionFilter == 'ready') return !p.isBundle && p.condition == 'ready';
      if (conditionFilter == 'bundle') return p.isBundle;
      return true;
    }).toList();
    final cols = availableWidth >= 600 ? 3 : 2;
    final rows = <Widget>[];
    for (var i = 0; i < items.length; i += cols) {
      final slice = items.skip(i).take(cols).toList();
      rows.add(
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              for (var j = 0; j < cols; j++) ...[
                if (j > 0) const SizedBox(width: 8),
                Expanded(
                  child: j < slice.length
                      ? productCard(slice[j])
                      : const SizedBox.shrink(),
                ),
              ],
            ],
          ),
        ),
      );
    }
    return [
      TextField(
        decoration: const InputDecoration(
          labelText: 'Cari produk POS',
          prefixIcon: Icon(Icons.search),
        ),
        onChanged: model.search,
      ),
      const SizedBox(height: 8),
      Wrap(
        spacing: 8,
        runSpacing: 4,
        children: [
          for (final entry in const [
            ('all', 'Semua'),
            ('frozen', 'Beku'),
            ('ready', 'Siap jual'),
            ('bundle', 'Paket'),
          ])
            ChoiceChip(
              label: Text(entry.$2),
              selected: conditionFilter == entry.$1,
              selectedColor: Theme.of(context).colorScheme.primary,
              checkmarkColor: Colors.white,
              labelStyle: TextStyle(
                color: conditionFilter == entry.$1
                    ? Colors.white
                    : const Color(0xFF334155),
                fontWeight: conditionFilter == entry.$1
                    ? FontWeight.w700
                    : FontWeight.w600,
              ),
              onSelected: (_) => setState(() => conditionFilter = entry.$1),
            ),
        ],
      ),
      const SizedBox(height: 8),
      if (items.isEmpty)
        const Padding(
          padding: EdgeInsets.symmetric(vertical: 16),
          child: Text('Tidak ada produk yang cocok.'),
        ),
      ...rows,
    ];
  }

  TextEditingController _qtyControllerFor(CartEntry entry) {
    final c = _qtyControllers.putIfAbsent(
      entry.product.id,
      () => TextEditingController(text: '${entry.quantity}'),
    );
    final desired = '${entry.quantity}';
    if (c.text != desired && entry.quantity > 0) {
      c.value = TextEditingValue(
        text: desired,
        selection: TextSelection.collapsed(offset: desired.length),
      );
    }
    return c;
  }

  Widget cartTile(CartEntry entry) {
    final qtyCtrl = _qtyControllerFor(entry);
    return Card(
      key: ValueKey('cart-${entry.product.id}'),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    entry.product.name,
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                ),
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
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Row(
              children: [
                IconButton.outlined(
                  tooltip: 'Kurangi ${entry.product.sku}',
                  onPressed: model.cartLocked
                      ? null
                      : () {
                          model.decrement(entry);
                          if (model.cart.containsKey(entry.product.id)) {
                            qtyCtrl.text = '${entry.quantity}';
                          }
                        },
                  icon: const Icon(Icons.remove),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: TextFormField(
                    key: ValueKey('qty-${entry.product.id}'),
                    controller: qtyCtrl,
                    textAlign: TextAlign.center,
                    decoration: const InputDecoration(labelText: 'Jumlah unit'),
                    keyboardType: TextInputType.number,
                    enabled: !model.cartLocked,
                    onChanged: (v) => model.quantity(entry, v),
                  ),
                ),
                const SizedBox(width: 8),
                IconButton.outlined(
                  tooltip: 'Tambah jumlah ${entry.product.sku}',
                  onPressed: model.cartLocked
                      ? null
                      : () {
                          model.increment(entry);
                          qtyCtrl.text = '${entry.quantity}';
                        },
                  icon: const Icon(Icons.add),
                ),
              ],
            ),
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
  }
  List<Widget> cartWidgets() {
    final activeVouchers = ((model.context?['vouchers'] as List?) ?? [])
        .cast<Map<String, dynamic>>();
    return [
      Text('Keranjang', style: Theme.of(context).textTheme.titleLarge),
      for (final entry in model.cart.values) cartTile(entry),
      if (model.cart.isEmpty)
        const Text('Pilih produk dan sumber stok. Total final dihitung server.'),
      if (model.consumablesEnabled)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: DropdownButtonFormField<String>(
            key: ValueKey('service-type-${model.serviceType}'),
            initialValue: model.serviceType,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Jenis penjualan'),
            items: const [
              DropdownMenuItem(value: 'dine_in', child: Text('Makan di tempat')),
              DropdownMenuItem(
                value: 'takeaway',
                child: Text('Bawa pulang (pakai kemasan)'),
              ),
            ],
            onChanged: model.cartLocked ? null : model.setServiceType,
          ),
        ),
      if (activeVouchers.isNotEmpty) ...[
        const SizedBox(height: 8),
        Text(
          'Voucher aktif (tap untuk pakai)',
          style: Theme.of(context).textTheme.labelMedium,
        ),
        const SizedBox(height: 4),
        Wrap(
          spacing: 8,
          runSpacing: 4,
          children: [
            for (final v in activeVouchers)
              ChoiceChip(
                label: Text('${v['code']}'),
                selected: voucher.text.trim() == '${v['code']}',
                selectedColor: Theme.of(context).colorScheme.primary,
                checkmarkColor: Colors.white,
                labelStyle: TextStyle(
                  color: voucher.text.trim() == '${v['code']}'
                      ? Colors.white
                      : const Color(0xFF334155),
                  fontWeight: FontWeight.w600,
                ),
                onSelected: model.busy || model.creatingUncertain
                    ? null
                    : (selected) => setState(() {
                        voucher.text = selected ? '${v['code']}' : '';
                      }),
              ),
          ],
        ),
      ],
      field(
        'Kode voucher (opsional)',
        voucher,
        enabled: !model.busy && !model.creatingUncertain,
      ),
    ];
  }

  Widget stickyCheckoutBar() => Container(
    padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
    decoration: const BoxDecoration(
      color: Colors.white,
      border: Border(top: BorderSide(color: Color(0xFFE2E8F0))),
    ),
    child: SafeArea(
      top: false,
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  model.cart.isEmpty
                      ? 'Keranjang kosong'
                      : '${model.totalCartUnits} item · ${model.cart.length} produk',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                Text(
                  'Estimasi Rp ${model.estimatedSubtotal}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontWeight: FontWeight.w800,
                    fontSize: 16,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Flexible(
            child: FilledButton(
              onPressed: model.busy
                  ? null
                  : () => model.review(voucherCode: voucher.text),
              child: Text(
                model.creatingUncertain
                    ? 'Ulangi pembuatan draft yang sama'
                    : 'Tinjau pembayaran',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ),
        ],
      ),
    ),
  );
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
      if (paymentMethod == 'cash') ...[
        field(
          'Tunai diterima (Rp)',
          cash,
          number: true,
          enabled: !model.busy && !model.paymentUncertain,
        ),
        Wrap(
          spacing: 8,
          runSpacing: 6,
          children: [
            ActionChip(
              label: const Text('Uang Pas'),
              onPressed: model.busy || model.paymentUncertain
                  ? null
                  : () => setState(
                      () => cash.text = '${model.intent!['total_amount'] ?? 0}',
                    ),
            ),
            for (final amount in const [20000, 50000, 100000])
              ActionChip(
                label: Text('Rp $amount'),
                onPressed: model.busy || model.paymentUncertain
                    ? null
                    : () => setState(() => cash.text = '$amount'),
              ),
          ],
        ),
        const SizedBox(height: 8),
      ],
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
            SaleReceipt(
              sale: model.receipt!,
              onPrint: widget.repository.deviceState == null
                  ? null
                  : () => Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => ReceiptPrintPage(
                          printer: ReceiptPrinter(
                            widget.repository.deviceState!,
                            '${widget.repository.api.baseUrl}:${widget.repository.api.identity?["id"]}',
                          ),
                          sale: model.receipt!,
                        ),
                      ),
                    ),
            ),
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
            if (model.closedShift != null) ...[
              Text(
                'Shift ditutup · Selisih Rp ${model.closedShift!['difference_cash']}',
              ),
              if (model.closedShift!['deposit_cash'] != null)
                Text(
                  'Setoran Rp ${model.closedShift!['deposit_cash']} · Sisa modal Rp ${model.closedShift!['retained_cash'] ?? model.closedShift!['counted_cash']}',
                ),
              if (widget.repository.deviceState != null) ...[
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  onPressed: printingShift
                      ? null
                      : () async {
                          setState(() {
                            printingShift = true;
                            shiftPrintMessage = null;
                          });
                          try {
                            final printer = ReceiptPrinter(
                              widget.repository.deviceState!,
                              '${widget.repository.api.baseUrl}:${widget.repository.api.identity?["id"]}',
                            );
                            await printer.printShiftSummary(
                              model.closedShift!,
                              storeName:
                                  (contextData['store'] as Map)['name']
                                      as String?,
                            );
                            if (mounted) {
                              setState(
                                () => shiftPrintMessage =
                                    'Rekap tutup shift dikirim ke printer.',
                              );
                            }
                          } catch (e) {
                            if (mounted) {
                              setState(() => shiftPrintMessage = e.toString());
                            }
                          } finally {
                            if (mounted) setState(() => printingShift = false);
                          }
                        },
                  icon: const Icon(Icons.print_outlined),
                  label: Text(
                    printingShift
                        ? 'Mencetak rekap...'
                        : 'Cetak rekap tutup shift',
                  ),
                ),
                if (shiftPrintMessage != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(shiftPrintMessage!),
                  ),
              ],
            ],
            if (model.blockingOpenShift != null)
              Card(
                color: Theme.of(context).colorScheme.errorContainer,
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Text(
                    'Pergantian shift tertahan: shift ${model.blockingOpenShift!['cashier_name']} (${model.blockingOpenShift!['device_name']}) masih terbuka. Wajib tutup shift sebelumnya secara online.',
                  ),
                ),
              ),
            if (model.handover != null)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Text(
                    'Serah terima dari ${model.handover!['previous_cashier_name']} · Sisa modal diharapkan Rp ${model.handover!['expected_opening_cash']}',
                  ),
                ),
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
            field(
              'Catatan serah terima kas awal (wajib bila selisih)',
              openingNote,
              enabled: !model.busy && !model.openingUncertain,
            ),
            FilledButton(
              onPressed: model.busy
                  ? null
                  : () => model.open(
                      device.text,
                      opening.text,
                      openingNote: openingNote.text,
                    ),
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
            if (model.offlineBlockerMessage != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(
                  model.offlineBlockerMessage!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            if (model.pendingCashRequests.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(
                  'Masih ada ${model.pendingCashRequests.length} pengajuan kas pending. Selesaikan/batalkan di modul Kas shift.',
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            field(
              'Kas fisik hasil hitungan (Rp)',
              counted,
              number: true,
              enabled: !model.busy && !model.closingUncertain,
            ),
            field(
              'Sisa modal di laci untuk shift berikut (Rp, opsional)',
              retained,
              number: true,
              enabled: !model.busy && !model.closingUncertain,
            ),
            field(
              'Catatan selisih',
              note,
              enabled: !model.busy && !model.closingUncertain,
            ),
            FilledButton(
              onPressed:
                  model.cartLocked ||
                      model.pending.isNotEmpty ||
                      model.offlineBlockerMessage != null ||
                      model.pendingCashRequests.isNotEmpty
                  ? null
                  : () => model.close(
                      counted.text,
                      note.text,
                      retainedText: retained.text,
                    ),
              child: Text(
                model.closingUncertain
                    ? 'Ulangi tutup shift yang sama'
                    : 'Konfirmasi tutup shift',
              ),
            ),
          ],
        ),
      ];
      return LayoutBuilder(
        builder: (context, constraints) {
          if (constraints.maxWidth >= 900) {
            final leftWidth = (constraints.maxWidth * 0.56) - 32;
            final catalog = buildCatalogWidgets(leftWidth);
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
                        flex: 6,
                        child: ListView(
                          padding: const EdgeInsets.all(16),
                          children: catalog,
                        ),
                      ),
                      const VerticalDivider(width: 1),
                      Expanded(
                        flex: 5,
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
                stickyCheckoutBar(),
              ],
            );
          }
          final catalog = buildCatalogWidgets(constraints.maxWidth - 32);
          return Column(
            children: [
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    ...header(),
                    ...pendingWidgets,
                    ...catalog,
                    ...cartWidgets(),
                    ...footer,
                  ],
                ),
              ),
              stickyCheckoutBar(),
            ],
          );
        },
      );
    },
  );
}
