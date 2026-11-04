import 'dart:convert';
import 'package:uuid/uuid.dart';
import '../../../core/network/api_client.dart';
import '../../../core/storage/device_state.dart';

class OfflineRepository {
  OfflineRepository(this.api, this.storage);
  final ApiClient api;
  final DeviceState storage;
  int get userId {
    final id = api.identity?['id'];
    if (id is! int) {
      throw const ApiException(
        'Masuk kembali sebelum mengakses antrean perangkat.',
      );
    }
    return id;
  }

  String get key => 'offline:${api.baseUrl}:$userId';
  Map<String, dynamic> fresh() => {
    'user_id': userId,
    'base_url': api.baseUrl.toString(),
    'permits': <String, dynamic>{},
    'outbox': <String, dynamic>{},
  };
  Future<Map<String, dynamic>> load() async {
    final state = await storage.read(key) ?? fresh();
    if (state['user_id'] != userId ||
        state['base_url'] != api.baseUrl.toString() ||
        state['permits'] is! Map ||
        state['outbox'] is! Map) {
      throw const ApiException(
        'Cakupan antrean tidak valid. Data dipertahankan.',
      );
    }
    return state;
  }

  Future<void> cachePermit(Map<String, dynamic> p) async {
    if (p['id'] is! String ||
        p['store_id'] is! String ||
        p['cashier_id'] != userId ||
        p['snapshot'] is! Map ||
        p['snapshot']['user_id'] != userId ||
        p['snapshot']['store']['id'] != p['store_id'] ||
        p['snapshot']['vouchers_allowed'] != false ||
        jsonEncode(p['snapshot']['payment_methods']) != '["cash"]') {
      throw const ApiException('Izin offline tidak sesuai akun/toko.');
    }
    await storage.update(key, (old) {
      final state = old ?? fresh();
      state['permits'][p['id']] = p;
      return state;
    });
  }

  Future<void> prepare(String store, String shift, int minutes) async {
    if (minutes < 5 || minutes > 480) {
      throw const ApiException('Pilih durasi 5–480 menit.');
    }
    final state = await load();
    final requests = Map<String, dynamic>.from(state['permit_requests'] ?? {});
    final body = Map<String, dynamic>.from(
      requests[store] ??
          {
            'operation_id': const Uuid().v4(),
            'shift_id': shift,
            'duration_minutes': minutes,
          },
    );
    await storage.update(key, (old) {
      final s = old ?? fresh();
      s['permit_requests'] ??= <String, dynamic>{};
      s['permit_requests'][store] = body;
      return s;
    });
    final d = (await api.request(
      'POST',
      'stores/${Uri.encodeComponent(store)}/sales/offline/permits',
      body,
    ))['data'];
    if (d is! Map ||
        d['store_id'] != store ||
        d['cash_shift_id'] != body['shift_id']) {
      throw const ApiException('ACK izin tidak sesuai. Pulihkan izin server.');
    }
    await cachePermit(Map<String, dynamic>.from(d));
    await storage.update(key, (old) {
      final s = old!;
      (s['permit_requests'] as Map?)?.remove(store);
      return s;
    });
  }

  Future<void> recover(String store) async {
    final c = await api.request(
      'GET',
      'stores/${Uri.encodeComponent(store)}/sales/offline',
    );
    if (c['store_id'] != store || c['permits'] is! List) {
      throw const ApiException('Cakupan izin server tidak sesuai.');
    }
    for (final p in c['permits']) {
      await cachePermit(Map<String, dynamic>.from(p));
    }
    await storage.update(key, (old) {
      final s = old ?? fresh();
      (s['permit_requests'] as Map?)?.remove(store);
      return s;
    });
  }

  void _checkSafety(Map snapshot, Map batch, String bucket, DateTime at) {
    final safety = snapshot['safety'];
    if (safety is! Map) return;
    if (safety['block_expired'] == true) {
      final expires = batch['expires_on'];
      final wib = at
          .toUtc()
          .add(const Duration(hours: 7))
          .toIso8601String()
          .substring(0, 10);
      if (expires is! String ||
          expires.length < 10 ||
          expires.substring(0, 10).compareTo(wib) < 0) {
        throw const ApiException('Tanggal kemasan diblokir SOP tersimpan.');
      }
    }
    if (bucket == 'ready' && safety['ready_life_minutes'] != null) {
      final until = DateTime.tryParse(batch['ready_expires_at'] ?? '');
      if (until == null || !until.isAfter(at)) {
        throw const ApiException(
          'Batas masa simpan siap jual telah berakhir/tidak diketahui.',
        );
      }
    }
  }

