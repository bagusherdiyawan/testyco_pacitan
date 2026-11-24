import 'dart:convert';
import 'package:file_picker/file_picker.dart';
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
  final host = TextEditingController(),
      port = TextEditingController(),
      headerText = TextEditingController(),
      footerText = TextEditingController(text: 'Simpan sebagai bukti transaksi');
  int? paper;
  String connection = 'bluetooth';
  String? deviceId, logoBase64;
  List<Map<String, String>> devices = [];
  bool cut = false,
      busy = false,
      profileLoaded = false,
      templateLoaded = false,
      showLogo = false,
      showAddress = true,
      showCashier = true,
      showDiscount = true;
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
    headerText.dispose();
    footerText.dispose();
    super.dispose();
  }

  Future<void> pickLogo() async {
    final result = await FilePicker.platform.pickFiles(
      type: FileType.image,
      withData: true,
    );
    final bytes = result?.files.singleOrNull?.bytes;
    if (bytes == null) return;
    if (bytes.length > 512 * 1024) {
      setState(() => error = 'Ukuran logo struk maksimal 512 KB.');
      return;
    }
    setState(() {
      logoBase64 = base64Encode(bytes);
      showLogo = true;
      error = null;
    });
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
      final t = data!['template'] as Map<String, dynamic>?;
      if (t != null && !templateLoaded) {
        templateLoaded = true;
        headerText.text = (t['header_text'] as String?) ?? '';
        footerText.text =
            (t['footer_text'] as String?) ?? 'Simpan sebagai bukti transaksi';
        showAddress = t['show_address'] != false;
        showCashier = t['show_cashier'] != false;
        showDiscount = t['show_discount'] != false;
        showLogo = t['show_logo'] == true;
        logoBase64 = t['logo_base64'] as String?;
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
        const SizedBox(height: 8),
        ExpansionTile(
          title: const Text('Edit tampilan struk thermal (Logo, Header, Footer)'),
          children: [
            Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (logoBase64 != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Row(
                        children: [
                          Image.memory(
                            base64Decode(logoBase64!),
                            height: 56,
                            fit: BoxFit.contain,
                          ),
                          const SizedBox(width: 12),
                          TextButton(
                            onPressed: busy
                                ? null
                                : () => setState(() {
                                    logoBase64 = null;
                                    showLogo = false;
                                  }),
                            child: const Text('Hapus logo'),
                          ),
                        ],
                      ),
                    ),
                  OutlinedButton.icon(
                    onPressed: busy ? null : pickLogo,
                    icon: const Icon(Icons.image_outlined),
                    label: const Text('Pilih logo struk (PNG/JPG, maks 512 KB)'),
                  ),
                  SwitchListTile(
                    value: showLogo,
                    onChanged: busy || logoBase64 == null
                        ? null
                        : (v) => setState(() => showLogo = v),
                    title: const Text('Tampilkan logo di bagian atas struk'),
                  ),
                  TextField(
                    controller: headerText,
                    enabled: !busy,
                    maxLength: 160,
                    onChanged: (_) => setState(() {}),
                    decoration: const InputDecoration(
                      labelText: 'Tagline / header tambahan (opsional)',
                    ),
                  ),
                  TextField(
                    controller: footerText,
                    enabled: !busy,
                    maxLength: 240,
                    onChanged: (_) => setState(() {}),
                    decoration: const InputDecoration(
                      labelText: 'Pesan bawah / footer struk',
                    ),
                  ),
                  SwitchListTile(
                    value: showAddress,
                    onChanged: busy
                        ? null
                        : (v) => setState(() => showAddress = v),
                    title: const Text('Tampilkan alamat toko'),
                  ),
                  SwitchListTile(
                    value: showCashier,
                    onChanged: busy
                        ? null
                        : (v) => setState(() => showCashier = v),
                    title: const Text('Tampilkan nama kasir'),
                  ),
                  SwitchListTile(
                    value: showDiscount,
                    onChanged: busy
                        ? null
                        : (v) => setState(() => showDiscount = v),
                    title: const Text('Tampilkan baris diskon'),
                  ),
                  const SizedBox(height: 8),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.center,
                        children: [
                          const Text(
                            'Pratinjau Struk Thermal',
                            style: TextStyle(fontWeight: FontWeight.bold),
                          ),
                          const Divider(),
                          if (showLogo && logoBase64 != null)
                            Padding(
                              padding: const EdgeInsets.only(bottom: 6),
                              child: Image.memory(
                                base64Decode(logoBase64!),
                                height: 42,
                                fit: BoxFit.contain,
                              ),
                            ),
                          Text('${widget.sale['store_name']}'),
                          if (showAddress &&
                              '${widget.sale['store_address'] ?? ''}'
                                  .isNotEmpty)
                            Text('${widget.sale['store_address']}'),
                          if (headerText.text.trim().isNotEmpty)
                            Text(headerText.text.trim()),
                          Text('${widget.sale['number']}'),
                          if (showCashier)
                            Text('Kasir: ${widget.sale['cashier_name']}'),
                          const Divider(),
                          Text('Total Rp${widget.sale['total_amount']}'),
                          if (showDiscount)
                            Text('Diskon Rp${widget.sale['discount_amount'] ?? 0}'),
                          if (footerText.text.trim().isNotEmpty) ...[
                            const SizedBox(height: 6),
                            Text(footerText.text.trim()),
                          ],
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 8),
                  FilledButton(
                    onPressed: busy
                        ? null
                        : () => run(() async {
                            await widget.printer.saveTemplate({
                              'header_text': headerText.text,
                              'footer_text': footerText.text,
                              'show_address': showAddress,
                              'show_cashier': showCashier,
                              'show_discount': showDiscount,
                              'show_logo': showLogo,
                              'logo_base64': logoBase64,
                            });
                          }),
                    child: const Text('Simpan tampilan struk'),
                  ),
                ],
              ),
            ),
          ],
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
