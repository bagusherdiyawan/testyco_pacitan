import 'package:flutter/material.dart';
import '../application/receipt_printer.dart';

class ReceiptPrintPage extends StatefulWidget {
  const ReceiptPrintPage({
    required this.printer,
    required this.sale,
    super.key,
  });
  final ReceiptPrinter printer;
  final Map<String, dynamic> sale;
  @override
  State<ReceiptPrintPage> createState() => _ReceiptPrintPageState();
}

class _ReceiptPrintPageState extends State<ReceiptPrintPage> {
  final host = TextEditingController(), port = TextEditingController();
  int? paper;
  bool cut = false, busy = false;
  String? error;
  Map<String, dynamic>? data;
  @override
  void initState() {
    super.initState();
    refresh();
  }

  @override
  void dispose() {
    host.dispose();
    port.dispose();
    super.dispose();
  }

  Future<void> refresh() async {
    try {
      data = await widget.printer.load();
      final p = data!['profile'];
      if (p != null) {
        host.text = p['host'];
        port.text = '${p['port']}';
        paper = p['paper'];
        cut = p['cut'];
      }
    } catch (e) {
      error = '$e';
    }
    if (mounted) setState(() {});
  }

  Future<void> run(Future<void> Function() f) async {
    if (busy) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await f();
    } catch (e) {
      error = '$e';
    } finally {
      await refresh();
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Cetak struk ESC/POS LAN')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        const Text(
          'Gunakan printer ESC/POS di jaringan kasir. Byte terkirim belum membuktikan kertas tercetak. Tidak mengulang otomatis; cetak berikutnya selalu bertanda SALINAN. Penjualan tidak dikirim ulang.',
        ),
        if (error != null) Text(error!),
        if (busy) const LinearProgressIndicator(),
        Text('Struk ${widget.sale['number']}'),
        TextField(
          controller: host,
          enabled: !busy,
          decoration: const InputDecoration(
            labelText: 'IPv4 printer sesuai perangkat',
          ),
        ),
        TextField(
          controller: port,
          enabled: !busy,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(
            labelText: 'Port RAW TCP sesuai printer (umumnya9100)',
          ),
        ),
        DropdownButtonFormField<int>(
          initialValue: paper,
          decoration: const InputDecoration(labelText: 'Lebar kertas'),
          items: [58, 80]
              .map((n) => DropdownMenuItem(value: n, child: Text('$n mm')))
              .toList(),
          onChanged: busy ? null : (v) => setState(() => paper = v),
        ),
        CheckboxListTile(
          value: cut,
          onChanged: busy ? null : (v) => setState(() => cut = v!),
          title: const Text(
            'Printer mendukung pemotong ESC/POS; potong sesudah struk',
          ),
        ),
        FilledButton(
          onPressed: busy
              ? null
              : () => run(() async {
                  await widget.printer.configure({
                    'host': host.text.trim(),
                    'port': int.tryParse(port.text),
                    'paper': paper,
                    'cut': cut,
                  });
                }),
          child: const Text('Simpan konfigurasi printer'),
        ),
        FilledButton(
          onPressed: busy
              ? null
              : () => run(() async {
                  await widget.printer.printSale(widget.sale);
                }),
          child: const Text('Cetak struk / salinan'),
        ),
        TextButton(
          onPressed: busy ? null : () => run(widget.printer.archiveChecked),
          child: const Text(
            'Arsipkan pekerjaan terkonfirmasi; cetak berikutnya ditandai salinan',
          ),
        ),
        for (final j in (data?['jobs'] as Map? ?? {}).values)
          if (j['sale_id'] == widget.sale['id'] ||
              ['sending', 'unknown', 'sent_unconfirmed'].contains(j['state']))
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${j['number']} · ${j['state']}'),
                    if ([
                      'sending',
                      'unknown',
                      'sent_unconfirmed',
                    ].contains(j['state'])) ...[
                      TextButton(
                        onPressed: busy
                            ? null
                            : () => run(
                                () => widget.printer.confirm(j['id'], true),
                              ),
                        child: const Text('Saya periksa: kertas tercetak'),
                      ),
                      TextButton(
                        onPressed: busy
                            ? null
                            : () => run(
                                () => widget.printer.confirm(j['id'], false),
                              ),
                        child: const Text('Saya periksa: belum tercetak'),
                      ),
                    ],
                  ],
                ),
              ),
            ),
      ],
    ),
  );
}
