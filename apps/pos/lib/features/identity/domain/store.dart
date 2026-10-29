class AssignedStore {
  const AssignedStore({
    required this.id,
    required this.name,
    required this.active,
    this.role,
    this.organizationId = '',
  });
  final String id, organizationId;
  final String name;
  final bool active;
  final String? role;
  bool get canManageShifts =>
      const {'owner', 'admin', 'partner_lead', 'store_manager'}.contains(role);
  bool get canViewReports =>
      organizationId.isNotEmpty &&
      const {'owner', 'admin', 'partner_lead', 'store_manager'}.contains(role);
  bool get canControlStock => const {
    'owner',
    'admin',
    'partner_lead',
    'store_manager',
    'warehouse',
  }.contains(role);
  bool get canSell => const {
    'owner',
    'admin',
    'partner_lead',
    'store_manager',
    'cashier',
  }.contains(role);
  bool get canOrder => role != null && role != 'cashier';
  factory AssignedStore.fromJson(Map<String, dynamic> json) => AssignedStore(
    id: json['id'] as String,
    organizationId: json['organization_id'] as String? ?? '',
    name: json['name'] as String,
    active: json['active'] as bool,
    role: json['role'] as String?,
  );
}
