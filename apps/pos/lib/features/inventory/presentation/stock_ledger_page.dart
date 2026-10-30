import 'package:flutter/material.dart';
import '../../../design_system/formatters.dart';
import '../../../design_system/molecules/error_panel.dart';
import '../../../design_system/molecules/stock_movement_card.dart';
import '../application/stock_ledger_controller.dart';
import '../data/stock_ledger_repository.dart';
import '../domain/stock_ledger.dart';

class StockLedgerHistoryPage extends StatefulWidget {
  const StockLedgerHistoryPage({
    required this.repository,
    required this.store,
    this.batchId,
    super.key,
  });
  final StockLedgerRepository repository;
  final String store;
  final String? batchId;
  @override
  State<StockLedgerHistoryPage> createState() => _StockLedgerHistoryPageState();
}

class _StockLedgerHistoryPageState extends State<StockLedgerHistoryPage> {
  late final StockLedgerController model;
  late final TextEditingController from, to;
  final query = TextEditingController();
  String? source, bucket, formError;
  @override
  void initState() {
    super.initState();
    model = StockLedgerController(
      widget.repository,
      widget.store,
      batchId: widget.batchId,
    );
    from = TextEditingController(text: LedgerFilter.day(model.filter.from));
    to = TextEditingController(text: LedgerFilter.day(model.filter.to));
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
      final filter = LedgerFilter.form(
        from.text,
        to.text,
        query: query.text,
        batchId: model.filter.batchId,
        source: source,
        bucket: bucket,
      );
      setState(() => formError = null);
      model.load(next: filter);
    } catch (e) {
      setState(() => formError = 'Filter belum diterapkan: $e');
    }
  }

  Widget field(String label, TextEditingController value, {int? limit}) =>
      Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: TextField(
          controller: value,
          enabled: !model.busy,
          maxLength: limit,
          decoration: InputDecoration(labelText: label),
        ),
      );
  Widget choice(
    String label,
    String? value,
    Map<String, String> options,
    ValueChanged<String?> change,
  ) => DropdownButtonFormField<String>(
    initialValue: value ?? '',
    isExpanded: true,
    decoration: InputDecoration(labelText: label),
    items: [
      const DropdownMenuItem(value: '', child: Text('Semua')),
      for (final e in options.entries)
        DropdownMenuItem(value: e.key, child: Text(e.value)),
    ],
    onChanged: model.busy
        ? null
        : (v) => setState(() => change(v == '' ? null : v)),
  );
  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) {
      final result = model.result;
      return Scaffold(
        appBar: AppBar(
          title: const Text('Riwayat pergerakan stok'),
          actions: [
            IconButton(
              tooltip: 'Muat ulang halaman riwayat',
              onPressed: model.busy ? null : () => model.load(),
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
        body: ListView(
          key: const Key('ledger-scroll'),
          padding: const EdgeInsets.all(16),
          children: [
            const Text(
              'Perubahan per bucket dalam biji. Baris yang terlihat bukan saldo akhir batch. Data tiap halaman dibaca terpisah; gunakan untuk menelusuri, bukan mencatat pengganti otomatis.',
            ),
            ExpansionTile(
              title: const Text('Filter riwayat'),
              children: [
                field('Tanggal awal WIB (YYYY-MM-DD)', from),
                field('Tanggal akhir WIB (YYYY-MM-DD)', to),
                field('Cari SKU / nama / batch', query, limit: 100),
                choice(
                  'Jenis sumber',
                  source,
                  stockSourceLabels,
                  (v) => source = v,
                ),
                choice(
                  'Bucket stok',
                  bucket,
                  stockBucketLabels,
                  (v) => bucket = v,
                ),
                FilledButton(
                  onPressed: model.busy ? null : apply,
                  child: const Text('Terapkan filter'),
                ),
              ],
            ),
            if (formError != null)
              Text(
                formError!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            Text(
              'Periode diterapkan: ${LedgerFilter.day(model.filter.from)} — ${LedgerFilter.day(model.filter.to)} WIB',
            ),
            if (model.filter.source != null)
              Text(
                'Sumber diterapkan: ${stockSourceLabels[model.filter.source] ?? model.filter.source}',
              ),
            if (model.filter.bucket != null)
              Text(
                'Bucket diterapkan: ${stockBucketLabels[model.filter.bucket] ?? model.filter.bucket}',
              ),
            if (model.filter.query.isNotEmpty)
              Text('Pencarian diterapkan: ${model.filter.query}'),
            if (model.filter.batchId != null) ...[
              SelectableText('Batch ID diterapkan: ${model.filter.batchId}'),
              TextButton(
                onPressed: model.busy
                    ? null
                    : () => model.load(next: model.filter.allBatches()),
                child: const Text('Lihat semua batch'),
              ),
            ],
            if (model.busy) const LinearProgressIndicator(),
            if (model.error != null)
              ErrorPanel(message: model.error!, onRetry: () => model.load()),
            if (result != null) ...[
              Text(
                'Toko ${result.storeName} · Diamati ${formatWib(result.observedAt)}',
              ),
              if (result.rows.isEmpty)
                const Padding(
                  padding: EdgeInsets.all(16),
                  child: Text('Tidak ada pergerakan pada filter ini.'),
                ),
              for (final row in result.rows)
                StockMovementCard(
                  sku: row.sku,
                  name: row.name,
                  batch: row.batch,
                  packSize: row.packSize,
                  quantity: row.quantity,
                  bucket: stockBucketLabels[row.bucket] ?? row.bucket,
                  source: stockSourceLabels[row.source] ?? row.source,
                  sourceId: row.sourceId,
                  actor: row.actor,
                  time: formatWib(row.occurredAt),
                  id: row.id,
                  note: row.note,
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
