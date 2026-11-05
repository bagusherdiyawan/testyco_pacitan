import 'package:flutter/services.dart';
import 'printer_transport.dart';

/// Android paired Classic/SPP devices. No discovery, location access or auto-retry.
class BluetoothPrinterTransport implements PrinterTransport {
  BluetoothPrinterTransport({MethodChannel? channel})
    : channel = channel ?? const MethodChannel('testyco/receipt_bluetooth');
  final MethodChannel channel;
  Future<List<Map<String, String>>> pairedDevices() async {
    final rows = await channel.invokeListMethod<dynamic>('pairedDevices');
    if (rows == null) {
      throw const FormatException('Daftar printer tidak valid.');
    }
    return rows.map((row) {
      if (row is! Map ||
          row['id'] is! String ||
          !validAddress(row['id']) ||
          row['name'] is! String) {
        throw const FormatException('Identitas printer tidak valid.');
      }
      return {'id': row['id'] as String, 'name': row['name'] as String};
    }).toList();
  }

  static bool validAddress(Object? value) =>
      value is String &&
      RegExp(r'^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$').hasMatch(value);

  @override
  Future<void> send(String host, int port, Uint8List bytes) async {
    if (!validAddress(host) || bytes.isEmpty) {
      throw const FormatException('Printer Bluetooth atau struk tidak valid.');
    }
    final result = await channel.invokeMethod<bool>('send', {
      'device_id': host,
      'bytes': bytes,
    });
    if (result != true) throw StateError('Pengiriman Bluetooth belum pasti.');
  }
}
