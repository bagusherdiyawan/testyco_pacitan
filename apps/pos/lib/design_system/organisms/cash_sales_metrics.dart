import 'package:flutter/material.dart';

String reportRupiah(int value) =>
    'Rp ${value.toString().replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+(?!\d))'), (m) => '${m[1]}.')}';

class CashSalesMetrics extends StatelessWidget {
  const CashSalesMetrics({
    required this.count,
    required this.gross,
    required this.discount,
    required this.net,
    super.key,
  });
  final int count, gross, discount, net;
  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, box) => Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final metric in [
          ('Transaksi', '$count'),
          ('Penjualan kotor', reportRupiah(gross)),
          ('Diskon tercatat', reportRupiah(discount)),
          ('Penjualan bersih', reportRupiah(net)),
        ])
          SizedBox(
            width: box.maxWidth < 600 ? box.maxWidth : (box.maxWidth - 8) / 2,
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(metric.$1),
                    Text(
                      metric.$2,
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                  ],
                ),
              ),
            ),
          ),
      ],
    ),
  );
}
