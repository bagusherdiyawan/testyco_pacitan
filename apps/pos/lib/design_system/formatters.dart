String formatRupiah(int value) =>
    'Rp ${value.toString().replaceAllMapped(RegExp(r'([0-9])(?=([0-9]{3})+(?![0-9]))'), (m) => '${m[1]}.')}';
String formatWib(String raw) {
  final d = DateTime.parse(raw).toUtc().add(const Duration(hours: 7));
  String pad(int value) => value.toString().padLeft(2, '0');
  return '${d.year}-${pad(d.month)}-${pad(d.day)} ${pad(d.hour)}:${pad(d.minute)} WIB';
}
