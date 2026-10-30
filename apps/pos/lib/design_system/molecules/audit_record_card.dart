import 'dart:convert';
import 'package:flutter/material.dart';

class AuditRecordCard extends StatelessWidget {
  const AuditRecordCard({
    required this.id,
    required this.action,
    required this.subject,
    required this.actor,
    required this.time,
    this.before,
    this.after,
    super.key,
  });
  final String id, action, subject, actor, time;
  final Object? before, after;
  String render(Object? value) {
    if (value == null) return 'Tidak ada snapshot';
    Object? data = value;
    if (value is String) {
      try {
        data = jsonDecode(value);
      } on FormatException {
        return value;
      }
    }
    return const JsonEncoder.withIndent('  ').convert(data);
  }

  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(action, style: Theme.of(context).textTheme.titleMedium),
          Text(time),
          Text(actor),
          SelectableText('Objek: $subject'),
          ExpansionTile(
            title: const Text('Perubahan sebelum / sesudah'),
            children: [
              Align(
                alignment: Alignment.centerLeft,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Sebelum'),
                    SelectableText(render(before)),
                    const SizedBox(height: 12),
                    const Text('Sesudah'),
                    SelectableText(render(after)),
                    const SizedBox(height: 12),
                    SelectableText('ID audit: $id'),
                  ],
                ),
              ),
            ],
          ),
        ],
      ),
    ),
  );
}
