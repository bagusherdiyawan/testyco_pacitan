import 'package:flutter/material.dart';
import '../data/inventory_repository.dart';
import '../domain/stock_minimum.dart';
import 'stock_minimum_editor.dart';
import '../../../design_system/molecules/error_panel.dart';
import '../../../design_system/molecules/stock_minimum_card.dart';

class StockMinimumPage extends StatefulWidget {
  const StockMinimumPage({
    required this.repository,
    required this.storeId,
    super.key,
  });
  final InventoryRepository repository;
  final String storeId;
  @override
  State<StockMinimumPage> createState() => _StockMinimumPageState();
}

class _StockMinimumPageState extends State<StockMinimumPage> {
  late Future<StockMinimumReport> _rows;
  bool _lowOnly = true;
  int _page = 1;
  String _query = '';
  final search = TextEditingController();
  @override
  void initState() {
    super.initState();
    _rows = _fetch();
  }

  Future<StockMinimumReport> _fetch() => widget.repository.minimumPage(
    widget.storeId,
    lowOnly: _lowOnly,
    page: _page,
    query: _query,
  );
  void _reload() => setState(() {
    _rows = _fetch();
  });
  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  Widget card(StockMinimum r, StockMinimumReport report) => StockMinimumCard(
    sku: r.sku,
    name: r.name,
    available: r.available,
    usable: r.usable,
    opened: r.opened,
    ready: r.ready,
    minimum: r.minimum,
    shortfall: r.shortfall,
    isLow: r.isLow,
    action:
        report.canManage &&
            report.storeId == widget.storeId &&
            r.productId.isNotEmpty &&
            r.version != null &&
            r.version! >= 0
        ? TextButton.icon(
            icon: const Icon(Icons.tune),
            label: const Text('Atur ambang'),
            onPressed: () async {
              await Navigator.of(context).push<void>(
                MaterialPageRoute(
                  builder: (_) => StockMinimumEditor(
                    repository: widget.repository,
                    storeId: widget.storeId,
                    productId: r.productId,
                  ),
                ),
              );
              if (mounted) {
                _reload();
              }
            },
          )
        : null,
  );
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const Text('Stok minimum'),
      actions: [
        IconButton(
          tooltip: 'Muat ulang peringatan',
          onPressed: _reload,
          icon: const Icon(Icons.refresh),
        ),
      ],
    ),
    body: ListView(
      key: const Key('minimum-list-scroll'),
      padding: const EdgeInsets.all(16),
      children: [
        const Text(
          'Saldo saat halaman dimuat: utuh + terbuka + siap, tanpa karantina/susut. Bukan jaminan mutu atau jumlah siap saji. Pengelola berizin dapat mengatur ambang dari kartu SKU; tidak membuat pesanan otomatis.',
        ),
        SwitchListTile(
          title: const Text('Hanya stok rendah'),
          value: _lowOnly,
          onChanged: (v) {
            _lowOnly = v;
            _page = 1;
            _reload();
          },
        ),
        TextField(
          controller: search,
          maxLength: 100,
          decoration: const InputDecoration(
            labelText: 'Cari SKU atau nama',
            suffixIcon: Icon(Icons.search),
          ),
          onSubmitted: (v) {
            _query = v;
            _page = 1;
            _reload();
          },
        ),
        FutureBuilder<StockMinimumReport>(
          future: _rows,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return ErrorPanel(
                message: snapshot.error.toString(),
                onRetry: _reload,
              );
            }
            final report = snapshot.data!;
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (report.rows.isEmpty)
                  Padding(
                    padding: const EdgeInsets.all(16),
                    child: Text(
                      _query.isNotEmpty
                          ? 'Tidak ada SKU sesuai pencarian.'
                          : _lowOnly
                          ? 'Tidak ada stok di bawah ambang aktif. SKU tanpa ambang belum dipantau.'
                          : 'Belum ada produk aktif.',
                    ),
                  ),
                for (final r in report.rows) card(r, report),
                if (report.observedAt.isNotEmpty)
                  Text('Diamati ${report.observedAt}'),
                Wrap(
                  spacing: 8,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    TextButton(
                      onPressed: report.page > 1
                          ? () {
                              _page = report.page - 1;
                              _reload();
                            }
                          : null,
                      child: const Text('Sebelumnya'),
                    ),
                    Text('Halaman ${report.page} / ${report.lastPage}'),
                    TextButton(
                      onPressed: report.page < report.lastPage
                          ? () {
                              _page = report.page + 1;
                              _reload();
                            }
                          : null,
                      child: const Text('Berikutnya'),
                    ),
                  ],
                ),
              ],
            );
          },
        ),
      ],
    ),
  );
}