  Future<Map<String, dynamic>> preview(
    String permitId,
    List<Map<String, dynamic>> requested,
    int cash,
  ) async {
    final state = await load();
    final p = state['permits'][permitId];
    if (p is! Map || p['closed_at'] != null) {
      throw const ApiException('Izin offline tidak tersedia.');
    }
    final now = DateTime.now().toUtc();
    final from = DateTime.tryParse(p['issued_at'] ?? ''),
        until = DateTime.tryParse(p['expires_at'] ?? '');
    if (from == null ||
        until == null ||
        now.isBefore(from) ||
        now.isAfter(until)) {
      throw const ApiException(
        'Izin offline di luar waktunya. Sambungkan jaringan dan siapkan izin baru.',
      );
    }
    if (requested.isEmpty || requested.length > 100) {
      throw const ApiException('Isi 1–100 SKU.');
    }
    final seen = <String>{};
    var total = 0;
    final review = <Map<String, dynamic>>[], lines = <Map<String, dynamic>>[];
    final used = <String, int>{};
    for (final old in (state['outbox'] as Map).values) {
      if (old['body']['permit_id'] != permitId) continue;
      for (final line in old['review']['lines']) {
        for (final a in line['allocations']) {
          final k = '${a['batch_id']}:${a['bucket']}';
          used[k] = (used[k] ?? 0) + (a['pieces'] as int);
        }
      }
    }
    for (final raw in jsonDecode(jsonEncode(requested)) as List) {
      final matches = (p['snapshot']['products'] as List).where(
        (v) => v['id'] == raw['product_id'],
      );
      if (matches.length != 1 || !seen.add(raw['product_id'])) {
        throw const ApiException('SKU tidak valid/diulang.');
      }
      final product = matches.single;
      final qty = raw['quantity'], unit = raw['unit'];
      if (qty is! int ||
          qty < 1 ||
          qty > 10000 ||
          !['piece', 'portion', 'pack'].contains(unit)) {
        throw const ApiException('Periksa satuan dan jumlah.');
      }
      final recipe = product['recipe'];
      if (recipe != null && unit != 'piece') {
        throw const ApiException('Paket memakai satuan paket.');
      }
      final per = recipe != null
          ? 1
          : unit == 'pack'
          ? product['pack_size']
          : unit == 'portion'
          ? product['portion_size']
          : 1;
      final price = (product['retail_price'] as int) * (per as int);
      final amount = price * qty;
      total += amount;
      if (total > 1000000000000) {
        throw const ApiException('Nominal melewati batas.');
      }
      var allocated = 0;
      final components = <String, int>{};
      final allocations = <Map<String, dynamic>>[],
          wire = <Map<String, dynamic>>[];
      final pairs = <String>{};
      for (final a in raw['allocations'] as List) {
        final component = recipe == null
            ? null
            : (recipe['components'] as List)
                  .where((v) => v['product_id'] == a['component_product_id'])
                  .firstOrNull;
        if (recipe != null && component == null) {
          throw const ApiException('Komponen resep tidak sesuai.');
        }
        final productId = component?['product_id'] ?? product['id'];
        final batch = (p['snapshot']['batches'] as List)
            .where(
              (b) => b['id'] == a['batch_id'] && b['product_id'] == productId,
            )
            .firstOrNull;
        final bucket = a['bucket'], n = a['quantity'];
        if (batch == null ||
            n is! int ||
            n < 1 ||
            !['usable', 'opened', 'ready'].contains(bucket) ||
            batch['condition'] !=
                (component?['condition'] ?? product['condition']) ||
            (batch['blocked_buckets'] as List).contains(bucket)) {
          throw const ApiException(
            'Batch/keadaan stok tidak sesuai snapshot izin.',
          );
        }
        _checkSafety(p['snapshot'], batch, bucket, now);
        if ((unit == 'pack' && recipe == null) &&
            (bucket != 'usable' || batch['pack_size'] != per)) {
          throw const ApiException(
            'Kemasan memerlukan stok utuh dengan konversi sama.',
          );
        }
        if ((unit != 'pack' || recipe != null) && bucket == 'usable') {
          throw const ApiException(
            'Biji/porsi/paket memerlukan stok terbuka/siap.',
          );
        }
        final pair = '${batch['id']}:$bucket';
        if (!pairs.add(pair)) {
          throw const ApiException('Alokasi batch diulang.');
        }
        final pieces = n * (recipe == null ? per : 1);
        used[pair] = (used[pair] ?? 0) + pieces;
        if (used[pair]! > (batch['${bucket}_pieces'] as int)) {
          throw const ApiException(
            'Sisa snapshot perangkat tidak cukup. Stok perangkat lain belum diketahui.',
          );
        }
        allocated += n;
        if (component != null) {
          components[productId] = (components[productId] ?? 0) + n;
        }
        wire.add({
          'batch_id': batch['id'],
          'bucket': bucket,
          'quantity': n,
          if (component != null) 'component_product_id': productId,
        });
        allocations.add({
          ...wire.last,
          'pieces': pieces,
          'batch_code': batch['batch_code'],
        });
      }
      if (recipe == null) {
        if (allocated != qty) {
          throw const ApiException('Alokasi tidak sama dengan jumlah.');
        }
      } else {
        for (final c in recipe['components']) {
          if (components[c['product_id']] != c['pieces'] * qty) {
            throw const ApiException('Isi seluruh komponen paket.');
          }
        }
      }
      lines.add({
        'product_id': product['id'],
        'unit': unit,
        'quantity': qty,
        'allocations': wire,
      });
      review.add({
        'product_id': product['id'],
        'sku': product['sku'],
        'name': product['name'],
        'unit': unit,
        'quantity': qty,
        'unit_price': price,
        'line_total': amount,
        'allocations': allocations,
      });
    }
    if (cash < total || cash > 1000000000000) {
      throw const ApiException('Tunai belum cukup atau melewati batas.');
    }
    return {
      'body': {
        'operation_id': const Uuid().v4(),
        'permit_id': permitId,
        'captured_at': now.toIso8601String(),
        'client_total': total,
        'cash_received': cash,
        'lines': lines,
      },
      'review': {
        'store_id': p['store_id'],
        'store_name': p['snapshot']['store']['name'],
        'total_amount': total,
        'cash_received': cash,
        'change_amount': cash - total,
        'lines': review,
      },
    };
  }

