# Codebase audit

## Scope and method

This review covered every tracked PHP, JavaScript, CSS, SQL, and documentation file. Checks included PHP parsing, the repository contract suite, tenant-boundary searches, mutation/CSRF review, authorization review, credential handling, output escaping, and schema/runtime migration behavior.

## Fixed findings

1. **Critical — cross-tenant chart disclosure.** Every query in `api/get_chart_data.php` read all farms. Queries now bind the active `farm_id`, including the stock subquery. Profit/loss expenses are pre-aggregated, which also prevents sales-to-expenses row multiplication from inflating totals.
2. **High — unauthorized daily-record deletion.** Any authenticated account, including a viewer, could call the deletion endpoint. It now requires poultry write access.
3. **High — unauthorized and inconsistent stock updates.** The endpoint did not enforce module/role access and trusted the caller's `farm_type`. It now authorizes against, and records, the type stored on the tenant-owned inventory item.
4. **High — category CSRF.** Category creation and destructive category deletion had no request token validation. Both forms and handlers now enforce the session CSRF token.
5. **High — partial production-cycle creation.** Ruminant opening records explicitly inserted `NULL` into the required water-consumption column, and the cycle row was committed before that failure. The seed now uses zero and cycle creation is atomic, so either both records are stored or neither is.
6. **Medium — inconsistent dashboard ticker visibility.** Dashboard access was inferred with a separate role chain that classified fallback roles as ruminant and hid the ticker from sales representatives. It now uses the shared module-aware resolver and renders active-cycle statistics for every entitled dashboard role.
7. **Critical — specialist expense tenant leakage.** Broiler, layer, and ruminant expense pages neither filtered reads by `farm_id` nor assigned `farm_id` on inserts. All three now bind the active farm and tenant-scope their user joins.
8. **High — cross-tenant inventory ID mutation.** Forged item/category IDs could reach destructive inventory operations without a farm predicate. Category ownership is now validated and item/history mutations require both the record ID and active `farm_id`.
9. **High — cross-tenant cycle relationship.** A forged cycle ID could link a stock batch belonging to one farm to another farm's cycle. The relationship now requires an active cycle owned by the current farm.
10. **High — partial farm provisioning.** Logo validation occurred after the farm row was inserted, so databases without transactional table support could retain a farm with no modules or admin. Logo data is now validated first, failed provisioning performs compensating cleanup, module/role inserts are verified, and edit mode can recreate a missing admin account.

## Recommended follow-up

1. **Normalize API responses and validation.** Read endpoints currently mix response shapes and status codes. Add shared typed validators for IDs, ISO dates, enums, positive quantities, and bounded ranges, then use `send_json()` consistently.
2. **Avoid database details in client errors.** `config.php` and several mutation endpoints return exception messages. Log a correlation ID server-side and return a generic production message.
3. **Move runtime DDL out of web requests.** The compatibility helpers execute `ALTER TABLE` statements. Deploy schema changes only through the migration runner and leave runtime code to verify required schema versions.
4. **Strengthen session lifecycle.** Regenerate the session ID after login, rotate CSRF tokens on authentication changes, and configure an explicit cookie lifetime and trusted-proxy HTTPS policy.
5. **Add database-backed integration tests.** Contract checks are useful static guards but cannot prove tenant isolation. Seed two farms and assert that every endpoint reads and mutates only its active tenant.
6. **Add automated static analysis.** Introduce PHPStan or Psalm, PHP-CS-Fixer, and a CI job running syntax, contracts, migrations against a disposable MySQL instance, and browser smoke tests.
7. **Review destructive category semantics.** Deleting a category currently permanently removes its inventory items and transaction history. Prefer a soft-delete/archive workflow, or require explicit confirmation with an audit record.
8. **Set security headers centrally.** Add a Content Security Policy, frame restrictions, referrer policy, MIME sniffing protection, and a deliberate CDN allowlist; then remove inline scripts/styles or use nonces.
