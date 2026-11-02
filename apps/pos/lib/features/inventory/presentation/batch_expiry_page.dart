import 'package:flutter/material.dart';
import '../../../design_system/formatters.dart';
import '../../../design_system/molecules/error_panel.dart';
import '../../../design_system/molecules/stock_batch_card.dart';
import '../application/batch_expiry_controller.dart';
import '../data/batch_expiry_repository.dart';
import '../domain/batch_expiry.dart';

class BatchExpirySearchPage extends StatefulWidget {
  const BatchExpirySearchPage({
    required this.repository,
    required this.store,
    super.key,
  });
  final BatchExpiryRepository repository;
  final String store;
  @override
  State<BatchExpirySearchPage> createState() => _BatchExpirySearchPageState();
}

class _BatchExpirySearchPageState extends State<BatchExpirySearchPage> {
  late final BatchExpiryController model;
  final from = TextEditingController(),
      to = TextEditingController(),
      query = TextEditingController();
  bool withBalance = false;
  String? formError;
  @override
  void initState() {
    super.initState();
    model = BatchExpiryController(widget.repository, widget.store);
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
      final next = BatchExpiryFilter.form(
        from.text,
        to.text,
        query: query.text,
        withBalance: withBalance,
      );
      setState(() => formError = null);
      model.load(next: next);
      FocusScope.of(context).unfocus();
    } catch (e) {
      setState(() => formError = 'Filter belum diterapkan: $e');
    }
  }

  Widget field(String label, TextEditingController controller, {int? limit}) =>
      Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: TextField(
          controller: controller,
          enabled: !model.busy,
          maxLength: limit,
          decoration: InputDecoration(labelText: label),
          keyboardType: limit == null
              ? TextInputType.datetime
              : TextInputType.text,
        ),
      );
  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) {
      final result = model.result, f = model.filter;
      return Scaffold(
        appBar: AppBar(
          title: const Text('Kedaluwarsa batch'),
          actions: [
            IconButton(
              tooltip: 'Muat ulang hasil batch',
              onPressed: model.busy ? null : () => model.load(),
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
        body: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 960),
            child: ListView(
              key: const Key('batch-expiry-scroll'),
              padding: const EdgeInsets.all(16),
              children: [
                const Text(
                  'Tanggal kedaluwarsa kemasan sesuai snapshot batch. Saldo dalam biji; karantina bukan stok layak jual. Masa simpan hasil kukus mengikuti SOP terpisah.',
                ),
                ExpansionTile(
                  title: const Text('Filter kedaluwarsa'),
                  children: [
                    field('Mulai tanggal kemasan (YYYY-MM-DD)', from),
                    field('Sampai tanggal kemasan (YYYY-MM-DD)', to),
                    const Text(
                      'Kedua batas opsional dan inklusif. Kosong berarti tanpa batas tanggal.',
                    ),
                    field('Cari SKU / nama / kode batch', query, limit: 100),
                    CheckboxListTile(
                      value: withBalance,
                      onChanged: model.busy
                          ? null
                          : (v) => setState(() => withBalance = v ?? false),
                      title: const Text(
                        'Ada saldo utuh / terbuka / siap / karantina',
                      ),
                    ),
                    FilledButton(
                      onPressed: model.busy ? null : apply,
                      child: const Text('Terapkan filter batch'),
                    ),
                  ],
                ),
                if (formError != null)
                  Semantics(
                    liveRegion: true,
                    child: Text(
                      formError!,
                      style: TextStyle(
                        color: Theme.of(context).colorScheme.error,
                      ),
                    ),
                  ),
                Text(
                  'Batas diterapkan: ${f.from.isEmpty ? 'Tanpa awal' : f.from} — ${f.to.isEmpty ? 'Tanpa akhir' : f.to} (inklusif)',
                ),
                Text(
                  'Saldo diterapkan: ${f.withBalance ? 'Ada utuh / terbuka / siap / karantina' : 'Termasuk saldo nol'}',
                ),
                if (f.query.isNotEmpty)
                  Text('Pencarian diterapkan: ${f.query}'),
                if (model.busy) const LinearProgressIndicator(),
                if (model.error != null)
                  ErrorPanel(
                    message: model.error!,
                    onRetry: () => model.load(),
                  ),
                if (result != null) ...[
                  Text(
                    'Toko ${result.storeName}${result.active ? '' : ' · Nonaktif'} · Diamati ${formatWib(result.observedAt)}',
                  ),
                  const Text(
                    'Urutan tanggal kemasan lalu ID. Saldo tiap halaman dibaca terpisah; bukan penetapan aman jual atau alokasi batch otomatis.',
                  ),
                  if (result.rows.isEmpty)
                    const Text('Tidak ada batch pada filter ini.'),
                  for (final b in result.rows)
                    StockBatchCard(
                      sku: b.sku,
                      name: b.name,
                      batch: b.code,
                      expiry: b.expiry,
                      condition: b.condition,
                      packSize: b.packSize,
                      usable: b.usable,
                      quarantined: b.quarantined,
                      opened: b.opened,
                      ready: b.ready,
                      waste: b.waste,
                      revision: b.revision,
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
                      Text(
                        'Halaman ${result.page} / ${result.lastPage} · ${result.total} batch',
                      ),
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
          ),
        ),
      );
    },
  );
}
