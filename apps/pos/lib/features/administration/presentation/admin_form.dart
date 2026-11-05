import 'dart:convert';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import '../../../core/network/api_client.dart';
import '../application/admin_mutation.dart';

class AdminField {
  const AdminField(
    this.key,
    this.label, {
    this.kind = 'text',
    this.options,
    this.optional = false,
  });
  final String key, label, kind;
  final Map<String, String>? options;
  final bool optional;
}

AdminField textField(String key, String label) => AdminField(key, label);
AdminField numberField(String key, String label) =>
    AdminField(key, label, kind: 'integer');
AdminField dateField(String key, String label) =>
    AdminField(key, '$label (YYYY-MM-DD)', kind: 'date');
AdminField choiceField(
  String key,
  String label,
  Map<String, String> choices, {
  bool optional = false,
}) => AdminField(key, label, options: choices, optional: optional);
AdminField multiField(
  String key,
  String label,
  Map<String, String> choices, {
  bool optional = false,
}) =>
    AdminField(key, label, kind: 'multi', options: choices, optional: optional);
const consentField = AdminField(
  'consent',
  'Saya sudah membaca dan menyetujui dokumen ini',
  kind: 'consent',
);
const reasonField = AdminField('reason', 'Alasan / catatan keputusan');

/// Shared atomic form/review surface; each feature supplies its own fields and ACK guard.
class AdminForm extends StatefulWidget {
  const AdminForm({
    required this.api,
    required this.title,
    required this.path,
    required this.fields,
    required this.accept,
    this.fixed = const {},
    this.contextText = '',
    this.transform,
    super.key,
  });
  final ApiClient api;
  final String title, path, contextText;
  final List<AdminField> fields;
  final Map<String, dynamic> fixed;
  final Map<String, dynamic> Function(Map<String, dynamic>)? transform;
  final bool Function(Map<String, dynamic>) accept;
  @override
  State<AdminForm> createState() => _AdminFormState();
}

class _AdminFormState extends State<AdminForm> {
  final form = GlobalKey<FormState>();
  final values = <String, dynamic>{};
  AdminMutation? pending;
  bool busy = false, reviewed = false;
  String? error;
  @override
  void initState() {
    super.initState();
    for (final f in widget.fields) {
      values[f.key] = f.kind == 'multi'
          ? <String>[]
          : f.kind == 'consent'
          ? false
          : null;
    }
  }

  void review() {
    if (!form.currentState!.validate()) return;
    try {
      final payload = <String, dynamic>{...widget.fixed};
      for (final f in widget.fields) {
        final v = values[f.key];
        payload[f.key] = f.kind == 'integer' ? int.parse(v) : v;
      }
      pending = AdminMutation(
        widget.api,
        widget.path,
        widget.transform?.call(payload) ?? payload,
        widget.accept,
      );
      setState(() {
        reviewed = true;
        error = null;
      });
    } catch (e) {
      setState(() => error = '$e');
    }
  }

