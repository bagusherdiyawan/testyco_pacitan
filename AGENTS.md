# Development guidance

Read README.md, docs/architecture/decisions.md and docs/product/requirements.md before changing business behavior. Use the existing checkout; cloud tasks are isolated, so create no worktree unless requested.

- API and web are separate Laravel applications; web must not query API database directly.
- Add modules through explicit provider/feature registries. Flags never replace backend authorization.
- Keep domain invariants out of Blade/Flutter components. Use atomic design and shared visual tokens.
- Each tenant-owned endpoint needs cross-tenant and denied-permission tests before activation.
- Do not invent decisions for offline, QRIS, consignment or negative stock; consult product decisions.
- Run scripts/check; for web/API changes also start Compose and run scripts/smoke.py.
- Do not disable TLS/signature/checksum verification. Keep credentials out of source, logs and fixtures.
- Identity/catalog and initial ordering, fulfillment, batch and inventory ledger (intake/dispatch/receipt) are implemented. POS sales, cooking/counting, shifts, returns/replacements and offline remain unimplemented. Read docs/supply-workflow.md for the exact scope.
- Run python3 scripts/smoke-identity.py for API/web identity or supply-workflow changes; it uses isolated temporary containers/database.
- Development uses SQLite; do not claim PostgreSQL or device-release builds are verified.
