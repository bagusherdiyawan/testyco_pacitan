import 'package:flutter/material.dart';

class StockCountCard extends StatelessWidget {
  const StockCountCard({
    required this.batch,
    required this.purpose,
    required this.bucket,
    required this.status,
    required this.reason,
    required this.expected,
    required this.counted,
    required this.difference,
    required this.revision,
    required this.stale,
    this.reviewNote,
    this.onApprove,
    this.onReject,
    this.onCancel,
    super.key,
  });
  final String batch, purpose, bucket, status, reason;
  final String? reviewNote;
  final int expected, counted, difference, revision;
  final bool stale;
  final VoidCallback? onApprove, onReject, onCancel;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '$purpose · $batch · $status',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          Text('Bucket $bucket · Snapshot $revision'),
          Text('Sistem $expected · Fisik $counted · Selisih $difference biji'),
          Text(reason),
          if (stale)
            const Text(
              'Snapshot kedaluwarsa. Hitung ulang; persetujuan dinonaktifkan.',
            ),
          if (reviewNote != null) Text('Catatan review: $reviewNote'),
          if (onApprove != null || onReject != null || onCancel != null)
            Wrap(
              spacing: 8,
              children: [
                if (onApprove != null)
                  FilledButton(
                    onPressed: onApprove,
                    child: const Text('Setujui penyesuaian'),
                  ),
                if (onReject != null)
                  OutlinedButton(
                    onPressed: onReject,
                    child: const Text('Tolak pengajuan'),
                  ),
                if (onCancel != null)
                  OutlinedButton(
                    onPressed: onCancel,
                    child: const Text('Batalkan pengajuan sendiri'),
                  ),
              ],
            ),
        ],
      ),
    ),
  );
}
