import 'package:flutter/material.dart';
import '../application/recipient_order_controller.dart';
import '../domain/recipient_order.dart';
import '../../../design_system/molecules/shipment_receipt_fields.dart';

class _ReceiptFields {
  final good = TextEditingController(),
      damaged = TextEditingController(),
      note = TextEditingController();
  ReceiptLineInput get input =>
      ReceiptLineInput(good: good.text, damaged: damaged.text, note: note.text);
  void dispose() {
    good.dispose();
    damaged.dispose();
    note.dispose();
  }
}

class ShipmentReceiptForm extends StatefulWidget {
  const ShipmentReceiptForm({
    required this.model,
    required this.shipment,
    required this.version,
    super.key,
  });
  final RecipientOrderController model;
  final ReceiptShipment shipment;
  final int version;
  @override
  State<ShipmentReceiptForm> createState() => _ShipmentReceiptFormState();
}

class _ShipmentReceiptFormState extends State<ShipmentReceiptForm> {
  late final Map<String, _ReceiptFields> fields;
  final note = TextEditingController();
  @override
  void initState() {
    super.initState();
    fields = {
      for (final line in widget.shipment.lines) line.id: _ReceiptFields(),
    };
  }

  @override
  void dispose() {
    for (final f in fields.values) {
      f.dispose();
    }
    note.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    final inputs = fields.map((key, value) => MapEntry(key, value.input));
    final receiptNote = note.text;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Tinjau penerimaan aktual'),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Versi pesanan ${widget.version}'),
              for (final line in widget.shipment.lines)
                Text(
                  'Batch ${line.batch} · dikirim ${line.sent} kemasan × ${line.packSize} biji\nBaik ${inputs[line.id]!.good} · Rusak ${inputs[line.id]!.damaged} kemasan\nKondisi: ${inputs[line.id]!.note}',
                ),
              Text('Catatan penerimaan: $receiptNote'),
              const Text(
                'Isi berdasarkan barang fisik. Barang rusak masuk karantina; jumlah kirim bukan bukti penerimaan. Selisih tidak membuat pengganti atau kredit otomatis.',
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Kembali'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Konfirmasi penerimaan'),
          ),
        ],
      ),
    );
    if (confirmed == true && mounted) {
      await widget.model.receive(
        widget.shipment,
        widget.version,
        inputs,
        receiptNote,
      );
    }
  }

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      const Text(
        'Hitung barang fisik dan isi semua baris, termasuk angka 0. Baik/rusak tidak diisi otomatis. Catatan per batch wajib jika ada kerusakan atau selisih.',
      ),
      for (final line in widget.shipment.lines)
        ShipmentReceiptFields(
          key: ValueKey('receipt-line-${line.id}'),
          batch: line.batch,
          expiry: line.expiry,
          sent: line.sent,
          packSize: line.packSize,
          good: fields[line.id]!.good,
          damaged: fields[line.id]!.damaged,
          note: fields[line.id]!.note,
          enabled: widget.model.canAct,
        ),
      TextField(
        key: const Key('receipt-note'),
        controller: note,
        enabled: widget.model.canAct,
        maxLength: 2000,
        decoration: const InputDecoration(
          labelText: 'Catatan penerimaan umum (opsional)',
        ),
      ),
      FilledButton(
        onPressed: widget.model.canAct ? submit : null,
        child: const Text('Tinjau penerimaan'),
      ),
    ],
  );
}