  Future<void> capture(Map<String, dynamic> candidate) async {
    final frozen = jsonDecode(jsonEncode(candidate)) as Map<String, dynamic>;
    final id = frozen['body']['operation_id'];
    if (id is! String) throw const ApiException('ID antrean tidak valid.');
    await storage.update(key, (old) {
      final s = old ?? fresh();
      final existing = s['outbox'][id];
      if (existing != null) {
        if (jsonEncode(existing['body']) != jsonEncode(frozen['body'])) {
          throw const ApiException('ID sudah dipakai untuk data berbeda.');
        }
        return s;
      }
      final permit = s['permits'][frozen['body']['permit_id']];
      if (permit == null ||
          permit['closed_at'] != null ||
          DateTime.parse(
            permit['expires_at'],
          ).isBefore(DateTime.now().toUtc())) {
        throw const ApiException(
          'Izin berubah; periksa sebelum menerima uang.',
        );
      }
      final now = DateTime.now().toUtc();
      final balances = <String, int>{};
      for (final row in (s['outbox'] as Map).values) {
        if (row['body']['permit_id'] != permit['id']) continue;
        for (final line in row['review']['lines']) {
          for (final a in line['allocations']) {
            final k = '${a['batch_id']}:${a['bucket']}';
            balances[k] = (balances[k] ?? 0) + (a['pieces'] as int);
          }
        }
      }
      for (final line in frozen['review']['lines']) {
        for (final a in line['allocations']) {
          final batch = (permit['snapshot']['batches'] as List)
              .where((b) => b['id'] == a['batch_id'])
              .single;
          _checkSafety(permit['snapshot'], batch, a['bucket'], now);
          final k = '${a['batch_id']}:${a['bucket']}';
          balances[k] = (balances[k] ?? 0) + (a['pieces'] as int);
          if (balances[k]! > (batch['${a['bucket']}_pieces'] as int)) {
            throw const ApiException(
              'Sisa snapshot berubah sebelum disimpan. Tinjau ulang tanpa menerima uang baru.',
            );
          }
        }
      }
      s['outbox'][id] = {
        ...frozen,
        'state': 'pending',
        'local_number': 'OFF-${id.toUpperCase()}',
        'server_id': null,
      };
      if (utf8.encode(jsonEncode(s)).length > 8 * 1024 * 1024) {
        throw const ApiException(
          'Antrean perangkat penuh. Sinkronkan sebelum menerima transaksi baru.',
        );
      }
      return s;
    });
  }

