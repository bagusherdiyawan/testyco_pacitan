# Development guidance

Read README.md, docs/architecture/decisions.md and docs/product/requirements.md before changing business behavior. Use the existing checkout; cloud tasks are isolated, so create no worktree unless requested.

- API and web are separate Laravel applications; web must not query API database directly.
- Add modules through explicit provider/feature registries. Flags never replace backend authorization.
- Keep domain invariants out of Blade/Flutter components. Use atomic design and shared visual tokens.
- Each tenant-owned endpoint needs cross-tenant and denied-permission tests before activation.
- Do not invent decisions for offline, QRIS, consignment or negative stock; consult product decisions.
- Run scripts/check; for web/API changes also start Compose and run scripts/smoke.py.
- Do not disable TLS/signature/checksum verification. Keep credentials out of source, logs and fixtures.
- Identity/catalog and initial ordering, fulfillment, batch and inventory ledger (intake/dispatch/receipt) are implemented. Stock opening/cooking and independent count/waste review are implemented on web and native Flutter; see docs/stock-control.md. Online cash POS and cashier shifts are implemented; see docs/cash-pos.md. Refunds, returns/replacements, QRIS and offline remain unimplemented. Read docs/supply-workflow.md for the exact scope.
- Run python3 scripts/smoke-identity.py for API/web identity or supply-workflow changes; it uses isolated temporary containers/database.
- All stock balance changes must use StockLedger.apply inside a transaction and increment stock_revision; do not bypass snapshot guards or independent-review authorization.
- Native ledger history is read-only and staff-scoped; use historical batch snapshots, signed base-piece deltas and explicit UTC occurred_at for WIB. Filtered rows are never current/running balance; source_id is not request UUID. See docs/native-stock-ledger.md.
- Native warehouse intake requires Inventory intake catalogue metadata and server preview/review_hash. Physical packs/date/batch/note have no defaults; preserve immutable UUID/path/body/review on unknown outcomes. Show reviewed addition, never replayed batch balance as current. See docs/native-warehouse-intake.md.
- Native warehouse uses scoped Fulfillment context can_process/can_dispatch, selected source ID and original order version. Remaining quota subtracts sent packs, never good receipts; do not auto-replace damage. Keep immutable nested UUID/path/body/review on unknown outcomes; see docs/native-warehouse.md.
- Native shift cash uses GET detail can_record_movement, original reviewed version and immutable UUID/path/body; can_manage alone never enables mutation. Unknown outcomes block refresh/pop/new operations; see docs/native-shift-cash.md.
- Native amendment uses recipient_actions.amend and original order ID/version. Only awaiting/rejected approvers may edit; never expand into postapproval changes/reapproval. Review hash binds order/version; reset stale drafts only after explicit user choice. See docs/native-order-amend.md.
- Native order entry must preview with authoritative server pricing, then submit its immutable payload/UUID/review_hash. Never infer SKU mapping by name, trust client totals, rotate an unknown submission UUID, or create auto-orders from minimum-stock warnings. Review hash is a concurrency check, not signed pricing/authorization. See docs/native-order-entry.md.
- Native recipient order decisions/receipts use GET detail recipient_actions; never infer authorization from legacy can_approve, local role alone or status alone. Receipts require every shipment line, explicit good/damaged packs (including zero), condition notes for discrepancies and original order version. Retain immutable nested UUID/path/body on uncertain response; see docs/native-ordering.md.
- Native stock controls retain exact UUID/path/body on uncertain network outcomes while the page stays alive. Do not replace pending operations, silently advance the selected revision or bypass independent review. Refresh requires reselecting and physically checking the snapshot; see docs/stock-control.md for restart limits.
- Native minimum editing uses exact product/store detail and can_manage/version metadata, never list position/SKU name or local role alone. Explicit zero stays enabled; disable sends null. Retain immutable UUID/path/body on unknown outcomes; see docs/native-stock-minimum.md.
- Minimum-stock warnings are live read projections, not durable push notifications; see docs/stock-minimums.md. Do not introduce automatic ordering, payment requests or default business thresholds. Configuration changes require scoped manager permission, current version, UUID and audit.
- Reporting is cash POS only: use Sales CashSalesReadModel, enforce organization membership and store assignment for summary/products/export, and never mix B2B/transfer/debt/cash movements into revenue. CSV must reject over 10,000 rows and neutralize formula-like text. See docs/cash-reporting.md.
- Sales must use RetailPricing, SaleStock and ShiftBook; do not write stock/cash balances across modules directly. Pending sale intents are not reservations.
- Development uses SQLite; do not claim PostgreSQL or device-release builds are verified.
