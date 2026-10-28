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
    this.opened = 0,
    this.ready = 0,
    this.waste = 0,
    this.revision = 0,
    super.key,
  });
  final String sku, name, batch, expiry;
  final int packSize, usable, quarantined, opened, ready, waste, revision;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('$sku · $name', style: Theme.of(context).textTheme.titleMedium),
          Text('Batch $batch · Kedaluwarsa kemasan $expiry'),
          Text('Isi kemasan historis: $packSize biji'),
          const SizedBox(height: 8),
          Text('Utuh: $usable biji'),
          Text('Beku terbuka: $opened biji'),
          Text('Siap jual: $ready biji'),
          Text('Karantina: $quarantined biji'),
          Text('Susut kumulatif: $waste biji'),
          Text(
            'Total di luar karantina/susut: ${usable + opened + ready} biji',
          ),
          Text('Revisi stok: $revision'),
        ],
      ),
    ),
  );
}
