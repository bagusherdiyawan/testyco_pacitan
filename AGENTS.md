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
- Native stock controls retain exact UUID/path/body on uncertain network outcomes while the page stays alive. Do not replace pending operations, silently advance the selected revision or bypass independent review. Refresh requires reselecting and physically checking the snapshot; see docs/stock-control.md for restart limits.
- Minimum-stock warnings are live read projections, not durable push notifications; see docs/stock-minimums.md. Do not introduce automatic ordering, payment requests or default business thresholds. Configuration changes require scoped manager permission, current version, UUID and audit.
- Sales must use RetailPricing, SaleStock and ShiftBook; do not write stock/cash balances across modules directly. Pending sale intents are not reservations.
- Development uses SQLite; do not claim PostgreSQL or device-release builds are verified.
