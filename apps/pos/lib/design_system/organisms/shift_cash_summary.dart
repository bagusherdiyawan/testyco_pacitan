import 'package:flutter/material.dart';
import '../formatters.dart';

class ShiftCashSummary extends StatelessWidget {
  const ShiftCashSummary({
    required this.opening,
    required this.sales,
    required this.cashIn,
    required this.cashOut,
    required this.expected,
    this.blindClose = false,
    super.key,
  });
  final int opening;
  final int? sales, cashIn, cashOut, expected;
  final bool blindClose;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Kas awal ${formatRupiah(opening)}'),
          if (blindClose || expected == null) ...[
            const Text(
              'Mode Hitung Buta (Blind Close) aktif. Saldo sistem disembunyikan sampai shift ditutup.',
            ),
          ] else ...[
            Text('Penjualan tunai ${formatRupiah(sales ?? 0)}'),
            Text('Kas masuk ${formatRupiah(cashIn ?? 0)}'),
            Text('Kas keluar ${formatRupiah(cashOut ?? 0)}'),
            Text(
              'Kas diharapkan ${formatRupiah(expected!)}',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const Text(
              'Kas masuk/keluar bukan omzet. Saldo ini bukan bukti hitungan fisik.',
            ),
          ],
        ],
      ),
    ),
  );
}
