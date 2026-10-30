import 'package:flutter/material.dart';
import '../../../design_system/molecules/error_panel.dart';
import '../data/warehouse_repository.dart';
import '../domain/warehouse_context.dart';
import 'warehouse_order_page.dart';

class WarehouseInboxPage extends StatefulWidget {
  const WarehouseInboxPage({
    required this.repository,
    required this.store,
    super.key,
  });
  final WarehouseRepository repository;
  final String store;
  @override
  State<WarehouseInboxPage> createState() => _WarehouseInboxPageState();
}

class _WarehouseInboxPageState extends State<WarehouseInboxPage> {
  late Future<WarehouseInbox> result;
  int page = 1;
  @override
  void initState() {
    super.initState();
    result = widget.repository.inbox(widget.store);
  }

  void load([int? next]) {
    setState(() {
      page = next ?? page;
      result = widget.repository.inbox(widget.store, page: page);
    });
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<WarehouseInbox>(
    future: result,
    builder: (context, snapshot) {
      if (snapshot.connectionState != ConnectionState.done) {
        return const Center(child: CircularProgressIndicator());
      }
      if (snapshot.hasError) {
        return ErrorPanel(message: '${snapshot.error}', onRetry: load);
      }
      final inbox = snapshot.data!;
      return ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Gudang ${inbox.source['name']}',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
              ),
              IconButton(
                tooltip: 'Muat ulang inbox gudang',
                onPressed: load,
                icon: const Icon(Icons.refresh),
              ),
            ],
          ),
          const Text(
            'Untuk memilih sumber lain, ganti toko pada navigasi utama. Pengajuan belum disetujui tidak tampil. Petugas gudang menunggu sumber ditetapkan owner/admin.',
          ),
          if (inbox.rows.isEmpty)
            const Text('Belum ada pesanan untuk gudang ini.'),
          for (final row in inbox.rows)
            Card(
              child: ListTile(
                title: Text('${row['destination_name']}'),
                subtitle: Text('${row['id']} · ${row['status']}'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () async {
                  await Navigator.of(context).push<void>(
                    MaterialPageRoute(
                      builder: (_) => WarehouseOrderPage(
                        repository: widget.repository,
                        store: widget.store,
                        order: row['id'] as String,
                      ),
                    ),
                  );
                  if (mounted) {
                    load();
                  }
                },
              ),
            ),
          Wrap(
            spacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              TextButton(
                onPressed: inbox.page > 1 ? () => load(inbox.page - 1) : null,
                child: const Text('Sebelumnya'),
              ),
              Text('Halaman ${inbox.page} / ${inbox.lastPage}'),
              TextButton(
                onPressed: inbox.page < inbox.lastPage
                    ? () => load(inbox.page + 1)
                    : null,
                child: const Text('Berikutnya'),
              ),
            ],
          ),
        ],
      );
    },
  );
}
