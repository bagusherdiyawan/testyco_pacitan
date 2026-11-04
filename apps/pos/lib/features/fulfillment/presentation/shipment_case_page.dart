import 'package:flutter/material.dart';
import '../../../design_system/organisms/shipment_case_card.dart';
import '../application/shipment_case_controller.dart';
import '../data/shipment_case_repository.dart';

const caseActions = {
  'report': 'Laporkan selisih',
  'note': 'Tambah catatan',
  'propose': 'Usulkan rencana',
  'confirm': 'Konfirmasi rencana',
  'reject': 'Tolak rencana',
  'withdraw': 'Tarik rencana',
  'dispatch': 'Kirim pengganti',
  'receive': 'Terima pengganti',
};

class ShipmentCasePage extends StatefulWidget {
  const ShipmentCasePage({required this.repository, super.key});
  final ShipmentCaseRepository repository;
  @override
  State<ShipmentCasePage> createState() => _ShipmentCasePageState();
}

class _ShipmentCasePageState extends State<ShipmentCasePage> {
  late final ShipmentCaseController model;
  @override
  void initState() {
    super.initState();
    model = ShipmentCaseController(widget.repository);
    model.load();
  }

  @override
  void dispose() {
    model.dispose();
    super.dispose();
  }

  Future<void> act(String action, {String? subject}) async {
    final fields = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => _CaseForm(
        action: action,
        data: model.context!,
        subject: subject,
        returns: widget.repository.returns,
      ),
    );
    if (fields == null || !mounted) return;
    try {
      final op = model.prepare(action, fields, subject: subject);
      final ok = await showDialog<bool>(
        context: context,
        builder: (_) => AlertDialog(
          title: Text('Tinjau ${caseActions[action]}'),
          content: SingleChildScrollView(child: _OperationReview(op: op)),
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
      if (ok == true && mounted) await model.confirm(op);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('$e')));
      }
    }
  }

  Widget button(String action, {String? subject}) => OutlinedButton(
    onPressed: model.editable ? () => act(action, subject: subject) : null,
    child: Text(
      widget.repository.returns
          ? (action == 'dispatch'
                ? 'Kirim retur karantina'
                : 'Terima retur ke karantina')
          : caseActions[action]!,
    ),
  );
  Widget pager(Map c, {bool plans = false}) {
    final page =
            (plans ? c['resolutions']['current_page'] : c['current_page'])
                as int,
        last = (plans ? c['resolutions']['last_page'] : c['last_page']) as int;
    void load(int p) => model.load(
      page: plans ? c['current_page'] : p,
      plansPage: plans ? p : (c['resolutions']?['current_page'] ?? 1),
    );
    return Wrap(
      spacing: 12,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Text('${plans ? 'Rencana' : 'Riwayat'} $page/$last'),
        TextButton(
          onPressed: model.editable && page > 1 ? () => load(page - 1) : null,
          child: const Text('Sebelumnya'),
        ),
        TextButton(
          onPressed: model.editable && page < last
              ? () => load(page + 1)
              : null,
          child: const Text('Berikutnya'),
        ),
      ],
    );
  }

  Future<void> replacements({bool returns = false}) async {
    final r = widget.repository;
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => ShipmentCasePage(
          repository: ShipmentCaseRepository(
            r.api,
            r.order,
            r.shipment,
            replacements: !returns,
            returns: returns,
          ),
        ),
      ),
    );
    if (mounted) await model.load();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) => PopScope(
      canPop: !model.busy && model.pending == null,
      child: Scaffold(
        appBar: AppBar(
          title: Text(
            widget.repository.physical
                ? (widget.repository.returns
                      ? 'Retur karantina'
                      : 'Barang pengganti')
                : 'Kasus selisih kiriman',
          ),
          actions: [
            IconButton(
              tooltip: 'Muat ulang',
              onPressed: model.busy || model.pending != null
                  ? null
                  : () => model.load(),
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
        body: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (model.busy) const LinearProgressIndicator(),
            if (model.error != null)
              Text(
                model.error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            const Text(
              'Owner/admin pusat mengusulkan, pimpinan mitra mengonfirmasi. Catatan dibaca kedua pihak yang berwenang.',
            ),
            if (model.pending case final op?) ...[
              const Text(
                'Hasil belum pasti. Pertahankan halaman dan ulangi request yang sama; jangan buat pengganti.',
              ),
              _OperationReview(op: op),
              FilledButton(
                onPressed: model.busy ? null : model.retry,
                child: const Text('Ulangi request yang sama'),
              ),
            ] else if (model.context case final c?) ...[
              if (!widget.repository.physical) ...[
                if (c['snapshot'] != null)
                  ShipmentCaseCard(
                    data: Map<String, dynamic>.from(c['snapshot']),
                  ),
                if (c['case'] != null)
                  ShipmentCaseCard(data: Map<String, dynamic>.from(c['case'])),
                Wrap(
                  spacing: 8,
                  children: [
                    for (final a in ['report', 'note', 'propose'])
                      if (c['actions'][a == 'propose'
                              ? 'propose_resolution'
                              : a] ==
                          true)
                        button(a),
                  ],
                ),
                if (c['pending_resolution'] case final p?) ...[
                  ShipmentCaseCard(
                    data: Map<String, dynamic>.from(p),
                    plan: true,
                  ),
                  Wrap(
                    spacing: 8,
                    children: [
                      for (final a in ['confirm', 'reject', 'withdraw'])
                        if (c['actions']['${a}_resolution'] == true)
                          button(a, subject: p['id']),
                    ],
                  ),
                ],
                const Text('Rencana yang sudah diputuskan'),
                for (final p in c['resolutions']['data']) ...[
                  ShipmentCaseCard(
                    data: Map<String, dynamic>.from(p),
                    plan: true,
                  ),
                  if (p['status'] == 'confirmed' &&
                      ['replacement', 'return'].contains(p['type']))
                    OutlinedButton(
                      onPressed: model.editable
                          ? () => replacements(returns: p['type'] == 'return')
                          : null,
                      child: Text(
                        p['type'] == 'return'
                            ? 'Buka retur karantina'
                            : 'Buka pengiriman dan penerimaan pengganti',
                      ),
                    ),
                ],
                pager(c, plans: true),
                const Text('Catatan bersama'),
                for (final n in c['data'])
                  ShipmentCaseCard(data: Map<String, dynamic>.from(n)),
                pager(c),
              ] else ...[
                Text(
                  widget.repository.returns
                      ? 'Retur ke karantina · ${c['execution_status']}'
                      : {
                              'ready': 'Siap dikirim',
                              'in_transit': 'Dalam pengiriman',
                              'partially_fulfilled': 'Sebagian diterima baik',
                              'requires_follow_up':
                                  'Perlu tindak lanjut: pengganti kurang/rusak',
                              'fulfilled': 'Penggantian selesai diterima baik',
                            }[c['execution_status']] ??
                            'Status belum dikenal',
                ),
                ShipmentCaseCard(
                  data: Map<String, dynamic>.from(c['resolution']),
                  plan: true,
                ),
                Text(
                  widget.repository.returns
                      ? 'Hanya barang rusak fisik di karantina, tujuan karantina gudang asal. Tidak membuat barang layak jual.'
                      : 'Kuota berkurang dari seluruh jumlah dikirim. Kerusakan/kehilangan baru tidak menambah kuota. Barang rusak awal tetap karantina.',
                ),
                for (final q in c['quota'])
                  Text(
                    '${q['sku']} → ${q['local_sku']} · Isi ${q['pack_size']} · Batas ${q['limit_packs']} · Kirim ${q[widget.repository.returns ? 'return_sent_packs' : 'replacement_sent_packs']} · Terima ${q[widget.repository.returns ? 'return_received_packs' : 'replacement_good_packs']} · Sisa ${q['remaining_packs']}',
                  ),
                if (c['can_dispatch'] == true) button('dispatch'),
                for (final s in c['data']) ...[
                  ShipmentCaseCard(data: Map<String, dynamic>.from(s)),
                  if (s['can_receive'] == true)
                    button('receive', subject: s['id']),
                ],
                pager(c),
              ],
            ],
            const SizedBox(height: 16),
            const Text(
              'Online; pending tersimpan di memori halaman, bukan antrean tahan restart. Setelah aplikasi ditutup, rekonsiliasi riwayat sebelum membuat request lain.',
            ),
          ],
        ),
      ),
    ),
  );
}

