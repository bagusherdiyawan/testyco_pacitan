import 'package:flutter/material.dart';
import '../../features/ordering/domain/order_change.dart';
import '../formatters.dart';
import '../molecules/order_change_line.dart';

class OrderChangeCard extends StatelessWidget {
  const OrderChangeCard({
    required this.record,
    this.actions = const [],
    super.key,
  });
  final OrderChangeRecord record;
  final List<Widget> actions;
  String get status => switch (record.status) {
    'pending' => 'Menunggu keputusan',
    'approved' => 'Disetujui',
    'rejected' => 'Ditolak',
    _ => 'Ditarik',
  };
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(status, style: Theme.of(context).textTheme.titleLarge),
          Text('Usulan ${record.id} · Versi asal ${record.baseVersion}'),
          Text('Pengusul #${record.requestedBy} · ${record.reason}'),
          for (final line in record.before.lines)
            OrderChangeLine(
              sku: line.sku,
              localSku: line.localSku,
              before: line.packs,
              after: record.after.lines
                  .firstWhere((v) => v.id == line.id)
                  .packs,
              size: line.size,
              price: line.price,
            ),
          Text(
            'Total ${formatRupiah(record.before.total)} → ${formatRupiah(record.after.total)}',
          ),
          if (record.status != 'pending') ...[
            Text('Keputusan oleh #${record.decidedBy ?? '—'}'),
            if (record.note != null) Text('Catatan keputusan: ${record.note}'),
            if (record.decidedAt != null)
              Text('Waktu keputusan: ${formatWib(record.decidedAt!)}'),
          ],
          Wrap(spacing: 8, runSpacing: 8, children: actions),
        ],
      ),
    ),
  );
}
