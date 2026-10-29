import 'package:flutter/material.dart';

class MinimumChangeReview extends StatelessWidget {
  const MinimumChangeReview({
    required this.sku,
    required this.previous,
    required this.next,
    required this.version,
    required this.reason,
    super.key,
  });
  final String sku, reason;
  final int? previous, next;
  final int version;
  String describe(int? value) =>
      value == null ? 'Tidak dipantau' : '$value biji';
  @override
  Widget build(BuildContext context) => Column(
    mainAxisSize: MainAxisSize.min,
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(sku),
      Text('Sebelum: ${describe(previous)}'),
      Text('Sesudah: ${describe(next)}'),
      Text('Versi konfigurasi $version'),
      Text('Alasan: $reason'),
      const Text(
        'Ambang 0 tetap aktif. Menonaktifkan mempertahankan riwayat. Tidak membuat pesanan atau mengubah stok.',
      ),
    ],
  );
}
