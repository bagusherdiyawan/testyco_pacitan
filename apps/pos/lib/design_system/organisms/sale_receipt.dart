import 'package:flutter/material.dart';

class SaleReceipt extends StatelessWidget {
  const SaleReceipt({required this.sale, super.key});
  final Map<String, dynamic> sale;
  String wib(String value) {
    final date = DateTime.tryParse(value);
    if (date == null) {
      return 'Waktu tidak tersedia';
    }
    final local = date.toUtc().add(const Duration(hours: 7));
    String pad(int x) => x.toString().padLeft(2, '0');
    return '${pad(local.day)}/${pad(local.month)}/${local.year} ${pad(local.hour)}:${pad(local.minute)} WIB';
  }

  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            sale['store_name'] as String,
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          Text(sale['number'] as String),
          Text(
            'Kasir ${sale['cashier_name']} · ${wib(sale['sold_at'] as String)}',
          ),
          for (final line in sale['lines'] as List)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: Text(
                '${line['sku']} · ${line['name']}\n${line['quantity']} ${line['unit']} × Rp ${line['unit_price']} = Rp ${line['line_total']}',
              ),
            ),
          const Divider(),
          Text(
            'Total Rp ${sale['total_amount']}',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          Text(
            'Diskon Rp ${sale['discount_amount'] ?? 0} · ${sale['payment_method'] ?? 'cash'}',
          ),
          if ((sale['payment_method'] ?? 'cash') == 'cash') ...[
            Text('Tunai Rp ${sale['cash_received']}'),
            Text('Kembalian Rp ${sale['change_amount']}'),
          ] else
            Text('Referensi terverifikasi ${sale['payment_reference'] ?? ''}'),
          for (final r in sale['refunds'] ?? [])
            Text(
              'Refund Rp ${r['amount']} · ${r['payment_method']} · ${r['reference']}',
            ),
          const Text('Struk digital · pencetakan perangkat belum terintegrasi'),
        ],
      ),
    ),
  );
}
