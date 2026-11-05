import 'package:flutter/material.dart';
import '../application/receipt_printer.dart';
import '../application/bluetooth_printer.dart';

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
  String connection = 'bluetooth';
  String? deviceId;
  List<Map<String, String>> devices = [];
  bool cut = false, busy = false, profileLoaded = false;
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
      if (p != null && !profileLoaded) {
        profileLoaded = true;
        connection = p['transport'] ?? 'tcp';
        if (connection == 'bluetooth') {
          deviceId = p['host'];
          if (!devices.any((d) => d['id'] == deviceId)) {
            devices.add({'id': deviceId!, 'name': p['name'] ?? deviceId!});
          }
        }
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
    appBar: AppBar(title: const Text('Cetak struk Bluetooth')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        const Text(
          'Pasangkan printer ESC/POS Bluetooth Classic melalui pengaturan Android, kemudian pilih perangkat di bawah. Byte terkirim belum membuktikan kertas tercetak. Tidak mengulang otomatis; cetak berikutnya selalu bertanda SALINAN. Penjualan tidak dikirim ulang.',
        ),
        if (error != null) Text(error!),
        if (busy) const LinearProgressIndicator(),
        Text('Struk ${widget.sale['number']}'),
        DropdownButtonFormField<String>(
          initialValue: connection,
          decoration: const InputDecoration(labelText: 'Koneksi printer'),
          items: const [
            DropdownMenuItem(
              value: 'bluetooth',
              child: Text('Bluetooth Android'),
            ),
            DropdownMenuItem(value: 'tcp', child: Text('LAN TCP')),
          ],
          onChanged: busy ? null : (v) => setState(() => connection = v!),
        ),
        if (connection == 'bluetooth') ...[
          TextButton.icon(
            icon: const Icon(Icons.bluetooth_searching),
            label: const Text('Muat perangkat yang sudah dipasangkan'),
            onPressed: busy
                ? null
                : () => run(() async {
                    devices = await BluetoothPrinterTransport().pairedDevices();
                    if (!devices.any((d) => d['id'] == deviceId)) {
                      deviceId = null;
                    }
                  }),
          ),
          DropdownButtonFormField<String>(
            key: ValueKey('${devices.length}:$deviceId'),
            initialValue: deviceId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Printer Bluetooth'),
            items: devices
                .map(
                  (d) => DropdownMenuItem(
                    value: d['id'],
                    child: Text(
                      '${d['name']} (${d['id']})',
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                )
                .toList(),
            onChanged: busy ? null : (v) => setState(() => deviceId = v),
          ),
          const Text(
            'Mendukung Android Bluetooth Classic/SPP. Untuk iOS atau BLE diperlukan driver sesuai model printer.',
          ),
        ],
        if (connection == 'tcp')
          TextField(
            controller: host,
            enabled: !busy,
            decoration: const InputDecoration(
              labelText: 'IPv4 printer sesuai perangkat',
            ),
          ),
        if (connection == 'tcp')
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
                    'transport': connection,
                    'host': connection == 'bluetooth'
                        ? deviceId
                        : host.text.trim(),
                    'name': connection == 'bluetooth' && deviceId != null
                        ? devices.firstWhere((d) => d['id'] == deviceId)['name']
                        : null,
                    'port': connection == 'bluetooth'
                        ? 0
                        : int.tryParse(port.text),
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
