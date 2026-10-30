import 'package:flutter/material.dart';

class StockMovementCard extends StatelessWidget {
  const StockMovementCard({
    required this.sku,
    required this.name,
    required this.batch,
    required this.packSize,
    required this.quantity,
    required this.bucket,
    required this.source,
    required this.sourceId,
    required this.actor,
    required this.time,
    required this.id,
    this.note,
    super.key,
  });
  final String sku, name, batch, bucket, source, sourceId, time, id;
  final String? note;
  final int packSize, quantity, actor;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('$sku · $name', style: Theme.of(context).textTheme.titleMedium),
          Text('Batch $batch · Isi historis $packSize biji/kemasan'),
          Text(
            '${quantity > 0 ? '+' : ''}$quantity biji · $bucket',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          Text('$source · $time'),
          Text('Pelaku #$actor'),
          SelectableText('Referensi sumber: $sourceId'),
          SelectableText('ID pergerakan: $id'),
          if (note != null && note!.isNotEmpty) Text('Catatan: $note'),
        ],
      ),
    ),
  );
}
