import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

class SaleReceipt extends StatelessWidget {
  const SaleReceipt({required this.sale, this.onPrint, super.key});
  final Map<String, dynamic> sale;
  final VoidCallback? onPrint;

  String shareableText() {
    final buf = StringBuffer()
      ..writeln('${sale['store_name']}')
      ..writeln('No: ${sale['number']}')
      ..writeln('Kasir: ${sale['cashier_name']} · ${wib(sale['sold_at'] as String)}')
      ..writeln('----------------');
    for (final line in sale['lines'] as List) {
      buf.writeln(
        '${line['name']} (${line['quantity']} ${line['unit']} × Rp ${line['unit_price']}) = Rp ${line['line_total']}',
      );
    }
    buf
      ..writeln('----------------')
      ..writeln('Diskon: Rp ${sale['discount_amount'] ?? 0}')
      ..writeln('Total: Rp ${sale['total_amount']}')
      ..writeln('Metode: ${sale['payment_method'] ?? 'cash'}');
    return buf.toString().trim();
  }
  String wib(String value) {
    final date = DateTime.tryParse(value);
    if (date == null) {
      return 'Waktu tidak tersedia';
    }
    final local = date.toUtc().add(const Duration(hours: 7));
    String pad(int x) => x.toString().padLeft(2, '0');
    return '${pad(local.day)}/${pad(local.month)}/${local.year} ${pad(local.hour)}:${pad(local.minute)} WIB';
  }

  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            sale['store_name'] as String,
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          Text(sale['number'] as String),
          Text(
            'Kasir ${sale['cashier_name']} · ${wib(sale['sold_at'] as String)}',
          ),
          if (sale['service_type'] != null)
            Text(
              'Jenis: ${sale['service_type'] == 'takeaway' ? 'Bawa pulang' : 'Makan di tempat'}',
            ),
          for (final line in sale['lines'] as List)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: Text(
                '${line['sku']} · ${line['name']}\n${line['quantity']} ${line['unit']} × Rp ${line['unit_price']} = Rp ${line['line_total']}',
              ),
            ),
          const Divider(),
          Text(
            'Total Rp ${sale['total_amount']}',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          Text(
            'Diskon Rp ${sale['discount_amount'] ?? 0} · ${sale['payment_method'] ?? 'cash'}',
          ),
          if ((sale['payment_method'] ?? 'cash') == 'cash') ...[
            Text('Tunai Rp ${sale['cash_received']}'),
            Text('Kembalian Rp ${sale['change_amount']}'),
          ] else
            Text('Referensi terverifikasi ${sale['payment_reference'] ?? ''}'),
          for (final r in sale['refunds'] ?? [])
            Text(
              'Refund Rp ${r['amount']} · ${r['payment_method']} · ${r['reference']}',
            ),
          if ((sale['consumption'] as List?)?.isNotEmpty == true) ...[
            const Divider(),
            const Text('Kemasan & alat makan terpakai:'),
            for (final c in sale['consumption'] as List)
              Text(
                '${c['name']} · ${c['quantity']} ${c['unit']}${(c['shortfall'] ?? 0) > 0 ? ' (Stok kurang ${c['shortfall']})' : ''}',
              ),
          ],
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              if (onPrint != null)
                OutlinedButton(
                  onPressed: onPrint,
                  child: const Text('Cetak struk'),
                ),
              OutlinedButton.icon(
                onPressed: () async {
                  await Clipboard.setData(ClipboardData(text: shareableText()));
                  if (context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                        content: Text('Struk disalin, siap dibagikan ke WhatsApp.'),
                      ),
                    );
                  }
                },
                icon: const Icon(Icons.share_outlined),
                label: const Text('Salin / bagikan struk'),
              ),
            ],
          ),
        ],
      ),
    ),
  );
}
