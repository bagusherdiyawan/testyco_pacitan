import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:testyco_pos/core/storage/device_state.dart';
import 'package:testyco_pos/features/printing/application/bluetooth_printer.dart';
import 'package:testyco_pos/features/printing/application/receipt_printer.dart';
import 'package:testyco_pos/features/printing/presentation/receipt_print_page.dart';
import 'receipt_printer_test.dart' as fixtures;

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  const channel = MethodChannel('testyco/receipt_bluetooth');
  final messenger =
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
  tearDown(() => messenger.setMockMethodCallHandler(channel, null));
  test(
    'Bluetooth sends frozen ESC POS bytes and never retries unknown job',
    () async {
      final sent = <MethodCall>[];
      var fail = true;
      messenger.setMockMethodCallHandler(channel, (call) async {
        sent.add(call);
        if (fail) {
          throw PlatformException(code: 'timeout');
        }
        return true;
      });
      final p = ReceiptPrinter(MemoryDeviceState(), 'actor');
      await p.configure({
        'transport': 'bluetooth',
        'host': 'AA:BB:CC:DD:EE:FF',
        'port': 0,
        'paper': 58,
        'cut': false,
      });
      await expectLater(p.printSale(fixtures.sale()), throwsStateError);
      expect(sent.length, 1);
      expect(sent.single.method, 'send');
      expect(sent.single.arguments['device_id'], 'AA:BB:CC:DD:EE:FF');
      expect(
        sent.single.arguments['bytes'],
        EscPosReceipt.encode(fixtures.sale(), 58, copy: false),
      );
      await expectLater(p.printSale(fixtures.sale()), throwsStateError);
      expect(sent.length, 1);
      final job = (await p.load())['jobs'] as Map;
      await p.confirm(job.keys.single, false);
      fail = false;
      await p.printSale(fixtures.sale());
      expect(sent.length, 2);
      expect((await p.load())['jobs'].values.last['state'], 'sent_unconfirmed');
    },
  );
  test('Paired devices validated; native false ACK is uncertain', () async {
    final bluetooth = BluetoothPrinterTransport();
    messenger.setMockMethodCallHandler(
      channel,
      (call) async => call.method == 'pairedDevices'
          ? [
              {'id': 'AA:BB:CC:DD:EE:FF', 'name': 'Thermal'},
            ]
          : false,
    );
    expect((await bluetooth.pairedDevices()).single['name'], 'Thermal');
    await expectLater(
      bluetooth.send('AA:BB:CC:DD:EE:FF', 0, Uint8List.fromList([27, 64])),
      throwsStateError,
    );
    messenger.setMockMethodCallHandler(
      channel,
      (call) async => [
        {'id': 'https://bad', 'name': 'Foreign'},
      ],
    );
    await expectLater(bluetooth.pairedDevices(), throwsFormatException);
    expect(
      () => ReceiptPrinter.validateProfile({
        'transport': 'bluetooth',
        'host': '192.168.1.1',
        'port': 0,
        'paper': 58,
        'cut': false,
      }),
      throwsFormatException,
    );
  });
  for (final width in [390.0, 1200.0]) {
    testWidgets('Bluetooth pairing selection and configuration at $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 1800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      messenger.setMockMethodCallHandler(
        channel,
        (call) async => [
          {'id': 'AA:BB:CC:DD:EE:FF', 'name': 'Printer Kasir'},
        ],
      );
      final p = ReceiptPrinter(MemoryDeviceState(), 'actor');
      await tester.pumpWidget(
        MaterialApp(
          home: ReceiptPrintPage(printer: p, sale: fixtures.sale()),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Bluetooth Android'), findsOneWidget);
      await tester.tap(find.text('Muat perangkat yang sudah dipasangkan'));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>).last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Printer Kasir (AA:BB:CC:DD:EE:FF)').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<int>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('58 mm').last);
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Simpan konfigurasi printer'));
      await tester.tap(find.text('Simpan konfigurasi printer'));
      await tester.pumpAndSettle();
      expect((await p.load())['profile']['transport'], 'bluetooth');
      expect((await p.load())['profile']['host'], 'AA:BB:CC:DD:EE:FF');
      expect(tester.takeException(), isNull);
    });
  }
}
