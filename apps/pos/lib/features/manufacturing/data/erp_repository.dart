import '../../../core/network/api_client.dart';
import '../../administration/presentation/admin_form.dart';

class ErpRepository {
  ErpRepository(this.api, this.organization);
  final ApiClient api;
  final String organization;
  static const sections = {
    'materials': 'Bahan baku / kemasan',
    'suppliers': 'Pemasok',
    'purchases': 'Pembelian dan pembayaran',
    'lots': 'Lot dan pemeriksaan bahan',
    'recipes': 'Resep produksi',
    'productions': 'Pelaksanaan produksi',
    'counts': 'Review opname bahan',
    'ledger': 'Pergerakan bahan',
    'planning': 'Kebutuhan bahan',
    'report': 'Biaya produksi / HPP',
    'trace': 'Jejak bahan ke produksi',
  };
  static const actions = {
    'material',
    'material_configure',
    'supplier',
    'purchase',
    'purchase_decide',
    'receive',
    'payment',
    'recipe',
    'production',
    'production_cancel',
    'complete',
    'material_quality',
    'production_quality',
    'transfer',
    'count',
    'count_decide',
  };
  static Map<String, dynamic> object(Object? value) {
    if (value is Map) return Map<String, dynamic>.from(value);
    if (value is List && value.isEmpty) return {};
    throw const FormatException('Metadata ERP tidak valid.');
  }

  Future<Map<String, dynamic>> load(
    String section,
    int page,
    Map<String, String> filters,
  ) async {
    if (!sections.containsKey(section) || page < 1) {
      throw const FormatException('Bagian ERP tidak valid.');
    }
    final query = Uri(
      queryParameters: {'section': section, 'page': '$page', ...filters},
    ).query;
    final c = await api.request(
      'GET',
      'organizations/$organization/erp/context?$query',
    );
    if (c['organization_id'] != organization ||
        c['section'] != section ||
        c['can_manage'] != true ||
        c['current_page'] != page ||
        c['data'] is! List ||
        c['forms'] is! List) {
      throw const FormatException('Lingkup ERP tidak cocok.');
    }
    for (final row in c['data']) {
      if (row is! Map ||
          row['organization_id'] != organization ||
          row['id'] is! String ||
          row['summary'] is! String ||
          row['forms'] is! List) {
        throw const FormatException('Dokumen ERP berbeda organisasi.');
      }
      for (final f in row['forms']) {
        fields(object(f));
      }
    }
    for (final f in c['forms']) {
      fields(object(f));
    }
    return c;
  }

  static List<AdminField> fields(Map<String, dynamic> form) {
    if (!actions.contains(form['operation']) ||
        form['label'] is! String ||
        form['fields'] is! List) {
      throw const FormatException('Formulir ERP tidak valid.');
    }
    object(form['fixed']);
    return (form['fields'] as List).map((raw) {
      final f = object(raw);
      if (f['key'] is! String ||
          !RegExp(r'^[a-z_]+$').hasMatch(f['key']) ||
          f['label'] is! String ||
          !{
            'text',
            'integer',
            'date',
            'choice',
            'quantity_map',
            'money_map',
            'consent',
          }.contains(f['kind'])) {
        throw const FormatException('Kolom ERP tidak valid.');
      }
      final options = object(f['options']).map((k, v) => MapEntry(k, '$v'));
      return AdminField(
        f['key'],
        f['label'],
        kind: f['kind'] == 'choice' ? 'text' : f['kind'],
        options: {'choice', 'quantity_map', 'money_map'}.contains(f['kind'])
            ? options
            : null,
        optional: f['optional'] == true,
      );
    }).toList();
  }
}
