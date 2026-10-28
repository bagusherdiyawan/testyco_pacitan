import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'core/modules/feature_module.dart';
import 'core/network/api_client.dart';
import 'features/identity/application/session.dart';
import 'features/identity/domain/store.dart';
import 'features/identity/presentation/login_page.dart';
import 'features/identity/presentation/store_picker_page.dart';
import 'features/catalog/catalog_module.dart';
import 'features/catalog/data/catalog_repository.dart';
import 'features/sales/sales_module.dart';
import 'features/sales/data/pos_repository.dart';
import 'features/inventory/inventory_module.dart';
import 'features/inventory/data/inventory_repository.dart';
import 'features/ordering/ordering_module.dart';
import 'features/reporting/reporting_module.dart';
import 'features/reporting/data/cash_report_repository.dart';
import 'features/ordering/data/orders_repository.dart';
import 'design_system/templates/responsive_shell.dart';
import 'design_system/tokens.dart';

void main() {
  const url = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1/',
  );
  final uri = Uri.parse(url);
  if (!url.endsWith('/') ||
      !uri.hasAuthority ||
      (uri.scheme != 'http' && uri.scheme != 'https') ||
      (kReleaseMode && uri.scheme != 'https')) {
    throw StateError(
      'API_BASE_URL must be an absolute URL ending in /; HTTPS is required for release.',
    );
  }
  runApp(TestycoApp(session: Session(ApiClient(baseUrl: uri))));
}

class TestycoApp extends StatefulWidget {
  const TestycoApp({required this.session, super.key});
  final Session session;
  @override
  State<TestycoApp> createState() => _TestycoAppState();
}

class _TestycoAppState extends State<TestycoApp> {
  AssignedStore? _store;
  @override
  void initState() {
    super.initState();
    widget.session.addListener(_changed);
  }

  void _changed() {
    if (!widget.session.authenticated) _store = null;
    setState(() {});
  }

  @override
  void dispose() {
    widget.session.removeListener(_changed);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'Testyco POS',
    debugShowCheckedModeBanner: false,
    theme: ThemeData(
      colorScheme: ColorScheme.fromSeed(seedColor: DesignTokens.primary),
      useMaterial3: true,
    ),
    home: !widget.session.authenticated
        ? LoginPage(session: widget.session)
        : _store == null
        ? StorePickerPage(
            session: widget.session,
            onSelected: (s) => setState(() => _store = s),
          )
        : ResponsiveShell(
            key: ValueKey(_store!.id),
            title: _store!.name,
            actions: [
              IconButton(
                tooltip: 'Ganti toko',
                icon: const Icon(Icons.store),
                onPressed: () => setState(() => _store = null),
              ),
              IconButton(
                tooltip: 'Keluar',
                icon: const Icon(Icons.logout),
                onPressed: () async {
                  try {
                    await widget.session.logout();
                  } catch (_) {
                    /* Local session cleared. */
                  }
                },
              ),
            ],
            registry: ModuleRegistry([
              CatalogModule(
                repository: CatalogRepository(widget.session.api),
                storeId: _store!.id,
              ),
              if (_store!.canSell &&
                  const bool.fromEnvironment(
                    'FEATURE_SALES',
                    defaultValue: true,
                  ))
                SalesModule(
                  repository: PosRepository(widget.session.api),
                  storeId: _store!.id,
                ),
              if (const bool.fromEnvironment(
                'FEATURE_INVENTORY',
                defaultValue: true,
              ))
                InventoryModule(
                  repository: InventoryRepository(widget.session.api),
                  canControl: _store!.canControlStock,
                  storeId: _store!.id,
                ),
              if (_store!.canOrder &&
                  const bool.fromEnvironment(
                    'FEATURE_ORDERING',
                    defaultValue: true,
                  ))
                OrderingModule(
                  repository: OrdersRepository(widget.session.api),
                  storeId: _store!.id,
                ),
              if (_store!.canViewReports &&
                  const bool.fromEnvironment(
                    'FEATURE_REPORTING',
                    defaultValue: true,
                  ))
                ReportingModule(
                  repository: CashReportRepository(widget.session.api),
                  organizationId: _store!.organizationId,
                  storeId: _store!.id,
                ),
            ]),
          ),
  );
}
