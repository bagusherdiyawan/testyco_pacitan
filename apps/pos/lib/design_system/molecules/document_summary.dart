import 'package:flutter/material.dart';

/// Reusable read-only document surface. Callers own labels and authorization.
class DocumentSummary extends StatelessWidget {
  const DocumentSummary({
    required this.text,
    this.details = const [],
    this.actions = const [],
    super.key,
  });
  final String text;
  final List<Widget> details, actions;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SelectableText(text),
          for (final detail in details)
            Padding(padding: const EdgeInsets.only(top: 8), child: detail),
          if (actions.isNotEmpty) Wrap(spacing: 8, children: actions),
        ],
      ),
    ),
  );
}
