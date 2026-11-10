import '../../../core/network/api_client.dart';
import '../../administration/presentation/admin_form.dart';

class ErpRepository {
  ErpRepository(this.api, this.organization);
  final ApiClient api;
  final String organization;
  static const financeSections = {
    'closings',
    'income_statement',
    'balance_sheet',
    'invoice_variances',
    'distribution_costs',
    'accounts',
    'periods',
    'journals',
    'trial_balance',
    'posting',
    'posting_events',
    'valuations',
    'valuation_stock',
    'cost_ledger',
  };
  static const sections = {
    'closings': 'Penutupan buku formal',
    'income_statement': 'Laporan laba rugi',
    'balance_sheet': 'Posisi keuangan',
    'invoice_variances': 'Penyesuaian biaya invoice',
    'distribution_costs': 'Biaya historis distribusi',
    'cost_ledger': 'Jejak nilai / HPP batch',
    'posting': 'Pemetaan jurnal otomatis',
    'posting_events': 'Antrean jurnal sumber',
    'valuations': 'Review nilai saldo awal',
    'valuation_stock': 'Nilai persediaan produk',
    'operators': 'Izin operator ERP',
    'accounts': 'Akun buku besar',
    'periods': 'Periode pembukuan',
    'journals': 'Jurnal debit/kredit',
    'trial_balance': 'Neraca saldo dan ringkasan akun',
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
    'commercial_policy',
    'closing_propose',
    'closing_decide',
    'invoice_revalue_retry',
    'wholesale_policy',
    'posting_policy',
    'posting_decide',
    'posting_suspend',
    'posting_reconcile',
    'posting_retry',
    'value_propose',
    'value_decide',
    'operator_grant',
    'account',
    'accounting_period',
    'journal',
    'journal_decide',
    'journal_reverse',
    'period_status',
    'material',
    'material_configure',
    'supplier',
    'purchase',
    'purchase_decide',
    'receive',
    'payment_reverse',
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
        (c['can_manage'] != true && c['can_operate'] != true) ||
        c['current_page'] != page ||
        c['data'] is! List ||
        c['forms'] is! List) {
      throw const FormatException('Lingkup ERP tidak cocok.');
    }
    if (filters.containsKey('period_id') &&
        {'closings', 'income_statement', 'balance_sheet'}.contains(section) &&
        c['selected_period_id'] != filters['period_id']) {
      throw const FormatException('Periode laporan tidak cocok.');
    }
    for (final row in c['data']) {
      if (filters.containsKey('store_id') &&
          {'productions', 'lots'}.contains(section) &&
          row['store_id'] != filters['store_id']) {
        throw const FormatException('Gudang ERP tidak cocok.');
      }
      if (filters.containsKey('period_id') &&
          {'journals', 'closings'}.contains(section) &&
          row['period_id'] != filters['period_id']) {
        throw const FormatException('Periode jurnal tidak cocok.');
      }
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
    if (c['can_manage'] != true) {
      for (final f in [
        ...c['forms'],
        for (final row in c['data']) ...row['forms'],
      ]) {
        if (!{
          'production',
          'complete',
          'production_cancel',
        }.contains(f['operation'])) {
          throw const FormatException('Tindakan operator ERP tidak cocok.');
        }
      }
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
