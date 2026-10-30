import 'package:flutter/material.dart';

class IntakeReviewCard extends StatelessWidget {
  const IntakeReviewCard({required this.snapshot, super.key});
  final Map<String, dynamic> snapshot;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Gudang ${snapshot['store_name']}',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          Text(
            '${snapshot['sku']} · ${snapshot['name']} · ${snapshot['condition']}',
          ),
          Text(
            'Batch ${snapshot['batch_code']} · Kedaluwarsa ${snapshot['expires_on']}',
          ),
          Text(
            '${snapshot['quantity_packs']} kemasan × ${snapshot['pack_size']} biji = ${snapshot['quantity_pieces']} biji ditambahkan',
          ),
          Text('Catatan: ${snapshot['note']}'),
          const Text(
            'Catat hanya barang fisik yang diterima baik. Ini penambahan stok, bukan total saldo terkini atau sertifikasi mutu.',
          ),
        ],
      ),
    ),
  );
}
