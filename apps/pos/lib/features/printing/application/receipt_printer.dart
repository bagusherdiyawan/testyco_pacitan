import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';
import 'package:uuid/uuid.dart';
import '../../../core/storage/device_state.dart';
import 'bluetooth_printer.dart';
import 'printer_transport.dart';
export 'printer_transport.dart';

class EscPosReceipt {
  static String text(Object? value) =>
      '$value'.replaceAll(RegExp(r'[^\x20-\x7e]'), '?');
  static List<String> wrap(String value, int width) {
    final lines = <String>[];
    while (value.length > width) {
      lines.add(value.substring(0, width));
      value = value.substring(width);
    }
    lines.add(value);
    return lines;
  }

  static Uint8List encode(
    Map<String, dynamic> sale,
    int paper, {
    required bool copy,
    bool cut = false,
  }) {
    if (![58, 80].contains(paper) ||
        sale['id'] is! String ||
        sale['number'] is! String ||
        (sale['number'] as String).startsWith('OFF-') ||
        sale['currency'] != 'IDR' ||
        sale['status'] != 'completed' ||
        sale['lines'] is! List ||
        (sale['lines'] as List).isEmpty) {
      throw const FormatException(
        'Hanya struk server selesai dengan ukuran kertas eksplisit yang dapat dicetak.',
      );
    }
    final width = paper == 58 ? 32 : 48, out = BytesBuilder();
    out.add([27, 64, 27, 97, 0]); // initialize, left align; never open drawer
    void line(Object? value) {
      for (final row in wrap(text(value), width)) {
        out.add(ascii.encode(row));
        out.addByte(10);
      }
    }

    int money(String key) {
      final v = sale[key];
      if (v is! int || v < 0) {
        throw FormatException('Nominal $key tidak valid.');
      }
      return v;
    }

    line(sale['store_name']);
    line(sale['store_address'] ?? '');
    line(copy ? 'SALINAN / CETAK ULANG' : 'STRUK PENJUALAN');
    line(sale['number']);
    final at = DateTime.parse(
      sale['sold_at'],
    ).toUtc().add(const Duration(hours: 7));
    line('${at.toIso8601String().substring(0, 19).replaceAll('T', ' ')} WIB');
    line('Kasir: ${sale['cashier_name']}');
    line('-' * width);
    for (final row in sale['lines']) {
      for (final key in ['quantity', 'unit_price', 'line_total']) {
        if (row[key] is! int || row[key] < 0) {
          throw const FormatException('Baris struk tidak valid.');
        }
      }
      line('${row['sku']} ${row['name']}');
      line('${row['quantity']} ${row['unit']} x Rp${row['unit_price']}');
      line('Rp${row['line_total']}');
    }
    line('-' * width);
    line('Subtotal Rp${money('subtotal')}');
    line('Diskon   Rp${money('discount_amount')}');
    line('TOTAL    Rp${money('total_amount')}');
    line('Metode: ${sale['payment_method']}');
    if (sale['payment_method'] == 'cash') {
      line('Tunai    Rp${money('cash_received')}');
      line('Kembali  Rp${money('change_amount')}');
    } else {
      line('Ref: ${sale['payment_reference'] ?? ''}');
    }
    for (final r in sale['refunds'] ?? []) {
      line('Refund tercatat Rp${r['amount']} ${r['payment_method']}');
    }
    line('Simpan sebagai bukti transaksi');
    out.add([10, 10, 10]);
    if (cut) out.add([29, 86, 0]);
    return out.toBytes();
  }
}

class TcpEscPosTransport implements PrinterTransport {
  @override
  Future<void> send(String host, int port, Uint8List bytes) async {
    Socket? socket;
    try {
      socket = await Socket.connect(
        host,
        port,
        timeout: const Duration(seconds: 5),
      );
      socket.add(bytes);
      await socket.flush().timeout(const Duration(seconds: 5));
      await socket.close().timeout(const Duration(seconds: 5));
    } finally {
      socket?.destroy();
    }
  }
}

