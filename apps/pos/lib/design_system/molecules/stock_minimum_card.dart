import 'package:flutter/material.dart';

class StockMinimumCard extends StatelessWidget {
  const StockMinimumCard({
    required this.sku,
    required this.name,
    required this.available,
    required this.usable,
    required this.opened,
    required this.ready,
    required this.minimum,
    required this.shortfall,
    required this.isLow,
    super.key,
  });
  final String sku, name;
  final int available, usable, opened, ready, shortfall;
  final int? minimum;
  final bool isLow;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('$sku · $name', style: Theme.of(context).textTheme.titleMedium),
          Text('Tersedia: $available biji'),
          Text('Utuh $usable · Terbuka $opened · Siap $ready'),
          Text(
            minimum == null
                ? 'Ambang belum diaktifkan'
                : 'Ambang: $minimum biji',
          ),
          if (isLow)
            Semantics(
              liveRegion: true,
              child: Text(
                'Stok rendah · Kekurangan $shortfall biji',
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontWeight: FontWeight.bold,
                ),
              ),
            ),
          if (!isLow && minimum != null) const Text('Stok memenuhi ambang.'),
        ],
      ),
    ),
  );
}
