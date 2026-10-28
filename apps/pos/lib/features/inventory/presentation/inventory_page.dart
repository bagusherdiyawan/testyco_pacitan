import 'package:flutter/material.dart';
import '../data/inventory_repository.dart';
import '../domain/stock_batch.dart';
import '../../../design_system/molecules/error_panel.dart';
import '../../../design_system/molecules/stock_batch_card.dart';

class InventoryPage extends StatefulWidget {
  const InventoryPage({
    required this.repository,
    required this.storeId,
    super.key,
  });
  final InventoryRepository repository;
  final String storeId;
  @override
  State<InventoryPage> createState() => _InventoryPageState();
}

class _InventoryPageState extends State<InventoryPage> {
  late Future<List<StockBatch>> _batches;
  @override
  void initState() {
    super.initState();
    _batches = widget.repository.load(widget.storeId);
  }

  void _reload() {
    setState(() {
      _batches = widget.repository.load(widget.storeId);
    });
  }

  @override
  Widget build(BuildContext context) => Column(
    children: [
      Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Expanded(
              child: Text(
                'Stok dan batch',
                style: Theme.of(context).textTheme.titleLarge,
              ),
            ),
            IconButton(
              tooltip: 'Muat ulang stok',
              onPressed: _reload,
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
      ),
      Expanded(
        child: FutureBuilder<List<StockBatch>>(
          future: _batches,
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
              return ErrorPanel(
                message: 'Belum ada penerimaan stok.',
                onRetry: _reload,
              );
            }
            return ListView(
              padding: const EdgeInsets.all(16),
              children: [
                for (final b in rows)
                  StockBatchCard(
                    sku: b.sku,
                    name: b.name,
                    batch: b.code,
                    expiry: b.expiry,
                    packSize: b.packSize,
                    usable: b.usable,
                    quarantined: b.quarantined,
                  ),
              ],
            );
          },
        ),
      ),
    ],
  );
}
