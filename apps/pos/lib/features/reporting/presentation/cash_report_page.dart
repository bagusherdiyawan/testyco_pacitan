import 'package:flutter/material.dart';
import '../domain/cash_report.dart';
import '../data/cash_report_repository.dart';
import '../../../design_system/organisms/cash_sales_metrics.dart';
import '../../../design_system/molecules/error_panel.dart';

class CashReportPage extends StatefulWidget {
  const CashReportPage({
    required this.repository,
    required this.organizationId,
    required this.storeId,
    this.initialPeriod,
    super.key,
  });
  final CashReportRepository repository;
  final String organizationId, storeId;
  final CashReportPeriod? initialPeriod;
  @override
  State<CashReportPage> createState() => _CashReportPageState();
}

class _CashReportPageState extends State<CashReportPage> {
  late CashReportPeriod period;
  late String store;
  int page = 1;
  late Future<CashReport> report;
  @override
  void initState() {
    super.initState();
    final now = DateTime.now().toUtc().add(const Duration(hours: 7));
    final day = DateTime.utc(now.year, now.month, now.day);
    period = widget.initialPeriod ?? CashReportPeriod(day, day);
    store = widget.storeId;
    report = fetch();
  }

  Future<CashReport> fetch() => widget.repository.load(
    widget.organizationId,
    period,
    store: store,
    page: page,
  );
  void reload() => setState(() {
    report = fetch();
  });
  Future<void> choosePeriod() async {
    final result = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2000),
      lastDate: DateTime(DateTime.now().year + 1, 12, 31),
      initialDateRange: DateTimeRange(start: period.from, end: period.to),
    );
    if (result == null || !mounted) {
      return;
    }
    final from = DateTime.utc(
      result.start.year,
      result.start.month,
      result.start.day,
    );
    final to = DateTime.utc(result.end.year, result.end.month, result.end.day);
    if (to.difference(from).inDays >= 31) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Periode maksimal 31 hari.')),
      );
      return;
    }
    period = CashReportPeriod(from, to);
    page = 1;
    reload();
  }

  @override
  Widget build(BuildContext context) => Column(
    children: [
      Padding(
        padding: const EdgeInsets.all(16),
        child: Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(
              'Laporan penjualan tunai',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            OutlinedButton(onPressed: choosePeriod, child: Text(period.label)),
            IconButton(
              tooltip: 'Muat ulang laporan',
              onPressed: reload,
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
      ),
      Expanded(
        child: FutureBuilder<CashReport>(
          future: report,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return ErrorPanel(
                message: snapshot.error.toString(),
                onRetry: reload,
              );
            }
            final data = snapshot.data!;
            return ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Text(data.name, style: Theme.of(context).textTheme.titleLarge),
                DropdownButtonFormField<String>(
                  key: ValueKey(store),
                  initialValue: store,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Toko dalam cakupan',
                  ),
                  items: [
                    const DropdownMenuItem(
                      value: '',
                      child: Text('Semua toko berizin'),
                    ),
                    for (final s in data.stores)
                      DropdownMenuItem(
                        value: s.id,
                        child: Text(
                          '${s.name}${s.active ? '' : ' (nonaktif)'}',
                        ),
                      ),
                  ],
                  onChanged: (value) {
                    store = value!;
                    page = 1;
                    reload();
                  },
                ),
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 12),
                  child: Text(
                    'Transaksi POS tunai selesai yang tersimpan di server. Kas masuk/keluar, grosir dan transfer internal tidak termasuk. Refund/non-tunai belum tersedia. Tanggal kalender WIB, bukan penutupan hari bisnis.',
                  ),
                ),
                Text('Ringkasan dimuat: ${_wib(data.observedAt)}'),
                CashSalesMetrics(
                  count: data.metrics.count,
                  gross: data.metrics.gross,
                  discount: data.metrics.discount,
                  net: data.metrics.net,
                ),
                Text(
                  'Perbandingan toko',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                if (data.byStore.isEmpty)
                  const Text('Belum ada toko dalam cakupan.'),
                for (final s in data.byStore)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('${s.name}${s.active ? '' : ' (nonaktif)'}'),
                          Text(
                            '${s.metrics.count} transaksi · Bersih ${reportRupiah(s.metrics.net)}',
                          ),
                        ],
                      ),
                    ),
                  ),
                Text(
                  'Produk terjual',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const Text(
                  'Konversi dan nama historis. Nilai produk adalah kotor; jumlah transaksi per produk tidak dijumlahkan menjadi jumlah struk.',
                ),
                Text('Produk dimuat: ${_wib(data.productObservedAt)}'),
                if (data.products.isEmpty)
                  const Text('Belum ada produk terjual dalam filter ini.'),
                for (final p in data.products)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('${p.sku} · ${p.name}'),
                          Text(
                            '${p.condition} · ${p.pieces} biji · ${p.count} transaksi',
                          ),
                          Text('Kotor ${reportRupiah(p.gross)}'),
                        ],
                      ),
                    ),
                  ),
                Wrap(
                  spacing: 8,
                  children: [
                    Text('Halaman ${data.page} / ${data.lastPage}'),
                    if (data.page > 1)
                      OutlinedButton(
                        onPressed: () {
                          page = data.page - 1;
                          reload();
                        },
                        child: const Text('Produk sebelumnya'),
                      ),
                    if (data.page < data.lastPage)
                      OutlinedButton(
                        onPressed: () {
                          page = data.page + 1;
                          reload();
                        },
                        child: const Text('Produk berikutnya'),
                      ),
                  ],
                ),
                const Text(
                  'Ekspor CSV tersedia melalui web bagi pengguna berwenang. Setiap permintaan membaca saldo laporan saat itu; muat ulang untuk pembaruan.',
                ),
              ],
            );
          },
        ),
      ),
    ],
  );
  String _wib(String raw) {
    final d = DateTime.parse(raw).toUtc().add(const Duration(hours: 7));
    return '${CashReportPeriod.day(d)} ${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')} WIB';
  }
}
