import 'package:flutter/material.dart';
import '../formatters.dart';

class OrderChangeLine extends StatelessWidget {
  const OrderChangeLine({
    required this.sku,
    required this.localSku,
    required this.before,
    required this.after,
    required this.size,
    required this.price,
    super.key,
  });
  final String sku, localSku;
  final int before, after, size, price;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '$sku → $localSku',
          style: Theme.of(context).textTheme.titleMedium,
        ),
        Text('$before → $after kemasan · isi $size biji'),
        Text('${formatRupiah(price)}/kemasan sesuai persetujuan'),
      ],
    ),
  );
}
