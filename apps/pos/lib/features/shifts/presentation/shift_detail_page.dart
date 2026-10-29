import 'package:flutter/material.dart';
import '../../../design_system/formatters.dart';
import '../../../design_system/molecules/cash_movement_review.dart';
import '../../../design_system/organisms/shift_cash_summary.dart';
import '../application/cash_movement_controller.dart';
import '../data/shifts_repository.dart';
import '../domain/cash_shift.dart';

class ShiftDetailPage extends StatefulWidget {
  const ShiftDetailPage({
    required this.repository,
    required this.storeId,
    required this.id,
    super.key,
  });
  final ShiftsRepository repository;
  final String storeId, id;
  @override
  State<ShiftDetailPage> createState() => _ShiftDetailPageState();
}

class _ShiftDetailPageState extends State<ShiftDetailPage> {
  late final CashMovementController model;
  final amount = TextEditingController(),
      category = TextEditingController(),
      reason = TextEditingController();
  String kind = 'out';
  int _epoch = 0;
  @override
  void initState() {
    super.initState();
    model = CashMovementController(widget.repository, widget.storeId, widget.id)
      ..addListener(_changed);
    model.load();
  }

  void _changed() {
    if (_epoch != model.formEpoch) {
      _epoch = model.formEpoch;
      amount.clear();
      category.clear();
      reason.clear();
    }
  }

  @override
  void dispose() {
    model.removeListener(_changed);
    model.dispose();
    amount.dispose();
    category.dispose();
    reason.dispose();
    super.dispose();
  }

  Widget review(CashMovementDraft draft) {
    final v = draft.body;
    return CashMovementReview(
      kind: v['kind'] as String,
      amount: v['amount'] as int,
      category: v['category'] as String,
      reason: v['reason'] as String,
      version: v['version'] as int,
    );
  }

  Future<void> confirm() async {
    try {
      final draft = model.prepare(
        kind,
        amount.text,
        category.text,
        reason.text,
      );
      final accepted = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Tinjau catatan kas'),
          content: SingleChildScrollView(child: review(draft)),
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
      if (accepted == true && mounted) {
        await model.confirm(draft);
        _feedback();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('$e')));
      }
    }
  }

  void _feedback() {
    if (!mounted) return;
    final message = model.savedMovement != null
        ? 'Catatan kas tersimpan.'
        : model.error;
    if (message != null) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(message)));
    }
  }

  Future<void> retry() async {
    await model.retry();
    _feedback();
  }

  Widget field(
    String label,
    TextEditingController controller, {
    bool number = false,
    int? maxLength,
  }) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: TextField(
      controller: controller,
      enabled: model.editable,
      maxLength: maxLength,
      decoration: InputDecoration(labelText: label),
      keyboardType: number ? TextInputType.number : TextInputType.text,
    ),
  );
  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) {
      final s = model.shift;
      return PopScope(
        canPop: !model.busy && model.pending == null,
        child: Scaffold(
          appBar: AppBar(
            title: const Text('Kas shift'),
            actions: [
              IconButton(
                tooltip: 'Muat ulang shift dan kosongkan isian',
                onPressed: model.busy || model.pending != null
                    ? null
                    : model.load,
                icon: const Icon(Icons.refresh),
              ),
            ],
          ),
          body: ListView(
            key: const Key('shift-detail-scroll'),
            padding: const EdgeInsets.all(16),
            children: [
              if (model.busy) const LinearProgressIndicator(),
              if (model.error != null)
                Text(
                  model.error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              if (model.savedMovement != null)
                Text('Catatan kas tersimpan · ${model.savedMovement!['id']}'),
              if (s != null) ...[
                Text(
                  '${s.cashier} · ${s.device}',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                Text('Shift ${s.id} · ${s.status} · versi ${s.version}'),
                Text('Dibuka ${s.openedAt}'),
                ShiftCashSummary(
                  opening: s.opening,
                  sales: s.sales,
                  cashIn: s.cashIn,
                  cashOut: s.cashOut,
                  expected: s.expected,
                ),
                if (s.status == 'closed') ...[
                  Text(
                    'Kas fisik ${formatRupiah(s.counted ?? 0)} · Selisih ${formatRupiah(s.difference ?? 0)}',
                  ),
                  if (s.closingNote != null) Text(s.closingNote!),
                ],
              ],
              if (model.pending != null) ...[
                const Text(
                  'Hasil pencatatan belum diketahui. Pertahankan halaman dan ulangi operasi yang sama.',
                ),
                review(model.pending!),
                FilledButton(
                  onPressed: model.busy ? null : retry,
                  child: const Text('Ulangi pencatatan yang sama'),
                ),
              ] else if (s?.canRecord == true && model.fresh) ...[
                DropdownButtonFormField<String>(
                  initialValue: kind,
                  decoration: const InputDecoration(
                    labelText: 'Jenis catatan kas',
                  ),
                  items: const [
                    DropdownMenuItem(value: 'out', child: Text('Kas keluar')),
                    DropdownMenuItem(value: 'in', child: Text('Kas masuk')),
                  ],
                  onChanged: model.editable
                      ? (v) => setState(() => kind = v!)
                      : null,
                ),
                field('Nilai kas (Rp)', amount, number: true),
                field('Kategori', category, maxLength: 80),
                field('Alasan', reason, maxLength: 2000),
                FilledButton(
                  onPressed: model.editable ? confirm : null,
                  child: const Text('Tinjau catatan kas'),
                ),
              ] else if (s != null)
                const Text(
                  'Pencatatan tidak tersedia. Periksa status, izin, dan muat ulang setelah kas berubah.',
                ),
              if (s != null) ...[
                const SizedBox(height: 16),
                Text(
                  'Riwayat kas manual',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                if (s.movements.isEmpty)
                  const Text('Belum ada catatan kas manual.'),
                for (final m in s.movements)
                  Card(
                    child: ListTile(
                      title: Text(
                        '${m['kind'] == 'in' ? 'Masuk' : 'Keluar'} ${formatRupiah(m['amount'] as int)} · ${m['category']}',
                      ),
                      subtitle: Text(
                        '${m['reason']}\n${m['created_at']} · Pelaku ${m['actor_id']} · ${m['id']}',
                      ),
                    ),
                  ),
              ],
              const Text(
                'Pending hanya disimpan dalam memori halaman. Setelah restart atau sesi berakhir, periksa riwayat sebelum mencatat pengganti. Belum tersedia antrean offline.',
              ),
            ],
          ),
        ),
      );
    },
  );
}