  Future<void> synchronize() async {
    final initial = await load();
    for (final entry in (initial['outbox'] as Map).entries) {
      final row = entry.value;
      if (row['state'] == 'accepted' || row['state'] == 'voided') continue;
      final body = Map<String, dynamic>.from(row['body']);
      final p = initial['permits'][body['permit_id']];
      if (p == null) {
        throw const ApiException('Izin antrean hilang; data dipertahankan.');
      }
      String? serverId = row['server_id'];
      if (serverId == null) {
        final d = (await api.request(
          'POST',
          'stores/${Uri.encodeComponent(p['store_id'])}/sales/offline',
          body,
        ))['data'];
        if (d is! Map ||
            d['id'] is! String ||
            d['operation_id'] != body['operation_id'] ||
            d['cashier_id'] != userId ||
            d['store_id'] != p['store_id'] ||
            d['offline_permit_id'] != body['permit_id']) {
          throw const ApiException(
            'ACK sinkronisasi tidak sesuai. Pertahankan request yang sama.',
          );
        }
        serverId = d['id'];
        await storage.update(key, (s) {
          s!['outbox'][entry.key]['server_id'] = serverId;
          return s;
        });
      }
      final d = (await api.request(
        'GET',
        'sales/offline/${Uri.encodeComponent(serverId!)}',
      ))['data'];
      if (d is! Map ||
          d['id'] != serverId ||
          d['operation_id'] != body['operation_id'] ||
          d['cashier_id'] != userId ||
          d['store_id'] != p['store_id'] ||
          !['accepted', 'conflict', 'voided'].contains(d['status'])) {
        throw const ApiException('Status sinkronisasi tidak sesuai.');
      }
      if (d['status'] == 'accepted' &&
          (d['receipt'] is! Map ||
              d['receipt']['offline_sale_id'] != serverId ||
              d['receipt']['payment_method'] != 'cash')) {
        throw const ApiException('Struk hasil sinkronisasi tidak sesuai.');
      }
      await storage.update(key, (s) {
        final r = s!['outbox'][entry.key];
        r['state'] = d['status'];
        r['conflict_message'] = d['conflict_message'];
        r['receipt'] = d['receipt'];
        s['last_sync'] = DateTime.now().toUtc().toIso8601String();
        return s;
      });
    }
  }

  Future<void> end(String permitId) async {
    final s = await load();
    if ((s['outbox'] as Map).values.any(
      (r) =>
          r['body']['permit_id'] == permitId &&
          !['accepted', 'voided'].contains(r['state']),
    )) {
      throw const ApiException('Sinkronkan dan selesaikan konflik dahulu.');
    }
    final p = s['permits'][permitId];
    if (p == null) throw const ApiException('Izin tidak ditemukan.');
    final body = Map<String, dynamic>.from(
      (s['end_requests'] ?? {})[permitId] ??
          {'operation_id': const Uuid().v4(), 'queue_empty': true},
    );
    await storage.update(key, (v) {
      v!['end_requests'] ??= <String, dynamic>{};
      v['end_requests'][permitId] = body;
      return v;
    });
    final d = (await api.request(
      'POST',
      'sales/offline/permits/${Uri.encodeComponent(permitId)}/end',
      body,
    ))['data'];
    if (d is! Map || d['id'] != permitId || d['closed_at'] is! String) {
      throw const ApiException('ACK akhir izin belum pasti.');
    }
    await storage.update(key, (v) {
      v!['permits'][permitId]['closed_at'] = d['closed_at'];
      (v['end_requests'] as Map).remove(permitId);
      return v;
    });
  }
}
