import 'package:flutter/material.dart';

class StockNumberField extends StatelessWidget {
  const StockNumberField({
    required this.label,
    required this.controller,
    required this.enabled,
    super.key,
  });
  final String label;
  final TextEditingController controller;
  final bool enabled;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: TextField(
      controller: controller,
      enabled: enabled,
      keyboardType: TextInputType.number,
      decoration: InputDecoration(
        labelText: label,
        border: const OutlineInputBorder(),
      ),
    ),
  );
}
