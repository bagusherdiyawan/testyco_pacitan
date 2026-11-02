import 'package:flutter/material.dart';
import '../data/inventory_repository.dart';
import '../data/batch_expiry_repository.dart';
import 'batch_expiry_page.dart';
import '../domain/stock_batch.dart';
import 'stock_minimum_page.dart';
import 'stock_ledger_page.dart';
import '../data/stock_ledger_repository.dart';
import 'warehouse_intake_page.dart';
import '../data/warehouse_intake_repository.dart';
import 'stock_control_page.dart';
import '../data/stock_control_repository.dart';
import '../../../design_system/molecules/error_panel.dart';
import '../../../design_system/molecules/stock_batch_card.dart';

class InventoryPage extends StatefulWidget {
  const InventoryPage({
    required this.repository,
    required this.storeId,
    this.canControl = false,
    this.canIntake = false,
    super.key,
  });
  final InventoryRepository repository;
  final String storeId;
  final bool canControl, canIntake;
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
      TextButton.icon(
        icon: const Icon(Icons.notifications_outlined),
        label: const Text('Stok minimum dan peringatan'),
        onPressed: () => Navigator.of(context).push(
          MaterialPageRoute<void>(
            builder: (_) => StockMinimumPage(
              repository: widget.repository,
              storeId: widget.storeId,
            ),
          ),
        ),
      ),
      TextButton.icon(
        icon: const Icon(Icons.event_outlined),
        label: const Text('Telusuri kedaluwarsa batch'),
        onPressed: () => Navigator.of(context).push<void>(
          MaterialPageRoute(
            builder: (_) => BatchExpirySearchPage(
              repository: BatchExpiryRepository(widget.repository.api),
              store: widget.storeId,
            ),
          ),
        ),
      ),
      if (widget.canControl)
        TextButton.icon(
          icon: const Icon(Icons.history),
          label: const Text('Riwayat pergerakan stok'),
          onPressed: () => Navigator.of(context).push<void>(
            MaterialPageRoute(
              builder: (_) => StockLedgerHistoryPage(
                repository: StockLedgerRepository(widget.repository.api),
                store: widget.storeId,
              ),
            ),
          ),
        ),
      if (widget.canIntake)
        TextButton.icon(
          icon: const Icon(Icons.add_box_outlined),
          label: const Text('Penerimaan awal gudang'),
          onPressed: () async {
            await Navigator.of(context).push<bool>(
              MaterialPageRoute(
                builder: (_) => WarehouseIntakePage(
                  repository: WarehouseIntakeRepository(widget.repository.api),
                  store: widget.storeId,
                ),
              ),
            );
            if (mounted) {
              _reload();
            }
          },
        ),
      if (widget.canControl)
        TextButton.icon(
          icon: const Icon(Icons.edit_note),
          label: const Text('Pengolahan dan opname'),
          onPressed: () async {
            await Navigator.of(context).push(
              MaterialPageRoute<void>(
                builder: (_) => StockControlPage(
                  repository: StockControlRepository(widget.repository.api),
                  storeId: widget.storeId,
                ),
              ),
            );
            if (mounted) {
              _reload();
            }
          },
        ),
      const Padding(
        padding: EdgeInsets.symmetric(horizontal: 16),
        child: Text(
          'Pengolahan dan opname tersedia bagi petugas berwenang melalui tombol kontrol stok atau web. Masa simpan siap jual mengikuti SOP, bukan tanggal kedaluwarsa kemasan.',
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
                    opened: b.opened,
                    ready: b.ready,
                    waste: b.waste,
                    revision: b.revision,
                    action: widget.canControl && b.id.isNotEmpty
                        ? TextButton(
                            onPressed: () => Navigator.of(context).push<void>(
                              MaterialPageRoute(
                                builder: (_) => StockLedgerHistoryPage(
                                  repository: StockLedgerRepository(
                                    widget.repository.api,
                                  ),
                                  store: widget.storeId,
                                  batchId: b.id,
                                ),
                              ),
                            ),
                            child: const Text('Riwayat batch ini'),
                          )
                        : null,
                  ),
              ],
            );
          },
        ),
      ),
    ],
  );
}
