/// Additional payload checks after each feature's parent/ID/version/status guard.
/// Keeps finance verification out of reusable form components.
bool adminAcknowledgement(
  String path,
  Map<String, dynamic> request,
  Map<String, dynamic> response,
) {
  final row = response['data'];
  if (row is! Map) return false;
  bool same(Map candidate, Iterable<String> keys) =>
      keys.every((key) => candidate[key] == request[key]);
  if (path.endsWith('/contracts')) {
    return same(row, [
      'partner_id',
      'title',
      'mode',
      'starts_on',
      'ends_on',
      'terms',
      'brand_terms',
      'data_scope',
      'facilities',
    ]);
  }
  if (path.endsWith('/vouchers')) {
    return same(row, [
          'code',
          'funding_organization_id',
          'kind',
          'value',
          'maximum_discount',
          'minimum_subtotal',
          'usage_limit',
          'starts_on',
          'ends_on',
          'restore_policy',
          'terms',
        ]) &&
        [
          'store_ids',
          'product_ids',
        ].every((key) => _listEqual(row[key], request[key]));
  }
  if (path.endsWith('/assets')) {
    return same(row, [
      'number',
      'kind',
      'brand',
      'serial_number',
      'condition_note',
      'ownership_reference',
    ]);
  }
  if (path.endsWith('/placements')) {
    return row['placement'] is Map &&
        same(row['placement'], [
          'store_id',
          'contract_id',
          'responsible_name',
          'handed_over_on',
          'condition_note',
          'agreement_reference',
          'evidence_reference',
        ]);
  }
  if (path.endsWith('/commercial/costs')) {
    return same(row, [
      'source_type',
      'source_id',
      'goods_cost',
      'operating_cost',
      'evidence_reference',
      'reason',
    ]);
  }
  if (!path.contains('/billing/')) return true;
  if (path.endsWith('/invoices')) {
    return same(row, [
      'mode',
      'basis',
      'due_on',
      'discount_amount',
      'shipping_amount',
      'tax_basis_points',
      'rounding',
      'note',
    ]);
  }
  if (path.endsWith('/reports')) {
    if (!same(row, ['contract_id', 'period_start', 'period_end', 'note']) ||
        row['lines'] is! List ||
        request['lines'] is! List) {
      return false;
    }
    final requested = request['lines'] as List, actual = row['lines'] as List;
    return requested.length == actual.length &&
        List.generate(requested.length, (i) => i).every(
          (i) =>
              actual[i]['order_line_id'] == requested[i]['order_line_id'] &&
              actual[i]['quantity_pieces'] == requested[i]['quantity_pieces'],
        );
  }
  if (path.endsWith('/payments')) {
    return row['payments'] is List &&
        (row['payments'] as List).any(
          (p) =>
              p is Map &&
              p['invoice_id'] == row['id'] &&
              p['status'] == 'verified' &&
              same(p, [
                'kind',
                'method',
                'amount',
                'reference',
                'paid_on',
                'note',
              ]),
        );
  }
  if (path.endsWith('/reverse')) {
    final id = path.split('/').reversed.elementAt(1);
    return row['payments'] is List &&
        (row['payments'] as List).any(
          (p) =>
              p is Map &&
              p['invoice_id'] == row['id'] &&
              p['id'] == id &&
              p['status'] == 'reversed' &&
              p['reversal_reason'] == request['reason'],
        );
  }
  if (path.endsWith('/credits')) {
    return row['credits'] is List &&
        (row['credits'] as List).any(
          (c) =>
              c is Map &&
              c['invoice_id'] == row['id'] &&
              c['status'] == 'pending' &&
              same(c, ['case_id', 'base_amount', 'tax_amount', 'reason']),
        );
  }
  if (path.contains('/credits/')) {
    final segments = path.split('/'), action = path.split('/').last;
    final id = segments[segments.length - 2];
    return row['credits'] is List &&
        (row['credits'] as List).any(
          (c) =>
              c is Map &&
              c['invoice_id'] == row['id'] &&
              c['id'] == id &&
              c['status'] ==
                  {
                    'accept': 'accepted',
                    'reject': 'rejected',
                    'withdraw': 'withdrawn',
                  }[action],
        );
  }
  return true;
}

bool _listEqual(Object? a, Object? b) =>
    a is List &&
    b is List &&
    a.length == b.length &&
    List.generate(a.length, (i) => i).every((i) => a[i] == b[i]);
