import 'package:flutter/material.dart';
import '../application/stock_control_controller.dart';
import '../domain/stock_batch.dart';
import '../../../design_system/molecules/stock_number_field.dart';

class StockActionForm extends StatefulWidget {
  const StockActionForm({required this.model, required this.batch, super.key});
  final StockControlController model;
  final StockBatch batch;
  @override
  State<StockActionForm> createState() => _StockActionFormState();
}

class _StockActionFormState extends State<StockActionForm> {
  final quantity = TextEditingController(),
      output = TextEditingController(),
      reason = TextEditingController();
  String kind = 'stocktake', bucket = 'opened';
  bool get processing => kind == 'open' || kind == 'cook';
  String get label => switch (kind) {
    'open' => 'Buka kemasan',
    'cook' => 'Kukus',
    'waste' => 'Ajukan susut',
    _ => 'Ajukan opname',
  };
  @override
  void dispose() {
    quantity.dispose();
    output.dispose();
    reason.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    final b = widget.batch;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Tinjau: $label'),
        content: SingleChildScrollView(
          child: Text(
            'Batch ${b.code} · Revisi ${b.revision}\n${kind == 'open'
                ? 'Kemasan'
                : processing
                ? 'Input biji'
                : 'Fisik tersisa ($bucket)'}: ${quantity.text}\n${kind == 'cook' ? 'Hasil aktual: ${output.text} biji\n' : ''}Alasan: ${reason.text}\n${processing ? 'Catat hanya proses yang benar-benar dilakukan.' : 'Pengajuan belum mengubah saldo sampai ditinjau pengguna lain.'}',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Kembali'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Konfirmasi pencatatan'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) {
      return;
    }
    if (processing) {
      await widget.model.process(
        kind: kind,
        quantity: quantity.text,
        output: output.text,
        reason: reason.text,
      );
    } else {
      await widget.model.propose(
        purpose: kind,
        bucket: bucket,
        counted: quantity.text,
        reason: reason.text,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final enabled = widget.model.canAct;
    final b = widget.batch;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Snapshot terpilih: ${b.code} · Revisi ${b.revision}',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            Text(
              'Utuh ${b.usable} · Terbuka ${b.opened} · Siap ${b.ready} · Karantina ${b.quarantined} biji',
            ),
            Text(
              'Isi kemasan historis ${b.packSize} biji. Hasil kukus wajib berdasarkan hitungan fisik.',
            ),
            DropdownButtonFormField<String>(
              key: const Key('stock-kind'),
              initialValue: kind,
              decoration: const InputDecoration(labelText: 'Tindakan'),
              items: [
                if (b.condition == 'frozen' &&
                    widget.model.context?.canProcess == true) ...const [
                  DropdownMenuItem(value: 'open', child: Text('Buka kemasan')),
                  DropdownMenuItem(value: 'cook', child: Text('Kukus')),
                ],
                if (widget.model.context?.canPropose == true) ...const [
                  DropdownMenuItem(
                    value: 'stocktake',
                    child: Text('Ajukan opname'),
                  ),
                  DropdownMenuItem(value: 'waste', child: Text('Ajukan susut')),
                ],
              ],
              onChanged: enabled
                  ? (value) {
                      setState(() {
                        kind = value!;
                        quantity.clear();
                        output.clear();
                        reason.clear();
                      });
                    }
                  : null,
            ),
            if (!processing)
              DropdownButtonFormField<String>(
                key: const Key('stock-bucket'),
                initialValue: bucket,
                decoration: const InputDecoration(
                  labelText: 'Keadaan yang dihitung',
                ),
                items: const [
                  DropdownMenuItem(value: 'usable', child: Text('Utuh')),
                  DropdownMenuItem(
                    value: 'opened',
                    child: Text('Beku terbuka'),
                  ),
                  DropdownMenuItem(value: 'ready', child: Text('Siap jual')),
                  DropdownMenuItem(
                    value: 'quarantined',
                    child: Text('Karantina'),
                  ),
                ],
                onChanged: enabled
                    ? (value) => setState(() {
                        bucket = value!;
                        quantity.clear();
                      })
                    : null,
              ),
            StockNumberField(
              key: const Key('stock-quantity'),
              label: kind == 'open'
                  ? 'Jumlah kemasan dibuka'
                  : processing
                  ? 'Input biji beku terbuka'
                  : 'Jumlah fisik tersisa (biji)',
              controller: quantity,
              enabled: enabled,
            ),
            if (kind == 'cook')
              StockNumberField(
                key: const Key('stock-output'),
                label: 'Hasil aktual (biji, boleh 0)',
                controller: output,
                enabled: enabled,
              ),
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: TextField(
                key: const Key('stock-reason'),
                controller: reason,
                enabled: enabled,
                maxLength: 2000,
                decoration: const InputDecoration(
                  labelText: 'Alasan / catatan fisik',
                ),
              ),
            ),
            FilledButton(
              onPressed: enabled ? submit : null,
              child: const Text('Tinjau pencatatan'),
            ),
          ],
        ),
      ),
    );
  }
}