class _OperationReview extends StatelessWidget {
  const _OperationReview({required this.op});
  final CaseOperation op;
  @override
  Widget build(BuildContext context) {
    final r = op.review, b = op.body;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Versi ditinjau ${b['version'] ?? b['order_version']}'),
        if (r['snapshot'] != null)
          ShipmentCaseCard(data: Map<String, dynamic>.from(r['snapshot'])),
        if (r['pending_resolution'] != null)
          ShipmentCaseCard(
            data: Map<String, dynamic>.from(r['pending_resolution']),
            plan: true,
          ),
        for (final key in [
          'reason',
          'message',
          'proposal',
          'reference',
          'handler',
          'note',
        ])
          if (b[key] != null)
            Text(
              '${{'reason': 'Alasan', 'message': 'Catatan', 'proposal': 'Rencana', 'reference': 'Referensi', 'handler': 'Petugas', 'note': 'Catatan'}[key]}: ${b[key]}',
            ),
        if (b['type'] != null) Text('Jenis ${casePlanTypes[b['type']]}'),
        for (final l in b['lines'] ?? [])
          if (op.action == 'dispatch') ...[
            for (final a in l['allocations'])
              Text(
                'Batch ${a['batch_id']} · Kirim ${a['quantity_packs']} kemasan',
              ),
          ] else
            Text(
              '${l.containsKey('received_packs') ? 'Karantina ${l['received_packs']}' : 'Baik ${l['good_packs']} · Rusak ${l['damaged_packs']}'} · ${l['condition_note'] ?? ''}',
            ),
        if (['dispatch', 'receive'].contains(op.action))
          const Text(
            'Konfirmasi akan membukukan perubahan stok fisik. Tidak membukukan kredit/invoice.',
          ),
      ],
    );
  }
}