  Future<void> send() async {
    if (busy || pending == null) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await pending!.send();
      if (mounted) Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) {
        setState(() {
          error = '$e';
          if (!pending!.uncertain) {
            reviewed = false;
            pending = null;
          }
        });
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Widget input(AdminField f) {
    if (f.kind == 'pdf') {
      return TextButton.icon(
        icon: const Icon(Icons.attach_file),
        label: Text(
          values[f.key] == null ? f.label : 'PDF dipilih (maksimal 5 MiB)',
        ),
        onPressed: () async {
          if (busy) return;
          setState(() {
            busy = true;
            error = null;
          });
          try {
            final result = await FilePicker.platform.pickFiles(
              type: FileType.custom,
              allowedExtensions: ['pdf'],
              withData: false,
              withReadStream: true,
            );
            if (result == null) return;
            final file = result.files.single;
            if (file.size > 5 * 1024 * 1024 || file.readStream == null) {
              throw const FormatException('Pilih PDF maksimal 5 MiB.');
            }
            final bytes = <int>[];
            await for (final chunk in file.readStream!.timeout(
              const Duration(seconds: 15),
            )) {
              if (bytes.length + chunk.length > 5 * 1024 * 1024) {
                throw const FormatException('PDF melebihi 5 MiB.');
              }
              bytes.addAll(chunk);
            }
            if (bytes.length < 5 ||
                ascii.decode(bytes.sublist(0, 5), allowInvalid: true) !=
                    '%PDF-') {
              throw const FormatException('Pilih PDF maksimal 5 MiB.');
            }
            if (mounted) setState(() => values[f.key] = base64Encode(bytes));
          } catch (e) {
            if (mounted) setState(() => error = '$e');
          } finally {
            if (mounted) setState(() => busy = false);
          }
        },
      );
    }
    if (f.kind == 'consent') {
      return FormField<bool>(
        validator: (_) =>
            values[f.key] == true ? null : 'Persetujuan eksplisit wajib.',
        builder: (s) => Column(
          children: [
            CheckboxListTile(
              value: values[f.key] == true,
              title: Text(f.label),
              onChanged: (v) => setState(() => values[f.key] = v),
            ),
            if (s.hasError)
              Text(
                s.errorText!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
          ],
        ),
      );
    }
    if (f.kind == 'multi') {
      return FormField<List<String>>(
        validator: (_) => f.optional || (values[f.key] as List).isNotEmpty
            ? null
            : 'Pilih setidaknya satu.',
        builder: (s) => Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(f.label),
            for (final option in f.options!.entries)
              CheckboxListTile(
                title: Text(option.value),
                value: (values[f.key] as List).contains(option.key),
                onChanged: (v) => setState(() {
                  final list = values[f.key] as List;
                  v == true ? list.add(option.key) : list.remove(option.key);
                }),
              ),
            if (s.hasError) Text(s.errorText!),
          ],
        ),
      );
    }
    if (f.options != null) {
      return DropdownButtonFormField<String>(
        isExpanded: true,
        decoration: InputDecoration(labelText: f.label),
        items: [
          if (f.optional)
            const DropdownMenuItem(value: '', child: Text('Tidak dipakai')),
          ...f.options!.entries.map(
            (e) => DropdownMenuItem(
              value: e.key,
              child: Text(e.value, overflow: TextOverflow.ellipsis),
            ),
          ),
        ],
        onChanged: (v) => values[f.key] = v == '' ? null : v,
        validator: (v) => f.optional || (v != null && v.isNotEmpty)
            ? null
            : 'Pilih ${f.label}.',
      );
    }
    return TextFormField(
      decoration: InputDecoration(labelText: f.label),
      maxLines: f.kind == 'text' ? 3 : 1,
      keyboardType: f.kind == 'integer'
          ? TextInputType.number
          : TextInputType.text,
      onChanged: (v) => values[f.key] = v.trim(),
      validator: (v) {
        if (v == null || v.trim().isEmpty) {
          return f.optional ? null : 'Wajib diisi.';
        }
        if (f.kind == 'integer' &&
            (!RegExp(r'^\d+$').hasMatch(v.trim()) ||
                int.tryParse(v.trim()) == null)) {
          return 'Isi bilangan bulat, termasuk nol bila sesuai.';
        }
        if (f.kind == 'date') {
          final parsed = DateTime.tryParse(v.trim());
          if (parsed == null ||
              !RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(v.trim()) ||
              parsed.toIso8601String().substring(0, 10) != v.trim()) {
            return 'Tanggal tidak valid.';
          }
        }
        return null;
      },
    );
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: !busy && pending?.uncertain != true,
    child: Scaffold(
      appBar: AppBar(title: Text(widget.title)),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 800),
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              if (widget.contextText.isNotEmpty)
                SelectableText(widget.contextText),
              if (error != null)
                Text(
                  error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              if (busy) const LinearProgressIndicator(),
              if (!reviewed) ...[
                Form(
                  key: form,
                  child: Column(
                    children: [
                      for (final f in widget.fields)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: input(f),
                        ),
                    ],
                  ),
                ),
                FilledButton(
                  onPressed: busy ? null : review,
                  child: const Text('Tinjau sebelum mengirim'),
                ),
              ] else ...[
                const Text(
                  'Periksa kembali data berikut. Perubahan berlaku setelah server mengonfirmasi.',
                ),
                for (final f in widget.fields)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: Text('${f.label}: ${display(f)}'),
                  ),
                if (pending?.uncertain == true)
                  const Text(
                    'Hasil belum pasti. Jangan membuat permintaan baru. Periksa riwayat server setelah konfirmasi. Ulangi hanya permintaan yang sama; layar ini harus tetap terbuka sampai hasil diketahui.',
                  ),
                FilledButton(
                  onPressed: busy ? null : send,
                  child: Text(
                    pending?.uncertain == true
                        ? 'Periksa / kirim ulang permintaan yang sama'
                        : 'Konfirmasi dan kirim',
                  ),
                ),
                if (pending?.uncertain != true)
                  TextButton(
                    onPressed: busy
                        ? null
                        : () => setState(() {
                            reviewed = false;
                            pending = null;
                          }),
                    child: const Text('Ubah data'),
                  ),
              ],
            ],
          ),
        ),
      ),
    ),
  );
  String display(AdminField f) {
    final v = values[f.key];
    if (f.kind == 'pdf') return v == null ? 'Tanpa lampiran' : 'PDF terlampir';
    if (f.kind == 'multi') {
      return (v as List).map((id) => f.options![id]).join(', ');
    }
    if (f.kind == 'consent') return v == true ? 'Setuju' : 'Belum setuju';
    return f.options?[v] ?? v?.toString() ?? 'Tidak dipakai';
  }
}
