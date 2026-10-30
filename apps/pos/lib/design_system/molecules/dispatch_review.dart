import 'package:flutter/material.dart';

class DispatchReview extends StatelessWidget {
  const DispatchReview({required this.review, super.key});
  final Map<String, dynamic> review;
  @override
  Widget build(BuildContext context) => Column(
    mainAxisSize: MainAxisSize.min,
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text('Order ${review['order']}'),
      Text('Gudang: ${review['source']}'),
      Text('Tujuan: ${review['destination']}'),
      Text('Metode: ${review['mode']} · versi ${review['version']}'),
      if (review['handler'] != null) Text('Petugas: ${review['handler']}'),
      if (review['note'] != null && review['note'] != '')
        Text('Catatan: ${review['note']}'),
      for (final row in (review['lines'] as List?) ?? [])
        Text(
          '${row['sku']} · ${row['batch']} · expiry ${row['expiry']}\n${row['packs']} kemasan × ${row['size']} biji',
        ),
      Text(
        review['lines'] == null
            ? 'Pilihan gudang tidak mereservasi stok.'
            : 'Konfirmasi setelah batch/jumlah fisik diperiksa. Stok gudang berkurang saat tersimpan; stok tujuan bertambah setelah penerimaan aktual.',
      ),
    ],
  );
}
