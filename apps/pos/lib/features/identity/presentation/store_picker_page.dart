import 'package:flutter/material.dart';
import 'password_change_page.dart';
import '../application/session.dart';
import '../domain/store.dart';
import '../../../design_system/molecules/error_panel.dart';

class StorePickerPage extends StatefulWidget {
  const StorePickerPage({
    required this.session,
    required this.onSelected,
    super.key,
  });
  final Session session;
  final ValueChanged<AssignedStore> onSelected;
  @override
  State<StorePickerPage> createState() => _StorePickerPageState();
}

class _StorePickerPageState extends State<StorePickerPage> {
  late Future<List<AssignedStore>> _stores;
  @override
  void initState() {
    super.initState();
    _stores = _load();
  }

  Future<List<AssignedStore>> _load() async {
    final stores = <AssignedStore>[];
    String? path = 'stores';
    while (path != null) {
      final response = await widget.session.api.request('GET', path);
      stores.addAll(
        (response['data'] as List).map(
          (j) => AssignedStore.fromJson(j as Map<String, dynamic>),
        ),
      );
      // Never forward bearer tokens to a server-provided URL: only use its page number.
      final next = response['next_page_url'] as String?;
      final page = next == null
          ? null
          : Uri.parse(next).queryParameters['page'];
      path = page == null ? null : 'stores?page=$page';
    }
    return stores;
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const Text('Pilih toko'),
      actions: [
        PasswordChangeAction(session: widget.session),
        TextButton(
          onPressed: () async {
            try {
              await widget.session.logout();
            } catch (_) {
              /* Local session cleared. */
            }
          },
          child: const Text('Keluar'),
        ),
      ],
    ),
    body: FutureBuilder<List<AssignedStore>>(
      future: _stores,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return ErrorPanel(
            message: snapshot.error.toString(),
            onRetry: () => setState(() {
              _stores = _load();
            }),
          );
        }
        final stores = snapshot.data!;
        if (stores.isEmpty) {
          return ErrorPanel(
            message:
                'Belum ada toko yang ditugaskan. Hubungi pengelola organisasi.',
            onRetry: () => setState(() {
              _stores = _load();
            }),
          );
        }
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            for (final store in stores)
              Card(
                child: ListTile(
                  title: Text(store.name),
                  subtitle: Text(store.active ? 'Aktif' : 'Nonaktif'),
                  trailing: const Icon(Icons.chevron_right),
                  enabled: store.active,
                  onTap: () => widget.onSelected(store),
                ),
              ),
          ],
        );
      },
    ),
  );
}
