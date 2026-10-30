import 'package:flutter/material.dart';

class ReceiptHistoryCard extends StatelessWidget {
  const ReceiptHistoryCard({
    required this.number,
    required this.time,
    required this.cashier,
    required this.total,
    required this.onOpen,
    super.key,
  });
  final String number, time, cashier, total;
  final VoidCallback? onOpen;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(number, style: Theme.of(context).textTheme.titleMedium),
          Text(time),
          Text('Kasir $cashier'),
          Text(total),
          TextButton(onPressed: onOpen, child: const Text('Buka struk')),
        ],
      ),
    ),
  );
}
