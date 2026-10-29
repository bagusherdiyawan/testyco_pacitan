import 'package:flutter/material.dart';
import '../formatters.dart';

class CashMovementReview extends StatelessWidget {
  const CashMovementReview({
    required this.kind,
    required this.amount,
    required this.category,
    required this.reason,
    required this.version,
    super.key,
  });
  final String kind, category, reason;
  final int amount, version;
  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    mainAxisSize: MainAxisSize.min,
    children: [
      Text(
        '${kind == 'in' ? 'Kas masuk' : 'Kas keluar'} ${formatRupiah(amount)}',
      ),
      Text('Kategori: $category'),
      Text('Alasan: $reason'),
      Text('Versi shift $version'),
      const Text(
        'Konfirmasi hanya setelah uang fisik benar-benar masuk atau keluar.',
      ),
    ],
  );
}
