import 'dart:typed_data';

abstract class PrinterTransport {
  Future<void> send(String host, int port, Uint8List bytes);
}
