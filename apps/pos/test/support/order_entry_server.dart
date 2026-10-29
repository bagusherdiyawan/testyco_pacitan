import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:testyco_pos/core/network/api_client.dart';
import 'package:testyco_pos/features/ordering/data/order_entry_repository.dart';

class EntryServer {
  Map<String, dynamic> context = {
    'name': 'Pacitan',
    'address': 'Jalan toko',
    'active': true,
    'can_order': true,
    'order_kind': 'wholesale',
  };
  final sources = <Map<String, dynamic>>[
    {
      'id': 'P1',
      'organization_id': 'C1',
      'sku': 'AYAM8',
      'name': 'Ayam pusat',
      'condition': 'frozen',
      'pack_size': 8,
      'wholesale_price': 18000,
    },
    {
      'id': 'P2',
      'organization_id': 'C1',
      'sku': 'SAPI8',
      'name': 'Sapi pusat',
      'condition': 'frozen',
      'pack_size': 8,
      'wholesale_price': 20000,
    },
    {
      'id': 'P3',
      'organization_id': 'C2',
      'sku': 'OTHER8',
      'name': 'Pusat lain',
      'condition': 'frozen',
      'pack_size': 8,
      'wholesale_price': 22000,
    },
  ];
  final locals = <Map<String, dynamic>>[
    {
      'id': 'L1',
      'sku': 'LOCAL8',
      'name': 'Ayam toko',
      'condition': 'frozen',
      'pack_size': 8,
    },
    {
      'id': 'L2',
      'sku': 'LOCAL-SAPI8',
      'name': 'Sapi toko',
      'condition': 'frozen',
      'pack_size': 8,
    },
    {
      'id': 'BAD',
      'sku': 'BAD10',
      'name': 'Kemasan berbeda',
      'condition': 'frozen',
      'pack_size': 10,
    },
    {
      'id': 'READY',
      'sku': 'READY8',
      'name': 'Kondisi berbeda',
      'condition': 'ready',
      'pack_size': 8,
    },
  ];
  final previews = <Map<String, dynamic>>[], creates = <Map<String, dynamic>>[];
  final saved = <String, String>{};
  int catalogueReads = 0, listReads = 0, createdTotal = 0;
  bool deniedContext = false,
      deniedCreate = false,
      loseResponse = false,
      failContext = false,
      changed = false;
  String get hash => List.filled(64, changed ? 'b' : 'a').join();
  late final api = ApiClient(
    baseUrl: Uri.parse('https://api.example.test/api/v1/'),
    client: MockClient(handle),
  );
  OrderEntryRepository get repository => OrderEntryRepository(api);
  http.Response reply(Object body, [int status = 200]) =>
      http.Response(jsonEncode(body), status);
  List<Map<String, dynamic>> quoteLines(Map<String, dynamic> body) => [
    for (final row in body['lines'] as List)
      {
        'supplier_product_id': row['supplier_product_id'],
        'local_product_id': row['local_product_id'],
        'sku': sources.firstWhere(
          (p) => p['id'] == row['supplier_product_id'],
        )['sku'],
        'local_sku': locals.firstWhere(
          (p) => p['id'] == row['local_product_id'],
        )['sku'],
        'quantity_packs': row['quantity_packs'],
        'pack_size': 8,
        'unit_price': context['order_kind'] == 'internal_transfer'
            ? 0
            : changed
            ? 19000
            : sources.firstWhere(
                (p) => p['id'] == row['supplier_product_id'],
              )['wholesale_price'],
      },
  ];
  Future<http.Response> handle(http.Request r) async {
    final path = r.url.path;
    if (r.method == 'GET') {
      if (path.endsWith('/operations-context')) {
        if (deniedContext) {
          return reply({'message': 'Denied'}, 403);
        }
        if (failContext) {
          return reply({}, 503);
        }
        return reply({'data': context});
      }
      if (path.endsWith('/supply-catalog')) {
        catalogueReads++;
        return reply({'data': sources});
      }
      if (path.endsWith('/catalog')) {
        catalogueReads++;
        return reply({'data': locals});
      }
      if (path.endsWith('/stores/S1/orders')) {
        listReads++;
        return reply({
          'data': saved.isEmpty
              ? []
              : [
                  {
                    'id': 'O9',
                    'status': 'awaiting_approval',
                    'total_amount': createdTotal,
                  },
                ],
        });
      }
      if (path.endsWith('/orders/O9')) {
        return reply({
          'data': {
            'id': 'O9',
            'destination_name': 'Pacitan',
            'kind': context['order_kind'],
            'status': 'awaiting_approval',
            'version': 1,
            'total_amount': createdTotal,
            'fulfillment_enabled': false,
            'recipient_actions': {
              'approve': false,
              'reject': false,
              'cancel': false,
              'receive': false,
            },
            'lines': quoteLines(creates.first),
          },
        });
      }
      throw StateError('Unexpected GET $path');
    }
    final body = jsonDecode(r.body) as Map<String, dynamic>;
    if (path.endsWith('/preview')) {
      previews.add(body);
      final lines = quoteLines(body);
      final total = lines.fold<int>(
        0,
        (sum, l) =>
            sum + (l['quantity_packs'] as int) * (l['unit_price'] as int),
      );
      return reply({
        'data': {
          'destination_name': 'Pacitan',
          'destination_address': context['address'],
          'kind': context['order_kind'],
          'delivery_mode': body['delivery_mode'],
          'total_amount': total,
          'review_hash': hash,
          'observed_at': '2026-10-29T03:00:00Z',
          'lines': lines,
        },
      });
    }
    if (path.endsWith('/orders')) {
      creates.add(body);
      if (deniedCreate) {
        return reply({'message': 'Denied'}, 403);
      }
      final operation = body['operation_id'] as String;
      if (saved.containsKey(operation)) {
        return reply({
          'data': {'id': saved[operation]},
        }, 201);
      }
      if (body['review_hash'] != hash) {
        return reply({
          'message': 'Data pengajuan berubah setelah ditinjau',
        }, 409);
      }
      saved[operation] = 'O9';
      createdTotal = quoteLines(body).fold<int>(
        0,
        (sum, l) =>
            sum + (l['quantity_packs'] as int) * (l['unit_price'] as int),
      );
      if (loseResponse) {
        loseResponse = false;
        throw http.ClientException('Lost after commit');
      }
      return reply({
        'data': {'id': 'O9'},
      }, 201);
    }
    throw StateError('Unexpected POST $path');
  }
}
