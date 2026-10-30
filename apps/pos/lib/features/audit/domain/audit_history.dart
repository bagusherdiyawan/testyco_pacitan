import '../../../core/domain/wib_date_range.dart';

class AuditFilter {
  const AuditFilter(
    this.range, {
    this.action = '',
    this.subject = '',
    this.actor = '',
  });
  final WibDateRange range;
  final String action, subject, actor;
  factory AuditFilter.form(
    String from,
    String to,
    String action,
    String subject,
    String actor,
  ) {
    final a = action.trim(), s = subject.trim(), u = actor.trim();
    if (a.length > 80 ||
        s.length > 80 ||
        (u.isNotEmpty &&
            (!RegExp(r'^[0-9]+$').hasMatch(u) || (int.tryParse(u) ?? 0) < 1))) {
      throw const FormatException(
        'Aksi dan objek maksimal 80 karakter; ID pelaku harus bilangan bulat positif.',
      );
    }
    return AuditFilter(
      WibDateRange.form(from, to),
      action: a,
      subject: s,
      actor: u,
    );
  }
  Map<String, String> get params => {
    ...range.params,
    if (action.isNotEmpty) 'action': action,
    if (subject.isNotEmpty) 'subject_id': subject,
    if (actor.isNotEmpty) 'actor_id': actor,
  };
}

class AuditRecord {
  AuditRecord.fromJson(Map<String, dynamic> j)
    : id = j['id'] as String,
      organization = j['organization_id'] as String,
      action = j['action'] as String,
      subject = j['subject_id'] as String,
      actor = j['actor_id'] as int?,
      occurredAt = j['occurred_at'] as String,
      before = j['before'],
      after = j['after'];
  final String id, organization, action, subject, occurredAt;
  final int? actor;
  final Object? before, after;
}

class AuditHistory {
  const AuditHistory(
    this.rows,
    this.name,
    this.visibility,
    this.observedAt,
    this.page,
    this.lastPage,
  );
  final List<AuditRecord> rows;
  final String name, visibility, observedAt;
  final int page, lastPage;
}
