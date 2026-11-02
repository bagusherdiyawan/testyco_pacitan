import 'package:flutter/material.dart';
import '../formatters.dart';
import '../molecules/order_change_line.dart';

class OrderChangeReview extends StatelessWidget {
  const OrderChangeReview({required this.review, super.key});
  final Map<String, dynamic> review;
  @override
  Widget build(BuildContext context) => Column(
    mainAxisSize: MainAxisSize.min,
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text('${review['destination']} · Versi ${review['version']}'),
      if (review['kind'] == 'internal_transfer')
        const Text('Transfer internal, nilai komersial nol.'),
      if (review['proposal_reason'] != null)
        Text('Alasan pemasok: ${review['proposal_reason']}'),
      Text('Catatan: ${review['reason']}'),
      for (final line in (review['lines'] as List).cast<Map<String, dynamic>>())
        OrderChangeLine(
          sku: line['sku'] as String,
          localSku: line['local_sku'] as String,
          before: line['before'] as int,
          after: line['after'] as int,
          size: line['size'] as int,
          price: line['price'] as int,
        ),
      Text('Total semula ${formatRupiah(review['before_total'] as int)}'),
      Text('Total usulan ${formatRupiah(review['after_total'] as int)}'),
      const Text(
        'Usulan belum mengubah jumlah pesanan sampai diterima. Tolak/tarik mempertahankan pesanan asli. Tidak mencatat stok atau pembayaran.',
      ),
    ],
  );
}
