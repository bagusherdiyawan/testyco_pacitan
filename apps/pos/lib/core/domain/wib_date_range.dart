class WibDateRange {
  const WibDateRange(this.from, this.to);
  final DateTime from, to;
  static String day(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
  static DateTime parseDay(String input) {
    final d = DateTime.tryParse(input);
    if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(input) ||
        d == null ||
        day(d) != input) {
      throw const FormatException(
        'Isi tanggal kalender YYYY-MM-DD yang valid.',
      );
    }
    return DateTime.utc(d.year, d.month, d.day);
  }

  factory WibDateRange.form(String from, String to) {
    final start = parseDay(from.trim()), end = parseDay(to.trim());
    final days = end.difference(start).inDays + 1;
    if (days < 1 || days > 31) {
      throw const FormatException('Rentang harus 1 sampai 31 hari.');
    }
    return WibDateRange(start, end);
  }
  factory WibDateRange.today() {
    final now = DateTime.now().toUtc().add(const Duration(hours: 7));
    final day = DateTime.utc(now.year, now.month, now.day);
    return WibDateRange(day, day);
  }
  Map<String, String> get params => {
    'from': '${day(from)}T00:00:00+07:00',
    'to': '${day(to.add(const Duration(days: 1)))}T00:00:00+07:00',
  };
}