class _CaseForm extends StatefulWidget {
  const _CaseForm({
    required this.action,
    required this.data,
    this.subject,
    this.returns = false,
  });
  final bool returns;
  final String action;
  final Map<String, dynamic> data;
  final String? subject;
  @override
  State<_CaseForm> createState() => _CaseFormState();
}

class _CaseFormState extends State<_CaseForm> {
  final values = <String, String>{}, batches = <String, String>{};
  String type = 'replacement';
  String? error;
  Widget field(
    String key,
    String label, {
    bool number = false,
    int max = 2000,
  }) => TextField(
    key: ValueKey(key),
    onChanged: (v) => values[key] = v,
    maxLength: number ? null : max,
    keyboardType: number ? TextInputType.number : TextInputType.multiline,
    decoration: InputDecoration(labelText: label),
  );
  int integer(String key) {
    final raw = values[key]?.trim() ?? '';
    if (!RegExp(r'^\d+$').hasMatch(raw)) {
      throw 'Isi jumlah bulat, termasuk 0 secara eksplisit.';
    }
    final n = int.parse(raw);
    if (n > 10000) throw 'Maksimal 10000 kemasan per baris.';
    return n;
  }

  void submit() {
    try {
      final a = widget.action;
      final result = <String, dynamic>{};
      final requiredKey = {
        'report': 'reason',
        'note': 'message',
        'propose': 'proposal',
        'reject': 'reason',
        'withdraw': 'reason',
        'dispatch': 'handler',
      }[a];
      if (requiredKey != null) {
        final text = (values[requiredKey] ?? '').trim();
        if (text.isEmpty) throw 'Isi teks wajib.';
        result[requiredKey] = text;
      }
      for (final key in ['reason', 'reference', 'note']) {
        if ((values[key] ?? '').trim().isNotEmpty) {
          result[key] = values[key]!.trim();
        }
      }
      if (a == 'propose') result['type'] = type;
      if (a == 'dispatch') {
        final lines = <Map<String, dynamic>>[];
        for (final q in widget.data['quota']) {
          final id = q['shipment_line_id'] as String;
          if ((values['qty:$id'] ?? '').trim().isEmpty) continue;
          final n = integer('qty:$id');
          if (n == 0) continue;
          if (!batches.containsKey(id)) throw 'Pilih batch sumber.';
          lines.add({
            'original_shipment_line_id': id,
            'allocations': [
              {'batch_id': batches[id], 'quantity_packs': n},
            ],
          });
        }
        result['lines'] = lines;
      }
      if (a == 'receive') {
        final s = (widget.data['data'] as List).firstWhere(
          (v) => v['id'] == widget.subject,
        );
        result['lines'] = [
          for (final l in s['lines'])
            {
              if (widget.returns) ...{
                'return_line_id': l['id'],
                'received_packs': integer('good:${l['id']}'),
              } else ...{
                'replacement_line_id': l['id'],
                'good_packs': integer('good:${l['id']}'),
                'damaged_packs': integer('damage:${l['id']}'),
              },
              'condition_note': values['condition:${l['id']}']?.trim(),
            },
        ];
      }
      Navigator.pop(context, result);
    } catch (e) {
      setState(() => error = '$e');
    }
  }

