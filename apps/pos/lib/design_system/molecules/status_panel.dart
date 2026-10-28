import 'package:flutter/material.dart';
import '../atoms/status_badge.dart';

class StatusPanel extends StatelessWidget {
  const StatusPanel({
    required this.title,
    required this.description,
    super.key,
  });
  final String title;
  final String description;
  @override
  Widget build(BuildContext context) => Center(
    child: SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const StatusBadge('Fondasi pengembangan'),
          Text(title, style: Theme.of(context).textTheme.headlineMedium),
          const SizedBox(height: 16),
          Text(description, textAlign: TextAlign.center),
        ],
      ),
    ),
  );
}
