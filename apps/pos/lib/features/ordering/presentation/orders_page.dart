import 'package:flutter/material.dart';
import '../data/orders_repository.dart';
import 'order_detail_page.dart';
import 'order_entry_page.dart';
import '../data/order_entry_repository.dart';
import '../../../design_system/molecules/error_panel.dart';
export 'order_detail_page.dart';

class OrdersPage extends StatefulWidget {
  const OrdersPage({
    required this.repository,
    required this.storeId,
    super.key,
  });
  final OrdersRepository repository;
  final String storeId;
  @override
  State<OrdersPage> createState() => _OrdersPageState();
}

class _OrdersPageState extends State<OrdersPage> {
  late Future<List<Map<String, dynamic>>> _orders;
  @override
  void initState() {
    super.initState();
    _orders = widget.repository.load(widget.storeId);
  }

  void _reload() {
    setState(() {
      _orders = widget.repository.load(widget.storeId);
    });
  }

  Future<void> _create() async {
    final id = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) => OrderEntryPage(
          repository: OrderEntryRepository(widget.repository.api),
          storeId: widget.storeId,
        ),
      ),
    );
    if (!mounted) {
      return;
    }
    _reload();
    if (id != null) {
      await Navigator.of(context).push<void>(
        MaterialPageRoute(
          builder: (_) =>
              OrderDetailPage(repository: widget.repository, id: id),
        ),
      );
      if (mounted) {
        _reload();
      }
    }
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
                'Pesanan toko',
                style: Theme.of(context).textTheme.titleLarge,
              ),
            ),
            IconButton(
              tooltip: 'Muat ulang pesanan',
              onPressed: _reload,
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
      ),
      Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          onPressed: _create,
          icon: const Icon(Icons.add_shopping_cart),
          label: const Text('Buat pengajuan'),
        ),
      ),
      Expanded(
        child: FutureBuilder<List<Map<String, dynamic>>>(
          future: _orders,
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
            final orders = snapshot.data!;
            if (orders.isEmpty) {
              return ErrorPanel(
                message: 'Belum ada pesanan. Gunakan tombol Buat pengajuan.',
                onRetry: _reload,
              );
            }
            return ListView(
              padding: const EdgeInsets.all(16),
              children: [
                for (final o in orders)
                  Card(
                    child: ListTile(
                      title: Text(o['id'] as String),
                      subtitle: Text(
                        '${o['status']} · Rp ${o['total_amount']}',
                      ),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () async {
                        await Navigator.of(context).push(
                          MaterialPageRoute<void>(
                            builder: (_) => OrderDetailPage(
                              repository: widget.repository,
                              id: o['id'] as String,
                            ),
                          ),
                        );
                        if (mounted) {
                          _reload();
                        }
                      },
                    ),
                  ),
              ],
            );
          },
        ),
      ),
    ],
  );
}
