import 'package:flutter/material.dart';
import '../formatters.dart';

class ShiftCashSummary extends StatelessWidget {
  const ShiftCashSummary({
    required this.opening,
    required this.sales,
    required this.cashIn,
    required this.cashOut,
    required this.expected,
    super.key,
  });
  final int opening, sales, cashIn, cashOut, expected;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Kas awal ${formatRupiah(opening)}'),
          Text('Penjualan tunai ${formatRupiah(sales)}'),
          Text('Kas masuk ${formatRupiah(cashIn)}'),
          Text('Kas keluar ${formatRupiah(cashOut)}'),
          Text(
            'Kas diharapkan ${formatRupiah(expected)}',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const Text(
            'Kas masuk/keluar bukan omzet. Saldo ini bukan bukti hitungan fisik.',
          ),
        ],
      ),
    ),
  );
}
