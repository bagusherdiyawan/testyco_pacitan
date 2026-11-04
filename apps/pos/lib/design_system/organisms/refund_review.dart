import 'package:flutter/material.dart';

class RefundReview extends StatelessWidget {
  const RefundReview({required this.review, super.key});
  final Map<String, dynamic> review;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Refund Rp ${review['amount']}',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          Text(
            'Metode asal ${review['payment_method']} · ${review['reference']}',
          ),
          Text(
            review['stock_disposition'] == 'quarantine'
                ? 'Barang kembali ke karantina batch historis, belum layak jual.'
                : 'Tanpa pengembalian stok.',
          ),
          Text('${review['reason']}'),
          for (final l in review['lines']) ...[
            Text(
              '${l['sku']} · ${l['quantity']} ${l['unit']} · Rp ${l['amount']} setelah diskon historis',
            ),
            for (final a in l['allocations'])
              Text(
                'Batch ${a['batch_code']} · ${a['pieces']} biji → karantina',
              ),
          ],
          const Text(
            'Verifikasi pengembalian uang melalui metode asal. Pencatatan ini tidak mengirim uang melalui provider.',
          ),
        ],
      ),
    ),
  );
}
