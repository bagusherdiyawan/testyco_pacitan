import 'package:flutter/material.dart';

class OrderLineEditor extends StatelessWidget {
  const OrderLineEditor({
    required this.title,
    required this.quantity,
    required this.choices,
    required this.localId,
    required this.enabled,
    required this.onQuantity,
    required this.onLocal,
    required this.onRemove,
    super.key,
  });
  final String title, quantity;
  final String? localId;
  final List<(String, String)> choices;
  final bool enabled;
  final ValueChanged<String> onQuantity;
  final ValueChanged<String?> onLocal;
  final VoidCallback onRemove;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(title, style: Theme.of(context).textTheme.titleMedium),
          DropdownButtonFormField<String>(
            key: ValueKey(
              'mapping:$localId:${choices.map((c) => c.$1).join(',')}',
            ),
            initialValue: localId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'SKU lokal toko'),
            items: [
              for (final choice in choices)
                DropdownMenuItem(
                  value: choice.$1,
                  child: Text(
                    choice.$2,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
            ],
            onChanged: enabled ? onLocal : null,
          ),
          if (choices.isEmpty)
            const Text(
              'Belum ada SKU lokal yang cocok. Perbarui katalog melalui web.',
            ),
          TextFormField(
            key: const Key('order-quantity'),
            initialValue: quantity,
            enabled: enabled,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'Jumlah kemasan'),
            onChanged: onQuantity,
          ),
          TextButton(
            onPressed: enabled ? onRemove : null,
            child: const Text('Hapus baris'),
          ),
        ],
      ),
    ),
  );
}
