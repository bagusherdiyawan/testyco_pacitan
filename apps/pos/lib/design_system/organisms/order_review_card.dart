import 'package:flutter/material.dart';
import '../formatters.dart';

class OrderReviewCard extends StatelessWidget {
  const OrderReviewCard({
    required this.destination,
    required this.address,
    required this.mode,
    required this.kind,
    required this.total,
    required this.observedAt,
    required this.children,
    super.key,
  });
  final String destination, address, mode, kind, observedAt;
  final int total;
  final List<Widget> children;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Tinjauan server · $destination',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          Text(kind),
          Text(mode),
          if (address.isNotEmpty) Text(address),
          ...children,
          Text(
            'Total ${formatRupiah(total)}',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          Text('Dimuat ${formatWib(observedAt)}'),
          const Text(
            'Belum membuat pesanan atau mereservasi stok. Setelah dikirim, pengajuan menunggu persetujuan yang berwenang.',
          ),
        ],
      ),
    ),
  );
}
