import 'package:flutter/material.dart';

const casePlanTypes = {
  'return': 'Retur',
  'replacement': 'Pengganti',
  'credit': 'Kredit',
};

class ShipmentCaseCard extends StatelessWidget {
  const ShipmentCaseCard({required this.data, this.plan = false, super.key});
  final Map<String, dynamic> data;
  final bool plan;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (plan) ...[
            Text(
              'Rencana ${casePlanTypes[data['type']] ?? data['type']} · ${data['status']}',
            ),
            Text('${data['proposal'] ?? ''}'),
            Text('Usulan oleh #${data['proposed_by']}'),
            if (data['decision_note'] != null)
              Text('Keputusan: ${data['decision_note']}'),
            const Text(
              'Persetujuan rencana tidak memindahkan stok atau membukukan kredit.',
            ),
          ] else ...[
            if (data['handler'] != null)
              Text(
                'Petugas ${data['handler']} · ${data['received_at'] == null ? 'Dalam pengiriman' : 'Diterima'}',
              ),
            if (data['reason'] != null) Text('${data['reason']}'),
            if (data['message'] != null) Text('${data['message']}'),
            if (data['problem_packs'] != null)
              Text('Selisih ${data['problem_packs']} kemasan'),
            for (final l in data['lines'] ?? [])
              Text(
                '${l['sku'] ?? l['local_sku']} → ${l['local_sku']}\nBatch ${l['batch_code']} · Isi ${l['pack_size']} · Kirim ${l['sent_packs'] ?? l['quantity_packs']} · ${l.containsKey('received_packs') ? 'Karantina ${l['received_packs'] ?? '—'}' : 'Baik ${l['good_packs'] ?? '—'} · Rusak ${l['damaged_packs'] ?? '—'}'} · Kurang ${l['missing_packs'] ?? '—'}\n${l['condition_note'] ?? ''}',
              ),
          ],
          if (data['reference'] != null)
            Text('Referensi: ${data['reference']}'),
        ],
      ),
    ),
  );
}
