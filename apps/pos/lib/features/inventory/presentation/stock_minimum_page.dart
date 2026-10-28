import 'package:flutter/material.dart';
import '../data/inventory_repository.dart';
import '../domain/stock_minimum.dart';
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
  late Future<List<StockMinimum>> _rows;
  bool _lowOnly = true;
  @override
  void initState() {
    super.initState();
    _rows = widget.repository.minimums(widget.storeId, lowOnly: _lowOnly);
  }

  void _reload() => setState(() {
    _rows = widget.repository.minimums(widget.storeId, lowOnly: _lowOnly);
  });
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
    body: Column(
      children: [
        const Padding(
          padding: EdgeInsets.all(16),
          child: Text(
            'Saldo saat halaman dimuat. Utuh + terbuka + siap; karantina/susut tidak dihitung. Bukan jaminan mutu atau jumlah siap saji. Pengelola mengatur ambang melalui web; tidak membuat pesanan otomatis.',
          ),
        ),
        SwitchListTile(
          title: const Text('Hanya stok rendah'),
          value: _lowOnly,
          onChanged: (value) {
            _lowOnly = value;
            _reload();
          },
        ),
        Expanded(
          child: FutureBuilder<List<StockMinimum>>(
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
              final rows = snapshot.data!;
              if (rows.isEmpty) {
                return Center(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Text(
                      _lowOnly
                          ? 'Tidak ada stok di bawah ambang aktif. SKU tanpa ambang belum dipantau.'
                          : 'Belum ada produk aktif.',
                    ),
                  ),
                );
              }
              return ListView.builder(
                padding: const EdgeInsets.all(16),
                itemCount: rows.length,
                itemBuilder: (context, i) {
                  final r = rows[i];
                  return StockMinimumCard(
                    sku: r.sku,
                    name: r.name,
                    available: r.available,
                    usable: r.usable,
                    opened: r.opened,
                    ready: r.ready,
                    minimum: r.minimum,
                    shortfall: r.shortfall,
                    isLow: r.isLow,
                  );
                },
              );
            },
          ),
        ),
      ],
    ),
  );
}
