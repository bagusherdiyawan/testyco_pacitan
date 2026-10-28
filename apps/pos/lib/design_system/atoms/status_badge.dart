import 'package:flutter/material.dart';

class StatusBadge extends StatelessWidget {
  const StatusBadge(this.label, {super.key});
  final String label;
  @override
  Widget build(BuildContext context) => Chip(label: Text(label));
}
