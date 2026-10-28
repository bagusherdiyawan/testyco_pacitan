class AssignedStore {
  const AssignedStore({
    required this.id,
    required this.name,
    required this.active,
    this.role,
  });
  final String id;
  final String name;
  final bool active;
  final String? role;
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
    name: json['name'] as String,
    active: json['active'] as bool,
    role: json['role'] as String?,
  );
}
