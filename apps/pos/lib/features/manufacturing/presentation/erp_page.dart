import 'package:flutter/material.dart';
import '../../../design_system/molecules/document_summary.dart';
import '../../administration/presentation/admin_widgets.dart';
import '../data/erp_repository.dart';

class ErpPage extends StatefulWidget {
  const ErpPage({
    required this.repository,
    this.operatorStore,
    this.initialSection = 'materials',
    this.financeOnly = false,
    super.key,
  });
  final ErpRepository repository;
  final String? operatorStore;
  final String initialSection;
  final bool financeOnly;
  @override
  State<ErpPage> createState() => _ErpPageState();
}

class _ErpPageState extends State<ErpPage> {
  String section = 'materials';
  String? store;
  int page = 1;
  final from = TextEditingController(), to = TextEditingController();
  Map<String, String> filters = {};
  late Future<Map<String, dynamic>> future;
  @override
  void initState() {
    super.initState();
    section = widget.initialSection;
    if (widget.operatorStore != null) {
      section = "productions";
      store = widget.operatorStore;
      filters = {"store_id": store!};
    }
    reload();
  }

  @override
  void dispose() {
    from.dispose();
    to.dispose();
    super.dispose();
  }

  void reload() {
    setState(() {
      future = widget.repository.load(section, page, filters);
    });
  }

  Future<void> edit(Map<String, dynamic> form, String snapshot) async {
    final repo = widget.repository;
    if (await openForm(
      context,
      repo.api,
      form['label'],
      'organizations/${repo.organization}/erp/${form['operation']}',
      ErpRepository.fields(form),
      (r) {
        final d = r['data'];
        return d is Map &&
            d['organization_id'] == repo.organization &&
            d['id'] is String &&
            d['version'] is int &&
            (form['expected_id'] == null || d['id'] == form['expected_id']) &&
            (form['expected_version'] == null ||
                d['version'] == form['expected_version']);
      },
      fixed: ErpRepository.object(form['fixed']),
      contextText: snapshot,
    )) {
      reload();
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const Text('Bahan baku dan produksi'),
      actions: [
        IconButton(
          onPressed: reload,
          icon: const Icon(Icons.refresh),
          tooltip: 'Muat ulang',
        ),
      ],
    ),
    body: Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 1000),
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            const Text(
              'Hasil produksi masuk karantina sampai diperiksa owner/admin pusat. Jumlah sesuai satuan bahan; biaya rupiah diisi eksplisit.',
            ),
            DropdownButtonFormField<String>(
              initialValue: section,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Bagian ERP'),
              items: ErpRepository.sections.entries
                  .where(
                    (e) =>
                        (widget.operatorStore == null ||
                            {
                              'productions',
                              'lots',
                              'recipes',
                            }.contains(e.key)) &&
                        (!widget.financeOnly ||
                            {
                              'accounts',
                              'periods',
                              'journals',
                              'trial_balance',
                              'posting',
                              'posting_events',
                              'valuations',
                              'valuation_stock',
                              'cost_ledger',
                            }.contains(e.key)),
                  )
                  .map(
                    (e) => DropdownMenuItem(
                      value: e.key,
                      child: Text(e.value, overflow: TextOverflow.ellipsis),
                    ),
                  )
                  .toList(),
              onChanged: (v) {
                section = v!;
                page = 1;
                filters = widget.operatorStore == null
                    ? {}
                    : {'store_id': widget.operatorStore!};
                store = widget.operatorStore;
                reload();
              },
            ),
            FutureBuilder<Map<String, dynamic>>(
              future: future,
              builder: (context, s) {
                if (s.connectionState != ConnectionState.done) {
                  return const LinearProgressIndicator();
                }
                if (s.hasError) return Text('${s.error}');
                final c = s.data!,
                    stores = ErpRepository.object(s.data!['filter_stores']);
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    DropdownButtonFormField<String>(
                      key: ValueKey('$section:$store'),
                      initialValue: store,
                      isExpanded: true,
                      decoration: const InputDecoration(labelText: 'Gudang'),
                      items: [
                        if (widget.operatorStore == null)
                          const DropdownMenuItem(
                            value: '',
                            child: Text('Semua gudang'),
                          ),
                        ...stores.entries.map(
                          (e) => DropdownMenuItem(
                            value: e.key,
                            child: Text('${e.value}'),
                          ),
                        ),
                      ],
                      onChanged: (v) {
                        store = v == '' ? null : v;
                        filters = {...filters};
                        filters.remove('store_id');
                        if (store != null) filters['store_id'] = store!;
                        page = 1;
                        reload();
                      },
                    ),
                    if ({'journals', 'trial_balance'}.contains(section))
                      DropdownButtonFormField<String>(
                        initialValue: filters['period_id'],
                        isExpanded: true,
                        decoration: const InputDecoration(
                          labelText: 'Periode pembukuan',
                        ),
                        items: ErpRepository.object(c['filter_periods']).entries
                            .map(
                              (e) => DropdownMenuItem(
                                value: e.key,
                                child: Text('${e.value}'),
                              ),
                            )
                            .toList(),
                        onChanged: (v) {
                          filters = {...filters, 'period_id': v!};
                          page = 1;
                          reload();
                        },
                      ),
                    if (section == 'report') ...[
                      TextField(
                        controller: from,
                        decoration: const InputDecoration(
                          labelText: 'Dari WIB (YYYY-MM-DD)',
                        ),
                      ),
                      TextField(
                        controller: to,
                        decoration: const InputDecoration(
                          labelText: 'Sampai inklusif WIB (YYYY-MM-DD)',
                        ),
                      ),
                      TextButton(
                        onPressed: () {
                          filters = {
                            ...filters,
                            'from': from.text.trim(),
                            'to': to.text.trim(),
                          };
                          page = 1;
                          reload();
                        },
                        child: const Text('Terapkan periode'),
                      ),
                    ],
                    if (c['notice'] is String) Text(c['notice']),
                    for (final f in c['forms'])
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 6),
                        child: FilledButton(
                          onPressed: () => edit(ErpRepository.object(f), ''),
                          child: Text(f['label']),
                        ),
                      ),
                    for (final row in c['data'])
                      DocumentSummary(
                        text: row['summary'],
                        actions: [
                          if (section == 'lots' &&
                              widget.operatorStore == null) ...[
                            TextButton(
                              onPressed: () {
                                section = 'trace';
                                page = 1;
                                filters = {'lot_id': row['id']};
                                reload();
                              },
                              child: const Text('Jejak pemakaian'),
                            ),
                            TextButton(
                              onPressed: () {
                                section = 'ledger';
                                page = 1;
                                filters = {'lot_id': row['id']};
                                reload();
                              },
                              child: const Text('Pergerakan lot'),
                            ),
                          ],
                          for (final f in row['forms'])
                            TextButton(
                              onPressed: () =>
                                  edit(ErpRepository.object(f), row['summary']),
                              child: Text(f['label']),
                            ),
                        ],
                      ),
                    Wrap(
                      children: [
                        TextButton(
                          onPressed: page > 1
                              ? () {
                                  page--;
                                  reload();
                                }
                              : null,
                          child: const Text('Sebelumnya'),
                        ),
                        Text('Halaman $page'),
                        TextButton(
                          onPressed: c['next_page_url'] != null
                              ? () {
                                  page++;
                                  reload();
                                }
                              : null,
                          child: const Text('Berikutnya'),
                        ),
                      ],
                    ),
                  ],
                );
              },
            ),
          ],
        ),
      ),
    ),
  );
}
