import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';
import 'package:image/image.dart' as img;
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

  static Uint8List encodeLogoRaster(Uint8List rawBytes, int paper) {
    final decoded = img.decodeImage(rawBytes);
    if (decoded == null) {
      throw const FormatException('Format gambar logo struk tidak dikenali.');
    }
    final maxWidth = paper == 58 ? 192 : 256;
    final resized = decoded.width > maxWidth
        ? img.copyResize(decoded, width: maxWidth)
        : decoded;
    final widthBytes = (resized.width + 7) ~/ 8;
    final height = resized.height;
    final out = BytesBuilder();
    out.add([27, 97, 1]); // center align
    out.add([
      29,
      118,
      48,
      0,
      widthBytes & 0xff,
      (widthBytes >> 8) & 0xff,
      height & 0xff,
      (height >> 8) & 0xff,
    ]);
    for (var y = 0; y < height; y++) {
      for (var xb = 0; xb < widthBytes; xb++) {
        var byte = 0;
        for (var bit = 0; bit < 8; bit++) {
          final x = xb * 8 + bit;
          if (x < resized.width) {
            final p = resized.getPixel(x, y);
            final alpha = p.a.toDouble() / 255.0;
            final luma =
                (0.299 * p.r + 0.587 * p.g + 0.114 * p.b) * alpha +
                255.0 * (1.0 - alpha);
            if (luma < 140) {
              byte |= (1 << (7 - bit));
            }
          }
        }
        out.addByte(byte);
      }
    }
    out.add([10, 27, 97, 0]); // newline, back to left align
    return out.toBytes();
  }

  static Uint8List encode(
    Map<String, dynamic> sale,
    int paper, {
    required bool copy,
    bool cut = false,
    Map<String, dynamic>? template,
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
    final tpl = ReceiptPrinter.normalizeTemplate(template);
    final width = paper == 58 ? 32 : 48, out = BytesBuilder();
    out.add([27, 64, 27, 97, 0]); // initialize, left align; never open drawer
    if (tpl['show_logo'] == true &&
        tpl['logo_base64'] is String &&
        (tpl['logo_base64'] as String).isNotEmpty) {
      try {
        out.add(
          encodeLogoRaster(base64Decode(tpl['logo_base64'] as String), paper),
        );
      } catch (_) {
        // Ignore malformed logo image on print fallback rather than blocking receipt text.
      }
    }
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
    if (tpl['show_address'] == true && '${sale['store_address'] ?? ''}'.isNotEmpty) {
      line(sale['store_address']);
    }
    if ((tpl['header_text'] as String).isNotEmpty) {
      line(tpl['header_text']);
    }
    line(copy ? 'SALINAN / CETAK ULANG' : 'STRUK PENJUALAN');
    line(sale['number']);
    final at = DateTime.parse(
      sale['sold_at'],
    ).toUtc().add(const Duration(hours: 7));
    line('${at.toIso8601String().substring(0, 19).replaceAll('T', ' ')} WIB');
    if (tpl['show_cashier'] == true) {
      line('Kasir: ${sale['cashier_name']}');
    }
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
    if (tpl['show_discount'] == true) {
      line('Diskon   Rp${money('discount_amount')}');
    }
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
    if ((tpl['footer_text'] as String).isNotEmpty) {
      line(tpl['footer_text']);
    }
    out.add([10, 10, 10]);
    if (cut) out.add([29, 86, 0]);
    return out.toBytes();
  }

  static Uint8List encodeShiftSummary(
    Map<String, dynamic> shift,
    int paper, {
    String? storeName,
    bool cut = false,
  }) {
    if (![58, 80].contains(paper)) {
      throw const FormatException('Ukuran kertas harus 58mm atau 80mm.');
    }
    final width = paper == 58 ? 32 : 48;
    final out = BytesBuilder();
    out.add([27, 64, 27, 97, 0]);
    void line(Object? value) {
      for (final row in wrap(text(value), width)) {
        out.add(ascii.encode(row));
        out.addByte(10);
      }
    }

    line('REKAP TUTUP SHIFT');
    if (storeName != null && storeName.isNotEmpty) {
      line(storeName);
    }
    line('Terminal: ${shift['device_name'] ?? '-'}');
    line('Kasir: ${shift['cashier_name'] ?? '-'}');
    line('-' * width);
    line('Kas awal      : Rp ${shift['opening_cash'] ?? 0}');
    line('Kas fisik      : Rp ${shift['counted_cash'] ?? 0}');
    line('Selisih       : Rp ${shift['difference_cash'] ?? 0}');
    if (shift['deposit_cash'] != null) {
      line('Setoran       : Rp ${shift['deposit_cash']}');
    }
    if (shift['retained_cash'] != null) {
      line('Sisa modal    : Rp ${shift['retained_cash']}');
    }
    if ('${shift['closing_note'] ?? ''}'.isNotEmpty) {
      line('Catatan: ${shift['closing_note']}');
    }
    line('-' * width);
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

  static Map<String, dynamic> defaultTemplate() => {
    'header_text': '',
    'footer_text': 'Simpan sebagai bukti transaksi',
    'show_address': true,
    'show_cashier': true,
    'show_discount': true,
    'show_logo': false,
    'logo_base64': null,
  };

  static Map<String, dynamic> normalizeTemplate(Map<String, dynamic>? raw) {
    final d = defaultTemplate();
    if (raw == null) return d;
    return {
      'header_text': '${raw['header_text'] ?? ''}'.trim(),
      'footer_text': raw['footer_text'] is String
          ? (raw['footer_text'] as String).trim()
          : d['footer_text'],
      'show_address': raw['show_address'] != false,
      'show_cashier': raw['show_cashier'] != false,
      'show_discount': raw['show_discount'] != false,
      'show_logo': raw['show_logo'] == true,
      'logo_base64': raw['logo_base64'] is String && (raw['logo_base64'] as String).isNotEmpty
          ? raw['logo_base64']
          : null,
    };
  }

  Future<Map<String, dynamic>> load() async {
    final data = await storage.read(key) ?? {'profile': null, 'jobs': <String, dynamic>{}};
    return {
      ...data,
      'template': normalizeTemplate(
        (data['template'] as Map?)?.cast<String, dynamic>(),
      ),
    };
  }

  Future<void> saveTemplate(Map<String, dynamic> template) async {
    final clean = normalizeTemplate(template);
    if ((clean['header_text'] as String).length > 160 ||
        (clean['footer_text'] as String).length > 240) {
      throw const FormatException('Teks header maksimal 160 dan footer maksimal 240 karakter.');
    }
    if (clean['logo_base64'] is String) {
      final raw = base64Decode(clean['logo_base64'] as String);
      if (raw.length > 512 * 1024) {
        throw const FormatException('Ukuran logo struk maksimal 512 KB.');
      }
      if (img.decodeImage(raw) == null) {
        throw const FormatException('File logo struk harus berupa gambar PNG/JPEG yang valid.');
      }
    }
    await storage.update(key, (s) {
      s ??= {'jobs': <String, dynamic>{}};
      s['template'] = clean;
      return s;
    });
  }
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
        template: (s['template'] as Map?)?.cast<String, dynamic>(),
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

  Future<void> printShiftSummary(
    Map<String, dynamic> shift, {
    String? storeName,
  }) async {
    final current = await load();
    final profile = current['profile'] as Map<String, dynamic>?;
    if (profile == null) {
      throw StateError('Profil printer belum disimpan.');
    }
    final paper = profile['paper'] as int? ?? 58;
    final bytes = EscPosReceipt.encodeShiftSummary(
      shift,
      paper,
      storeName: storeName,
      cut: profile['cut'] == true,
    );
    final connection =
        transport ??
        (profile['transport'] == 'bluetooth'
            ? BluetoothPrinterTransport()
            : TcpEscPosTransport());
    await connection.send(profile['host'], profile['port'], bytes);
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
