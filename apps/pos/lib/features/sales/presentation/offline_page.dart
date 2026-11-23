import 'dart:convert';
import 'package:flutter/material.dart';
import '../data/offline_repository.dart';

class OfflinePage extends StatefulWidget {
  const OfflinePage({
    required this.repository,
    this.storeId,
    this.shiftId,
    this.onReconnect,
    this.onLogout,
    super.key,
  });
  final OfflineRepository repository;
  final String? storeId, shiftId;
  final Future<void> Function()? onReconnect, onLogout;
  @override
  State<OfflinePage> createState() => _OfflinePageState();
}

class _OfflinePageState extends State<OfflinePage> {
  Map<String, dynamic>? state;
  String? error, permitId, productId, unit, serviceType;
  String? candidate;
  final duration = TextEditingController(),
      quantity = TextEditingController(),
      cash = TextEditingController();
  final selections = <String, String>{};
  final cart = <Map<String, dynamic>>[];
  bool busy = false, writeUncertain = false, verified = false;
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    duration.dispose();
    quantity.dispose();
    cash.dispose();
    super.dispose();
  }

  Future<void> run(Future<void> Function() f) async {
    if (busy) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await f();
      state = await widget.repository.load();
      final ps = (state!['permits'] as Map).values
          .where(
            (p) =>
                p['closed_at'] == null &&
                (widget.storeId == null || p['store_id'] == widget.storeId),
          )
          .toList();
      if (!ps.any((p) => p['id'] == permitId)) {
        permitId = ps.isEmpty ? null : ps.last['id'];
        productId = null;
        selections.clear();
      }
    } catch (e) {
      error = '$e';
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> load() => run(() async {});
  Map? get permit => state?['permits'][permitId];
  Map? get product => (permit?['snapshot']['products'] as List?)
      ?.where((p) => p['id'] == productId)
      .firstOrNull;
  void add() {
    try {
      final p = product;
      final n = int.tryParse(quantity.text);
      if (p == null || n == null || n < 1 || unit == null) {
        throw 'Pilih produk/satuan dan isi jumlah aktual.';
      }
      if (cart.any((l) => l['product_id'] == p['id'])) {
        throw 'SKU sudah ada; hapus baris sebelumnya untuk mengubah.';
      }
      final recipe = p['recipe'];
      final ids = recipe == null
          ? [p['id']]
          : [for (final c in recipe['components']) c['product_id']];
      final allocations = <Map<String, dynamic>>[];
      for (final id in ids) {
        final pair = selections[id]?.split(':');
        if (pair == null || pair.length != 2) {
          throw 'Pilih batch/keadaan stok setiap komponen.';
        }
        final count = recipe == null
            ? n
            : (recipe['components'] as List).firstWhere(
                    (c) => c['product_id'] == id,
                  )['pieces'] *
                  n;
        allocations.add({
          'batch_id': pair[0],
          'bucket': pair[1],
          'quantity': count,
          if (recipe != null) 'component_product_id': id,
        });
      }
      setState(() {
        cart.add({
          'product_id': p['id'],
          'unit': unit,
          'quantity': n,
          'allocations': allocations,
        });
        productId = null;
        unit = null;
        quantity.clear();
        selections.clear();
      });
    } catch (e) {
      setState(() => error = '$e');
    }
  }

  Widget stocks(Map p) {
    final recipe = p['recipe'];
    final components = recipe == null
        ? [
            {'product_id': p['id'], 'sku': p['sku']},
          ]
        : recipe['components'];
    return Column(
      children: [
        for (final c in components)
          DropdownButtonFormField<String>(
            key: ValueKey('$productId:${c['product_id']}'),
            isExpanded: true,
            decoration: InputDecoration(
              labelText: 'Batch ${c['sku'] ?? c['product_id']}',
            ),
            items: [
              for (final b in permit!['snapshot']['batches'])
                if (b['product_id'] == c['product_id'])
                  for (final bucket in ['usable', 'opened', 'ready'])
                    if ((b['${bucket}_pieces'] as int) > 0 &&
                        !(b['blocked_buckets'] as List).contains(bucket) &&
                        ((unit == 'pack' && recipe == null)
                            ? bucket == 'usable'
                            : bucket != 'usable'))
                      DropdownMenuItem(
                        value: '${b['id']}:$bucket',
                        child: Text(
                          '${b['batch_code']} · $bucket · ${b['${bucket}_pieces']} biji',
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
            ],
            onChanged: busy ? null : (v) => selections[c['product_id']] = v!,
          ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: !busy && !writeUncertain,
    child: Scaffold(
      appBar: AppBar(
        title: const Text('POS offline tunai dan antrean'),
        actions: [
          if (widget.onReconnect != null)
            IconButton(
              tooltip: 'Periksa koneksi dan sesi',
              onPressed: busy ? null : widget.onReconnect,
              icon: const Icon(Icons.sync),
            ),
          if (widget.onLogout != null)
            IconButton(
              tooltip: 'Keluar (antrean tetap terenkripsi)',
              onPressed: busy ? null : widget.onLogout,
              icon: const Icon(Icons.logout),
            ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (busy) const LinearProgressIndicator(),
          if (error != null)
            Text(
              error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          const Text(
            'Tunai saja, tanpa voucher/non-tunai. Stok/harga server dapat berubah. Catatan OFF adalah sementara sampai sinkronisasi; konflik wajib ditinjau pengelola.',
          ),
          if (candidate != null) ...[
            Builder(
              builder: (context) {
                final c = jsonDecode(candidate!);
                return Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Tinjau tunai Rp ${c['review']['total_amount']}'),
                        Text(
                          'Diterima Rp ${c['review']['cash_received']} · Kembalian Rp ${c['review']['change_amount']}',
                        ),
                        for (final l in c['review']['lines'])
                          Text(
                            '${l['sku']} · ${l['quantity']} ${l['unit']} · Rp ${l['line_total']}',
                          ),
                        const Text(
                          'Akan disimpan terenkripsi di perangkat. Belum dibukukan server.',
                        ),
                      ],
                    ),
                  ),
                );
              },
            ),
            CheckboxListTile(
              value: verified,
              onChanged: busy ? null : (v) => setState(() => verified = v!),
              title: const Text(
                'Saya memeriksa barang, uang tunai dan kembalian aktual sesuai review ini',
              ),
            ),
            FilledButton(
              onPressed: busy || !verified
                  ? null
                  : () => run(() async {
                      try {
                        await widget.repository.capture(
                          jsonDecode(candidate!) as Map<String, dynamic>,
                        );
                        writeUncertain = false;
                        candidate = null;
                        cart.clear();
                        cash.clear();
                        verified = false;
                      } catch (_) {
                        writeUncertain = true;
                        rethrow;
                      }
                    }),
              child: Text(
                writeUncertain
                    ? 'Ulangi penyimpanan UUID yang sama'
                    : 'Konfirmasi dan simpan catatan offline',
              ),
            ),
            if (!writeUncertain)
              TextButton(
                onPressed: busy
                    ? null
                    : () => setState(() {
                        candidate = null;
                        verified = false;
                      }),
                child: const Text('Kembali tanpa pencatatan'),
              ),
          ] else ...[
            if (widget.storeId != null && widget.shiftId != null) ...[
              TextField(
                controller: duration,
                enabled: !busy,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                  labelText: 'Durasi izin offline (5–480 menit)',
                ),
              ),
              FilledButton(
                onPressed: busy
                    ? null
                    : () => run(() async {
                        final n = int.tryParse(duration.text);
                        if (n == null) throw 'Isi durasi izin.';
                        await widget.repository.prepare(
                          widget.storeId!,
                          widget.shiftId!,
                          n,
                        );
                      }),
                child: const Text('Siapkan izin saat online'),
              ),
              TextButton(
                onPressed: busy
                    ? null
                    : () =>
                          run(() => widget.repository.recover(widget.storeId!)),
                child: const Text('Pulihkan izin dari server'),
              ),
            ],
            if (state != null)
              DropdownButtonFormField<String>(
                key: ValueKey('permit:$permitId'),
                initialValue: permitId,
                isExpanded: true,
                decoration: const InputDecoration(
                  labelText: 'Izin tersimpan milik akun ini',
                ),
                items: [
                  for (final p in (state!['permits'] as Map).values)
                    if (p['closed_at'] == null &&
                        (widget.storeId == null ||
                            p['store_id'] == widget.storeId))
                      DropdownMenuItem(
                        value: p['id'] as String,
                        child: Text(
                          '${p['snapshot']['store']['name']} · hingga ${p['expires_at']}',
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                ],
                onChanged: busy
                    ? null
                    : (v) => setState(() {
                        permitId = v;
                        cart.clear();
                        productId = null;
                        unit = null;
                        selections.clear();
                      }),
              ),
            if (permit != null) ...[
              Text(
                'Snapshot izin ${permit!['issued_at']} · Terakhir sinkron ${state!['last_sync'] ?? 'Belum'}',
              ),
              DropdownButtonFormField<String>(
                key: ValueKey('product:$productId:$permitId'),
                initialValue: productId,
                isExpanded: true,
                decoration: const InputDecoration(
                  labelText: 'Produk dari snapshot izin',
                ),
                items: [
                  for (final p in permit!['snapshot']['products'])
                    DropdownMenuItem(
                      value: p['id'] as String,
                      child: Text(
                        '${p['sku']} · ${p['name']}',
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                ],
                onChanged: busy
                    ? null
                    : (v) => setState(() {
                        productId = v;
                        unit = null;
                        selections.clear();
                      }),
              ),
              if (product case final p?) ...[
                DropdownButtonFormField<String>(
                  key: ValueKey('unit:$productId:$unit'),
                  initialValue: unit,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Satuan'),
                  items: [
                    for (final u
                        in p['recipe'] != null
                            ? ['piece']
                            : ['piece', 'portion', 'pack'])
                      DropdownMenuItem(
                        value: u,
                        child: Text(
                          u == 'piece' && p['recipe'] != null ? 'Paket' : u,
                        ),
                      ),
                  ],
                  onChanged: busy
                      ? null
                      : (v) => setState(() {
                          unit = v;
                          selections.clear();
                        }),
                ),
                TextField(
                  controller: quantity,
                  enabled: !busy,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(
                    labelText: 'Jumlah unit aktual',
                  ),
                ),
                if (unit != null) stocks(p),
                OutlinedButton(
                  onPressed: busy ? null : add,
                  child: const Text('Tambahkan ke keranjang offline'),
                ),
              ],
              for (final l in cart)
                ListTile(
                  title: Text(
                    '${l['quantity']} ${l['unit']} · ${(permit!['snapshot']['products'] as List).firstWhere((p) => p['id'] == l['product_id'])['sku']}',
                  ),
                  trailing: IconButton(
                    tooltip: 'Hapus item',
                    onPressed: busy
                        ? null
                        : () => setState(() => cart.remove(l)),
                    icon: const Icon(Icons.delete_outline),
                  ),
                ),
              if (permit!['snapshot']['consumables_enabled'] == true)
                DropdownButtonFormField<String>(
                  key: ValueKey('offline-service:$permitId:$serviceType'),
                  initialValue: serviceType,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Jenis penjualan',
                  ),
                  items: const [
                    DropdownMenuItem(
                      value: 'dine_in',
                      child: Text('Makan di tempat'),
                    ),
                    DropdownMenuItem(
                      value: 'takeaway',
                      child: Text('Bawa pulang (pakai kemasan)'),
                    ),
                  ],
                  onChanged: busy
                      ? null
                      : (v) => setState(() => serviceType = v),
                ),
              TextField(
                controller: cash,
                enabled: !busy,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                  labelText: 'Tunai aktual diterima (Rp)',
                ),
              ),
              FilledButton(
                onPressed: busy || cart.isEmpty
                    ? null
                    : () => run(() async {
                        final n = int.tryParse(cash.text);
                        if (n == null) throw 'Isi tunai aktual.';
                        candidate = jsonEncode(
                          await widget.repository.preview(
                            permitId!,
                            cart,
                            n,
                            serviceType: serviceType,
                          ),
                        );
                        verified = false;
                      }),
                child: const Text('Tinjau sebelum menyimpan offline'),
              ),
              OutlinedButton(
                onPressed: busy
                    ? null
                    : () => run(() => widget.repository.end(permitId!)),
                child: const Text(
                  'Akhiri izin setelah seluruh antrean selesai',
                ),
              ),
            ],
            FilledButton(
              onPressed: busy ? null : () => run(widget.repository.synchronize),
              child: const Text('Sinkronkan / perbarui status konflik'),
            ),
            const Text(
              'Pengelola menyelesaikan konflik melalui web → POS → Antrean offline. Jangan mencatat ulang catatan yang masih pending/konflik.',
            ),
            if (state != null)
              for (final r in (state!['outbox'] as Map).values)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('${r['local_number']} · ${r['state']}'),
                        Text(
                          '${r['review']['store_name']} · Rp ${r['review']['total_amount']} · Tunai Rp ${r['review']['cash_received']} · Kembali Rp ${r['review']['change_amount']}',
                        ),
                        Text(
                          r['state'] == 'accepted'
                              ? 'Struk server ${r['receipt']?['number'] ?? ''}'
                              : 'Catatan sementara, bukan bukti posting server.',
                        ),
                        if (r['conflict_message'] != null)
                          Text('${r['conflict_message']}'),
                      ],
                    ),
                  ),
                ),
          ],
        ],
      ),
    ),
  );
}
