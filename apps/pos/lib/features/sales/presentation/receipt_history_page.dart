import 'package:flutter/material.dart';
import '../../../core/domain/wib_date_range.dart';
import '../../../design_system/formatters.dart';
import '../../../design_system/molecules/error_panel.dart';
import '../../../design_system/molecules/receipt_history_card.dart';
import '../../../design_system/organisms/sale_receipt.dart';
import '../application/receipt_history_controller.dart';
import '../data/pos_repository.dart';

class ReceiptHistoryPage extends StatefulWidget {
  const ReceiptHistoryPage({
    required this.repository,
    required this.store,
    super.key,
  });
  final PosRepository repository;
  final String store;
  @override
  State<ReceiptHistoryPage> createState() => _ReceiptHistoryPageState();
}

class _ReceiptHistoryPageState extends State<ReceiptHistoryPage> {
  late final ReceiptHistoryController model;
  late final TextEditingController from, to;
  final query = TextEditingController();
  String? formError;
  @override
  void initState() {
    super.initState();
    model = ReceiptHistoryController(widget.repository, widget.store);
    from = TextEditingController(text: WibDateRange.day(model.range.from));
    to = TextEditingController(text: WibDateRange.day(model.range.to));
    model.load();
  }

  @override
  void dispose() {
    model.dispose();
    from.dispose();
    to.dispose();
    query.dispose();
    super.dispose();
  }

  void apply() {
    try {
      final range = WibDateRange.form(from.text, to.text);
      if (query.text.trim().length > 100) {
        throw const FormatException('Pencarian maksimal 100 karakter.');
      }
      setState(() => formError = null);
      model.load(next: range, search: query.text.trim());
    } catch (e) {
      setState(() => formError = 'Filter belum diterapkan: $e');
    }
  }

  Widget field(String label, TextEditingController c) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: TextField(
      controller: c,
      enabled: !model.busy,
      decoration: InputDecoration(labelText: label),
    ),
  );
  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) {
      final result = model.result;
      return Scaffold(
        appBar: AppBar(
          title: const Text('Riwayat struk'),
          actions: [
            IconButton(
              tooltip: 'Muat ulang riwayat',
              onPressed: model.busy ? null : model.retry,
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
        body: ListView(
          key: const Key('receipt-history-scroll'),
          padding: const EdgeInsets.all(16),
          children: [
            if (model.selectedId == null) ...[
              ExpansionTile(
                title: const Text('Filter struk'),
                children: [
                  field('Tanggal awal WIB (YYYY-MM-DD)', from),
                  field('Tanggal akhir WIB (YYYY-MM-DD)', to),
                  field('Cari nomor struk', query),
                  FilledButton(
                    onPressed: model.busy ? null : apply,
                    child: const Text('Terapkan filter'),
                  ),
                ],
              ),
              if (formError != null) Text(formError!),
              Text(
                'Periode diterapkan: ${WibDateRange.day(model.range.from)} — ${WibDateRange.day(model.range.to)} WIB',
              ),
              if (model.query.isNotEmpty) Text('Nomor struk: ${model.query}'),
            ] else
              TextButton(
                onPressed: model.busy ? null : () => model.load(),
                child: const Text('Kembali ke daftar struk'),
              ),
            if (model.busy) const LinearProgressIndicator(),
            if (model.error != null)
              ErrorPanel(message: model.error!, onRetry: model.retry),
            if (model.receipt != null) SaleReceipt(sale: model.receipt!),
            if (result != null) ...[
              Text(
                'Toko ${result.storeName} · ${result.scope == 'own' ? 'Struk saya' : 'Seluruh kasir toko'}',
              ),
              if (result.rows.isEmpty)
                const Text('Tidak ada struk pada filter ini.'),
              for (final row in result.rows)
                ReceiptHistoryCard(
                  number: row['number'] as String,
                  time: formatWib(row['sold_at'] as String),
                  cashier: row['cashier_name'] as String,
                  total: formatRupiah(row['total_amount'] as int),
                  onOpen: model.busy
                      ? null
                      : () => model.open(row['id'] as String),
                ),
              Wrap(
                spacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  TextButton(
                    onPressed: model.busy || result.page <= 1
                        ? null
                        : () => model.load(nextPage: result.page - 1),
                    child: const Text('Sebelumnya'),
                  ),
                  Text('Halaman ${result.page} / ${result.lastPage}'),
                  TextButton(
                    onPressed: model.busy || result.page >= result.lastPage
                        ? null
                        : () => model.load(nextPage: result.page + 1),
                    child: const Text('Berikutnya'),
                  ),
                ],
              ),
            ],
          ],
        ),
      );
    },
  );
}
