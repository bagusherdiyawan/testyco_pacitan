import 'package:flutter/material.dart';

class DispatchBatchField extends StatelessWidget {
  const DispatchBatchField({
    required this.batch,
    required this.expiry,
    required this.packs,
    required this.enabled,
    required this.onChanged,
    super.key,
  });
  final String batch, expiry;
  final int packs;
  final bool enabled;
  final ValueChanged<String> onChanged;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: TextFormField(
      enabled: enabled,
      keyboardType: TextInputType.number,
      decoration: InputDecoration(
        labelText: 'Kemasan dari $batch',
        helperText: 'Utuh $packs kemasan · expiry $expiry',
        helperMaxLines: 3,
      ),
      onChanged: onChanged,
    ),
  );
}
