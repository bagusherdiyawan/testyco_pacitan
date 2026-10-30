import 'package:flutter/material.dart';
import '../../../core/domain/wib_date_range.dart';
import '../../../design_system/formatters.dart';
import '../../../design_system/molecules/audit_record_card.dart';
import '../../../design_system/molecules/error_panel.dart';
import '../application/audit_controller.dart';
import '../data/audit_repository.dart';
import '../domain/audit_history.dart';

class AuditPage extends StatefulWidget {
  const AuditPage({
    required this.repository,
    required this.organization,
    super.key,
  });
  final AuditRepository repository;
  final String organization;
  @override
  State<AuditPage> createState() => _AuditPageState();
}

class _AuditPageState extends State<AuditPage> {
  late final AuditController model;
  late final TextEditingController from, to;
  final action = TextEditingController(),
      subject = TextEditingController(),
      actor = TextEditingController();
  String? formError;
  @override
  void initState() {
    super.initState();
    model = AuditController(widget.repository, widget.organization);
    from = TextEditingController(
      text: WibDateRange.day(model.filter.range.from),
    );
    to = TextEditingController(text: WibDateRange.day(model.filter.range.to));
    model.load();
  }

  @override
  void dispose() {
    model.dispose();
    for (final c in [from, to, action, subject, actor]) {
      c.dispose();
    }
    super.dispose();
  }

  void apply() {
    try {
      final f = AuditFilter.form(
        from.text,
        to.text,
        action.text,
        subject.text,
        actor.text,
      );
      setState(() => formError = null);
      model.load(next: f);
    } catch (e) {
      setState(() => formError = 'Filter belum diterapkan: $e');
    }
  }

  Widget field(String label, TextEditingController c) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: TextField(
      controller: c,
      enabled: !model.busy,
      decoration: InputDecoration(labelText: label),
    ),
  );
  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: model,
    builder: (context, _) {
      final result = model.result;
      return ListView(
        key: const Key('audit-scroll'),
        padding: const EdgeInsets.all(16),
        children: [
          Text('Jejak audit', style: Theme.of(context).textTheme.titleLarge),
          const Text(
            'Riwayat organisasi, bukan hanya toko yang sedang dipilih. Gunakan ID objek untuk menelusuri perubahan.',
          ),
          ExpansionTile(
            title: const Text('Filter audit'),
            children: [
              field('Tanggal awal WIB (YYYY-MM-DD)', from),
              field('Tanggal akhir WIB (YYYY-MM-DD)', to),
              field('Aksi persis (opsional)', action),
              field('ID objek persis (opsional)', subject),
              field('ID pelaku (opsional)', actor),
              FilledButton(
                onPressed: model.busy ? null : apply,
                child: const Text('Terapkan filter'),
              ),
            ],
          ),
          if (formError != null) Text(formError!),
          Text(
            'Periode diterapkan: ${WibDateRange.day(model.filter.range.from)} — ${WibDateRange.day(model.filter.range.to)} WIB',
          ),
          if (model.filter.action.isNotEmpty)
            Text('Aksi diterapkan: ${model.filter.action}'),
          if (model.filter.subject.isNotEmpty)
            Text('Objek diterapkan: ${model.filter.subject}'),
          if (model.filter.actor.isNotEmpty)
            Text('Pelaku diterapkan: ${model.filter.actor}'),
          TextButton.icon(
            onPressed: model.busy ? null : () => model.load(),
            icon: const Icon(Icons.refresh),
            label: const Text('Muat ulang audit'),
          ),
          if (model.busy) const LinearProgressIndicator(),
          if (model.error != null)
            ErrorPanel(message: model.error!, onRetry: () => model.load()),
          if (result != null) ...[
            Text(
              'Organisasi ${result.name} · ${result.visibility == 'onboarding' ? 'Hanya data onboarding' : 'Audit organisasi'}',
            ),
            Text('Diamati ${formatWib(result.observedAt)}'),
            if (result.rows.isEmpty)
              const Text('Tidak ada audit pada filter ini.'),
            for (final row in result.rows)
              AuditRecordCard(
                id: row.id,
                action: row.action,
                subject: row.subject,
                actor: row.actor == null
                    ? 'Pelaku tidak tersedia'
                    : 'Pelaku #${row.actor}',
                time: formatWib(row.occurredAt),
                before: row.before,
                after: row.after,
              ),
            Wrap(
              spacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                TextButton(
                  onPressed: model.busy || result.page <= 1
                      ? null
                      : () => model.load(nextPage: result.page - 1),
                  child: const Text('Sebelumnya'),
                ),
                Text('Halaman ${result.page} / ${result.lastPage}'),
                TextButton(
                  onPressed: model.busy || result.page >= result.lastPage
                      ? null
                      : () => model.load(nextPage: result.page + 1),
                  child: const Text('Berikutnya'),
                ),
              ],
            ),
          ],
        ],
      );
    },
  );
}