class ReceiptPrinter {
  ReceiptPrinter(this.storage, this.scope, {this.transport});
  final DeviceState storage;
  final String scope;
  final PrinterTransport? transport;
  String get key => 'printer:$scope';
  Future<Map<String, dynamic>> load() async =>
      await storage.read(key) ?? {'profile': null, 'jobs': <String, dynamic>{}};
  static void validateProfile(Map p) {
    if (p['transport'] == 'bluetooth') {
      if (!BluetoothPrinterTransport.validAddress(p['host']) ||
          p['port'] != 0 ||
          ![58, 80].contains(p['paper']) ||
          p['cut'] is! bool) {
        throw const FormatException(
          'Pilih printer Bluetooth dan kertas58/80 mm.',
        );
      }
      return;
    }
    if (p['transport'] != null && p['transport'] != 'tcp') {
      throw const FormatException('Jenis koneksi printer tidak didukung.');
    }
    final ip = InternetAddress.tryParse(p['host'] ?? '');
    if (ip == null ||
        ip.type != InternetAddressType.IPv4 ||
        p['port'] is! int ||
        p['port'] < 1 ||
        p['port'] > 65535 ||
        ![58, 80].contains(p['paper']) ||
        p['cut'] is! bool) {
      throw const FormatException(
        'Isi IPv4 printer, port1–65535, kertas58/80 dan pilihan pemotong.',
      );
    }
  }

  Future<void> configure(Map<String, dynamic> profile) async {
    validateProfile(profile);
    await storage.update(key, (s) {
      s ??= {'jobs': <String, dynamic>{}};
      s['profile'] = profile;
      return s;
    });
  }

  Future<String> printSale(Map<String, dynamic> sale) async {
    final id = const Uuid().v4();
    late Map profile;
    late Uint8List bytes;
    await storage.update(key, (s) {
      s ??= {'jobs': <String, dynamic>{}};
      final jobs = s['jobs'] as Map;
      if (jobs.values.any(
        (j) => ['sending', 'unknown', 'sent_unconfirmed'].contains(j['state']),
      )) {
        throw StateError(
          'Periksa hasil cetak belum pasti sebelum mencetak lagi.',
        );
      }
      profile = s['profile'] ?? {};
      validateProfile(profile);
      if (jobs.length >= 500) {
        throw StateError(
          'Riwayat cetak perangkat penuh; arsipkan dengan penanggung jawab perangkat.',
        );
      }
      final copy =
          s['history_archived'] == true ||
          jobs.values.any((j) => j['sale_id'] == sale['id']);
      bytes = EscPosReceipt.encode(
        sale,
        profile['paper'],
        copy: copy,
        cut: profile['cut'],
      );
      jobs[id] = {
        'id': id,
        'sale_id': sale['id'],
        'number': sale['number'],
        'state': 'sending',
        'copy': copy,
        'profile': profile,
        'bytes': base64Encode(bytes),
        'created_at': DateTime.now().toUtc().toIso8601String(),
      };
      return s;
    });
    try {
      final connection =
          transport ??
          (profile['transport'] == 'bluetooth'
              ? BluetoothPrinterTransport()
              : TcpEscPosTransport());
      await connection.send(profile['host'], profile['port'], bytes);
      await storage.update(key, (s) {
        s!['jobs'][id]['state'] = 'sent_unconfirmed';
        return s;
      });
    } catch (_) {
      await storage.update(key, (s) {
        s!['jobs'][id]['state'] = 'unknown';
        return s;
      });
      throw StateError(
        'Hasil cetak belum pasti. Periksa kertas/printer; tidak ada pengulangan otomatis atau transaksi penjualan baru.',
      );
    }
    return id;
  }

  Future<void> archiveChecked() async {
    final current = await load();
    final jobs = Map<String, dynamic>.from(current['jobs']);
    jobs.removeWhere(
      (_, j) => !['printed', 'checked_not_printed'].contains(j['state']),
    );
    if (jobs.isEmpty) return;
    final archiveId = const Uuid().v4();
    await storage.write('$key:archive:$archiveId', {
      'jobs': jobs,
      'archived_at': DateTime.now().toUtc().toIso8601String(),
    });
    await storage.update(key, (s) {
      s!['history_archived'] = true;
      s['archives'] ??= <String>[];
      (s['archives'] as List).add(archiveId);
      for (final id in jobs.keys) {
        if ([
          'printed',
          'checked_not_printed',
        ].contains(s['jobs'][id]?['state'])) {
          (s['jobs'] as Map).remove(id);
        }
      }
      return s;
    });
  }

  Future<void> confirm(String id, bool printed) async {
    await storage.update(key, (s) {
      final j = s?['jobs'][id];
      if (j == null ||
          !['sending', 'unknown', 'sent_unconfirmed'].contains(j['state'])) {
        throw StateError('Pekerjaan tidak dapat dikonfirmasi.');
      }
      j['state'] = printed ? 'printed' : 'checked_not_printed';
      j['checked_at'] = DateTime.now().toUtc().toIso8601String();
      return s!;
    });
  }
}
