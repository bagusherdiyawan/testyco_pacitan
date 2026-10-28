import 'package:flutter/material.dart';
import '../data/orders_repository.dart';
import '../../../design_system/molecules/error_panel.dart';

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
                message:
                    'Belum ada pesanan. Buat pengajuan melalui web operasional.',
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
                      onTap: () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => OrderDetailPage(
                            repository: widget.repository,
                            id: o['id'] as String,
                          ),
                        ),
                      ),
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

class OrderDetailPage extends StatefulWidget {
  const OrderDetailPage({
    required this.repository,
    required this.id,
    super.key,
  });
  final OrdersRepository repository;
  final String id;
  @override
  State<OrderDetailPage> createState() => _OrderDetailPageState();
}

class _OrderDetailPageState extends State<OrderDetailPage> {
  late Future<List<dynamic>> _detail;
  @override
  void initState() {
    super.initState();
    _detail = _load();
  }

  Future<List<dynamic>> _load() => Future.wait<dynamic>([
    widget.repository.details(widget.id),
    widget.repository.shipments(widget.id),
  ]);
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Detail pesanan')),
    body: FutureBuilder<List<dynamic>>(
      future: _detail,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return ErrorPanel(
            message: snapshot.error.toString(),
            onRetry: () {
              setState(() {
                _detail = _load();
              });
            },
          );
        }
        final o = snapshot.data![0] as Map<String, dynamic>;
        final shipments = snapshot.data![1] as List<Map<String, dynamic>>;
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              o['destination_name'] as String,
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            Text('${o['status']} · Versi ${o['version']}'),
            Text('Total: Rp ${o['total_amount']}'),
            for (final line in o['lines'] as List)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(
                    '${line['sku']} → ${line['local_sku']}\n${line['quantity_packs']} kemasan × ${line['pack_size']} biji · Rp ${line['unit_price']}/kemasan',
                  ),
                ),
              ),
            const Text('Pengiriman'),
            for (final s in shipments)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        s['received_at'] == null
                            ? 'Menunggu penerimaan'
                            : 'Penerimaan dikonfirmasi',
                      ),
                      for (final l in s['lines'] as List)
                        Text(
                          'Batch ${l['batch_code']} · dikirim ${l['quantity_packs']} · baik ${l['good_packs'] ?? '-'} · rusak ${l['damaged_packs'] ?? '-'}',
                        ),
                    ],
                  ),
                ),
              ),
            const Text(
              'Persetujuan, pengiriman dan penerimaan dilakukan melalui web operasional pada tahap ini.',
            ),
          ],
        );
      },
    ),
  );
}
