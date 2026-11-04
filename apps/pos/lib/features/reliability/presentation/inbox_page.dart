import 'package:flutter/material.dart';
import '../../../core/network/api_client.dart';

class InboxPage extends StatefulWidget {
  const InboxPage({required this.api, super.key});
  final ApiClient api;
  @override
  State<InboxPage> createState() => _InboxPageState();
}

class _InboxPageState extends State<InboxPage> {
  Map<String, dynamic>? data;
  String? error;
  bool busy = false;
  int page = 1;
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load([int? selected]) async {
    if (busy) return;
    setState(() {
      busy = true;
      error = null;
      data = null;
    });
    try {
      final n = selected ?? page;
      final c = await widget.api.request('GET', 'inbox?page=$n');
      if (c['data'] is! List ||
          c['current_page'] != n ||
          c['per_page'] != 50 ||
          c['total'] is! int ||
          c['preferences'] is! Map ||
          (widget.api.identity?['id'] != null &&
              c['user_id'] != widget.api.identity!['id'])) {
        throw const ApiException('Cakupan inbox tidak valid.');
      }
      for (final r in c['data']) {
        if (r['id'] is! String ||
            r['title'] is! String ||
            r['organization_id'] is! String) {
          throw const ApiException('Baris inbox tidak valid.');
        }
      }
      data = c;
      page = n;
    } catch (e) {
      error = '$e';
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> act(Future<void> Function() f) async {
    if (busy || data == null) return;
    setState(() => busy = true);
    try {
      await f();
    } catch (e) {
      error = '$e';
    } finally {
      if (mounted) setState(() => busy = false);
    }
    if (error == null) await load();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const Text('Inbox operasional'),
      actions: [
        IconButton(
          tooltip: 'Muat ulang inbox',
          onPressed: busy ? null : load,
          icon: const Icon(Icons.refresh),
        ),
      ],
    ),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (busy) const LinearProgressIndicator(),
        if (error != null) Text(error!),
        if (data case final c?) ...[
          Text('${c['unread_count']} belum dibaca'),
          Text(
            c['smtp_configured'] == true
                ? 'Pengiriman email melalui antrean SMTP.'
                : 'SMTP belum aktif; inbox tetap tersedia.',
          ),
          SwitchListTile(
            value: c['preferences']['email_enabled'] as bool,
            title: const Text(
              'Saya mengizinkan email notifikasi ke email akun',
            ),
            onChanged: busy
                ? null
                : (enabled) => act(() async {
                    final d = (await widget.api
                        .request('POST', 'inbox/preferences', {
                          'version': c['preferences']['version'],
                          'email_enabled': enabled,
                        }))['data'];
                    if (d is! Map ||
                        d['version'] != c['preferences']['version'] + 1 ||
                        d['email_enabled'] != enabled) {
                      throw const ApiException(
                        'ACK pilihan kanal tidak sesuai. Muat ulang status.',
                      );
                    }
                  }),
          ),
          for (final r in c['data'])
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      r['title'] as String,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    Text(
                      '${r['created_at']} · ${r['read_at'] == null ? 'Belum dibaca' : 'Sudah dibaca'}',
                    ),
                    const Text(
                      'Tinjau melalui modul pesanan, tagihan, stok atau aset sesuai hak akses Anda.',
                    ),
                    if (r['read_at'] == null)
                      TextButton(
                        onPressed: busy
                            ? null
                            : () => act(() async {
                                final d = (await widget.api.request(
                                  'POST',
                                  'inbox/${Uri.encodeComponent(r['id'])}/read',
                                ))['data'];
                                if (d is! Map ||
                                    d['id'] != r['id'] ||
                                    d['user_id'] != c['user_id']) {
                                  throw const ApiException(
                                    'ACK inbox tidak sesuai.',
                                  );
                                }
                              }),
                        child: const Text('Tandai dibaca'),
                      ),
                  ],
                ),
              ),
            ),
          Wrap(
            spacing: 12,
            children: [
              Text('Halaman $page/${c['last_page']}'),
              TextButton(
                onPressed: busy || page <= 1 ? null : () => load(page - 1),
                child: const Text('Sebelumnya'),
              ),
              TextButton(
                onPressed: busy || page >= c['last_page']
                    ? null
                    : () => load(page + 1),
                child: const Text('Berikutnya'),
              ),
            ],
          ),
        ],
      ],
    ),
  );
}
