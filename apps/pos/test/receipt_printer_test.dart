import 'package:flutter/material.dart';
import 'package:testyco_pos/features/printing/presentation/receipt_print_page.dart';
import 'dart:typed_data';
import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:testyco_pos/core/storage/device_state.dart';
import 'package:testyco_pos/features/printing/application/receipt_printer.dart';

Map<String, dynamic> sale() => {
  'id': 'SALE',
  'number': 'POS-1',
  'currency': 'IDR',
  'status': 'completed',
  'store_name': 'Pacitan\x1b\x70',
  'store_address': 'Street',
  'cashier_name': 'Kasir',
  'sold_at': '2026-11-04T10:00:00Z',
  'lines': [
    {
      'sku': 'AYAM',
      'name': 'Ayam',
      'unit': 'piece',
      'quantity': 2,
      'unit_price': 3000,
      'line_total': 6000,
    },
  ],
  'subtotal': 6000,
  'discount_amount': 1000,
  'total_amount': 5000,
  'cash_received': 10000,
  'change_amount': 5000,
  'payment_method': 'cash',
};

class FakePrinter implements PrinterTransport {
  bool fail = false;
  final sent = <Uint8List>[];
  @override
  Future<void> send(String host, int port, Uint8List bytes) async {
    sent.add(bytes);
    if (fail) throw const SocketException('lost result');
  }
}

void main() {
  test(
    'Thermal encoder preserves snapshot amounts and WIB, sanitizes commands and labels copies',
    () {
      for (final size in [58, 80]) {
        final b = EscPosReceipt.encode(sale(), size, copy: true);
        final text = ascii.decode(b);
        expect(text, contains('TOTAL    Rp5000'));
        expect(text, contains('Diskon   Rp1000'));
        expect(text, contains('17:00:00 WIB'));
        expect(text, contains('SALINAN'));
        expect(text, contains('Pacitan?p'));
        expect(text.contains('\x1bp'), isFalse);
        expect(text.contains('\x1dv'), isFalse); // no drawer/cut by default
      }
      expect(
        () => EscPosReceipt.encode(
          {...sale(), 'number': 'OFF-local'},
          58,
          copy: false,
        ),
        throwsFormatException,
      );
    },
  );
  test(
    'Lost result survives reopen, blocks automatic retry and explicit next print is a copy',
    () async {
      final storage = MemoryDeviceState(),
          transport = FakePrinter()..fail = true;
      var printer = ReceiptPrinter(storage, 'user1', transport: transport);
      await printer.configure({
        'host': '192.168.1.10',
        'port': 9100,
        'paper': 58,
        'cut': false,
      });
      await expectLater(printer.printSale(sale()), throwsStateError);
      printer = ReceiptPrinter(storage, 'user1', transport: transport);
      await expectLater(printer.printSale(sale()), throwsStateError);
      expect(transport.sent.length, 1);
      final jobs = (await printer.load())['jobs'] as Map;
      expect(jobs.values.single['state'], 'unknown');
      await printer.confirm(jobs.keys.single, false);
      transport.fail = false;
      final id = await printer.printSale(sale());
      expect(ascii.decode(transport.sent.last), contains('SALINAN'));
      await expectLater(printer.printSale(sale()), throwsStateError);
      await printer.confirm(id, true);
      expect((await ReceiptPrinter(storage, 'user2').load())['jobs'], isEmpty);
    },
  );
  test(
    'TCP transport sends actual ESC POS bytes to a loopback printer fixture',
    () async {
      final server = await ServerSocket.bind(InternetAddress.loopbackIPv4, 0);
      addTearDown(server.close);
      final received = server.first.then((socket) async {
        final bytes = await socket.fold<List<int>>([], (a, b) => a..addAll(b));
        socket.destroy();
        return bytes;
      });
      final bytes = EscPosReceipt.encode(sale(), 80, copy: false, cut: true);
      await TcpEscPosTransport().send('127.0.0.1', server.port, bytes);
      expect(await received, bytes);
    },
  );

  test(
    'Archiving keeps encrypted-store history and marks subsequent prints conservatively as copies',
    () async {
      final storage = MemoryDeviceState(), transport = FakePrinter();
      final p = ReceiptPrinter(storage, 'actor', transport: transport);
      await p.configure({
        'host': '192.168.1.10',
        'port': 9100,
        'paper': 58,
        'cut': false,
      });
      final id = await p.printSale(sale());
      await p.confirm(id, true);
      await p.archiveChecked();
      final state = await p.load();
      expect(state['jobs'], isEmpty);
      expect(state['archives'].length, 1);
      expect(storage.values.length, 2);
      await p.printSale({...sale(), 'id': 'SECOND', 'number': 'POS-2'});
      expect(ascii.decode(transport.sent.last), contains('SALINAN'));
    },
  );
  for (final width in [390.0, 1200.0]) {
    testWidgets('Printer page sends one frozen job and renders at $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 1400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final transport = FakePrinter(),
          p = ReceiptPrinter(
            MemoryDeviceState(),
            'actor',
            transport: transport,
          );
      await p.configure({
        'host': '192.168.1.10',
        'port': 9100,
        'paper': 58,
        'cut': false,
      });
      await tester.pumpWidget(
        MaterialApp(
          home: ReceiptPrintPage(printer: p, sale: sale()),
        ),
      );
      await tester.pumpAndSettle();
      final button = find.text('Cetak struk / salinan');
      await tester.ensureVisible(button);
      await tester.tap(button);
      await tester.pumpAndSettle();
      expect(transport.sent.length, 1);
      expect(
        (await p.load())['jobs'].values.single['state'],
        'sent_unconfirmed',
      );
      expect(tester.takeException(), isNull);
    });
  }
}
