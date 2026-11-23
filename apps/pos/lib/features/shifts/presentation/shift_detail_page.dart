import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';
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
      reason = TextEditingController(),
      evidence = TextEditingController(),
      decisionNote = TextEditingController();
  String kind = 'out';
  String? requestCategory;
  bool requestBusy = false;
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
    evidence.dispose();
    decisionNote.dispose();
    super.dispose();
  }

  Future<void> submitRequest(CashShift s) async {
    final n = int.tryParse(amount.text.trim());
    final cat = (requestCategory ?? s.movementCategories.first).trim();
    if (n == null || n <= 0 || reason.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Isi nominal positif dan alasan pengajuan.')),
      );
      return;
    }
    setState(() => requestBusy = true);
    try {
      await widget.repository.requestMovement(s.id, {
        'operation_id': const Uuid().v4(),
        'kind': kind,
        'amount': n,
        'category': cat,
        'reason': reason.text.trim(),
        if (evidence.text.trim().isNotEmpty)
          'evidence_reference': evidence.text.trim(),
      });
      amount.clear();
      reason.clear();
      evidence.clear();
      await model.load();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Pengajuan kas dikirim ke pengelola.')),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('$e')));
      }
    } finally {
      if (mounted) setState(() => requestBusy = false);
    }
  }

  Future<void> decideRequest(
    Map<String, dynamic> req,
    String action, {
    String noteText = '',
  }) async {
    setState(() => requestBusy = true);
    try {
      await widget.repository.decideRequest(req['id'] as String, action, {
        'operation_id': const Uuid().v4(),
        'version': req['version'],
        if (noteText.trim().isNotEmpty) 'decision_note': noteText.trim(),
      });
      await model.load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('$e')));
      }
    } finally {
      if (mounted) setState(() => requestBusy = false);
    }
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
                  blindClose: s.blindClose,
                ),
                if (s.noncashSummary != null)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Text(
                      'Non-tunai: QRIS ${s.noncashSummary!['qris_count']}x (${formatRupiah((s.noncashSummary!['qris_amount'] as int?) ?? 0)}) · Transfer ${s.noncashSummary!['transfer_count']}x (${formatRupiah((s.noncashSummary!['transfer_amount'] as int?) ?? 0)})',
                    ),
                  ),
                if (s.status == 'closed') ...[
                  Text(
                    'Kas fisik ${formatRupiah(s.counted ?? 0)} · Selisih ${formatRupiah(s.difference ?? 0)}',
                  ),
                  if (s.depositCash != null || s.retainedCash != null)
                    Text(
                      'Setoran ${formatRupiah(s.depositCash ?? 0)} · Sisa modal ${formatRupiah(s.retainedCash ?? s.counted ?? 0)}',
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
              ] else if (s != null && s.canRequest) ...[
                Text(
                  'Ajukan kas masuk/keluar (butuh persetujuan pengelola)',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                DropdownButtonFormField<String>(
                  initialValue: kind,
                  decoration: const InputDecoration(labelText: 'Jenis pengajuan'),
                  items: const [
                    DropdownMenuItem(value: 'out', child: Text('Kas keluar')),
                    DropdownMenuItem(value: 'in', child: Text('Kas masuk')),
                  ],
                  onChanged: requestBusy
                      ? null
                      : (v) => setState(() => kind = v!),
                ),
                DropdownButtonFormField<String>(
                  initialValue: requestCategory ?? s.movementCategories.first,
                  decoration: const InputDecoration(labelText: 'Kategori'),
                  items: [
                    for (final c in s.movementCategories)
                      DropdownMenuItem(value: c, child: Text(c)),
                  ],
                  onChanged: requestBusy
                      ? null
                      : (v) => setState(() => requestCategory = v),
                ),
                TextField(
                  controller: amount,
                  enabled: !requestBusy,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(labelText: 'Nilai kas (Rp)'),
                ),
                TextField(
                  controller: reason,
                  enabled: !requestBusy,
                  decoration: const InputDecoration(labelText: 'Alasan & kebutuhan'),
                ),
                TextField(
                  controller: evidence,
                  enabled: !requestBusy,
                  decoration: const InputDecoration(
                    labelText: 'Nomor nota / bukti (opsional)',
                  ),
                ),
                FilledButton(
                  onPressed: requestBusy ? null : () => submitRequest(s),
                  child: const Text('Kirim pengajuan kas'),
                ),
              ] else if (s != null)
                const Text(
                  'Pencatatan tidak tersedia. Periksa status, izin, dan muat ulang setelah kas berubah.',
                ),
              if (s != null && s.movementRequests.isNotEmpty) ...[
                const SizedBox(height: 16),
                Text(
                  'Daftar pengajuan kas',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                for (final req in s.movementRequests)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            '${req['kind'] == 'in' ? 'Masuk' : 'Keluar'} ${formatRupiah(req['amount'] as int)} · ${req['category']} (${req['status']})',
                          ),
                          Text('${req['reason']}'),
                          if (req['status'] == 'pending')
                            Wrap(
                              spacing: 8,
                              children: [
                                if (s.canManage &&
                                    req['requested_by'] !=
                                        widget.repository.api.identity?['id']) ...[
                                  TextButton(
                                    onPressed: requestBusy
                                        ? null
                                        : () => decideRequest(req, 'approve'),
                                    child: const Text('Setujui'),
                                  ),
                                  TextButton(
                                    onPressed: requestBusy
                                        ? null
                                        : () => decideRequest(
                                            req,
                                            'reject',
                                            noteText: 'Ditolak pengelola',
                                          ),
                                    child: const Text('Tolak'),
                                  ),
                                ] else if (s.isOwner)
                                  TextButton(
                                    onPressed: requestBusy
                                        ? null
                                        : () => decideRequest(req, 'cancel'),
                                    child: const Text('Batalkan pengajuan'),
                                  ),
                              ],
                            ),
                        ],
                      ),
                    ),
                  ),
              ],
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
