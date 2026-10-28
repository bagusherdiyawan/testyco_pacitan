import 'package:flutter/material.dart';

class StockBatchCard extends StatelessWidget {
  const StockBatchCard({
    required this.sku,
    required this.name,
    required this.batch,
    required this.expiry,
    required this.packSize,
    required this.usable,
    required this.quarantined,
    super.key,
  });
  final String sku, name, batch, expiry;
  final int packSize, usable, quarantined;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('$sku · $name', style: Theme.of(context).textTheme.titleMedium),
          Text('Batch $batch · Kedaluwarsa $expiry'),
          Text('Isi kemasan historis: $packSize biji'),
          const SizedBox(height: 8),
          Text('Diterima baik: $usable biji'),
          Text('Karantina: $quarantined biji'),
        ],
      ),
    ),
  );
}
