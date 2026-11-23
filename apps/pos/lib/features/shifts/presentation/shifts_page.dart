import 'package:flutter/material.dart';
import '../../../design_system/formatters.dart';
import '../../../design_system/molecules/error_panel.dart';
import '../data/shifts_repository.dart';
import '../domain/cash_shift.dart';
import 'shift_detail_page.dart';

class ShiftsPage extends StatefulWidget {
  const ShiftsPage({
    required this.repository,
    required this.storeId,
    super.key,
  });
  final ShiftsRepository repository;
  final String storeId;
  @override
  State<ShiftsPage> createState() => _ShiftsPageState();
}

class _ShiftsPageState extends State<ShiftsPage> {
  late Future<ShiftPage> _result;
  int _page = 1;
  @override
  void initState() {
    super.initState();
    _result = widget.repository.list(widget.storeId);
  }

  void load([int? page]) {
    setState(() {
      _page = page ?? _page;
      _result = widget.repository.list(widget.storeId, page: _page);
    });
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<ShiftPage>(
    future: _result,
    builder: (context, snapshot) {
      if (snapshot.connectionState != ConnectionState.done) {
        return const Center(child: CircularProgressIndicator());
      }
      if (snapshot.hasError) {
        return ErrorPanel(message: '${snapshot.error}', onRetry: load);
      }
      final result = snapshot.data!;
      return ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Shift dan kas toko',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
              ),
              IconButton(
                tooltip: 'Muat ulang daftar shift',
                onPressed: load,
                icon: const Icon(Icons.refresh),
              ),
            ],
          ),
          const Text(
            'Pilih shift untuk memeriksa riwayat dan mencatat kas masuk/keluar sesuai izin. Buka/tutup shift tetap melalui POS.',
          ),
          if (result.rows.isEmpty) const Text('Belum ada shift.'),
          for (final s in result.rows)
            Card(
              child: ListTile(
                title: Text('${s.cashier} · ${s.device}'),
                subtitle: Text(
                  '${s.status} · ${s.openedAt}\n${s.expected != null && !s.blindClose ? 'Kas diharapkan ${formatRupiah(s.expected!)}' : 'Mode hitung buta aktif'}',
                ),
                trailing: const Icon(Icons.chevron_right),
                onTap: () async {
                  await Navigator.of(context).push<void>(
                    MaterialPageRoute(
                      builder: (_) => ShiftDetailPage(
                        repository: widget.repository,
                        storeId: widget.storeId,
                        id: s.id,
                      ),
                    ),
                  );
                  if (mounted) load();
                },
              ),
            ),
          Wrap(
            spacing: 12,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              TextButton(
                onPressed: result.page > 1 ? () => load(result.page - 1) : null,
                child: const Text('Sebelumnya'),
              ),
              Text('Halaman ${result.page} / ${result.lastPage}'),
              TextButton(
                onPressed: result.page < result.lastPage
                    ? () => load(result.page + 1)
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