  @override
  Widget build(BuildContext context) {
    final a = widget.action, c = widget.data;
    return AlertDialog(
      title: Text(
        widget.returns
            ? (a == 'dispatch'
                  ? 'Kirim retur karantina'
                  : 'Terima retur ke karantina')
            : caseActions[a]!,
      ),
      content: SingleChildScrollView(
        child: SizedBox(
          width: 520,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (error != null) Text(error!),
              if (['report', 'reject', 'withdraw', 'confirm'].contains(a))
                field(
                  'reason',
                  a == 'confirm'
                      ? 'Catatan keputusan (opsional)'
                      : 'Alasan wajib',
                ),
              if (a == 'note') field('message', 'Catatan bersama wajib'),
              if (a == 'propose') ...[
                DropdownButtonFormField<String>(
                  initialValue: type,
                  items: [
                    for (final t in casePlanTypes.entries)
                      DropdownMenuItem(value: t.key, child: Text(t.value)),
                  ],
                  onChanged: (v) => setState(() => type = v!),
                ),
                field('proposal', 'Rencana untuk dikonfirmasi pimpinan mitra'),
              ],
              if (['report', 'note', 'propose'].contains(a))
                field('reference', 'Referensi bukti teks (opsional)', max: 500),
              if (a == 'dispatch') ...[
                field('handler', 'Petugas pengiriman wajib', max: 120),
                for (final q in c['quota'])
                  if (q['remaining_packs'] > 0) ...[
                    Text(
                      '${q['sku']} · Sisa ${q['remaining_packs']} kemasan isi ${q['pack_size']}',
                    ),
                    DropdownButtonFormField<String>(
                      isExpanded: true,
                      decoration: const InputDecoration(
                        labelText: 'Batch sumber',
                      ),
                      items: [
                        for (final b in c['batches'])
                          if (b['product_id'] ==
                                  q[widget.returns
                                      ? 'allocation_product_id'
                                      : 'supplier_product_id'] &&
                              b['pack_size'] == q['pack_size'])
                            DropdownMenuItem(
                              value: b['id'] as String,
                              child: Text(
                                '${b['batch_code']} · ${b[widget.returns ? 'quarantined_pieces' : 'usable_pieces']} biji',
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                      ],
                      onChanged: (v) => batches[q['shipment_line_id']] = v!,
                    ),
                    field(
                      'qty:${q['shipment_line_id']}',
                      'Kemasan dikirim (kosong/0 dilewati)',
                      number: true,
                    ),
                  ],
                field('note', 'Catatan pengiriman'),
              ],
              if (a == 'receive') ...[
                for (final s in c['data'])
                  if (s['id'] == widget.subject)
                    for (final l in s['lines']) ...[
                      Text(
                        '${l['local_sku']} · Batch ${l['batch_code']} · Kirim ${l['quantity_packs']} isi ${l['pack_size']}',
                      ),
                      field(
                        'good:${l['id']}',
                        widget.returns
                            ? 'Kemasan diterima karantina (isi 0 bila tidak ada)'
                            : 'Kemasan baik (0 jika tidak ada)',
                        number: true,
                      ),
                      if (!widget.returns)
                        field(
                          'damage:${l['id']}',
                          'Kemasan rusak (0 jika tidak ada)',
                          number: true,
                        ),
                      field('condition:${l['id']}', 'Alasan jika rusak/kurang'),
                    ],
                field('note', 'Catatan penerimaan'),
              ],
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Batal'),
        ),
        FilledButton(
          onPressed: submit,
          child: const Text('Tinjau sebelum kirim'),
        ),
      ],
    );
  }
}
