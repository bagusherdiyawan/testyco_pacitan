import 'package:flutter/material.dart';

class ShipmentReceiptFields extends StatelessWidget {
  const ShipmentReceiptFields({
    required this.batch,
    required this.expiry,
    required this.sent,
    required this.packSize,
    required this.good,
    required this.damaged,
    required this.note,
    required this.enabled,
    super.key,
  });
  final String batch, expiry;
  final int sent, packSize;
  final TextEditingController good, damaged, note;
  final bool enabled;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Batch $batch', style: Theme.of(context).textTheme.titleMedium),
          Text(
            'Dikirim $sent kemasan × $packSize biji · Kedaluwarsa kemasan $expiry',
          ),
          LayoutBuilder(
            builder: (context, box) {
              final width = box.maxWidth < 500
                  ? box.maxWidth
                  : (box.maxWidth - 12) / 2;
              return Wrap(
                spacing: 12,
                runSpacing: 8,
                children: [
                  for (final field in [
                    ('Baik (kemasan)', good),
                    ('Rusak (kemasan)', damaged),
                  ])
                    SizedBox(
                      width: width,
                      child: TextField(
                        controller: field.$2,
                        enabled: enabled,
                        keyboardType: TextInputType.number,
                        decoration: InputDecoration(labelText: field.$1),
                      ),
                    ),
                ],
              );
            },
          ),
          TextField(
            controller: note,
            enabled: enabled,
            maxLength: 2000,
            decoration: const InputDecoration(
              labelText: 'Catatan kondisi / selisih per batch',
            ),
          ),
        ],
      ),
    ),
  );
}
