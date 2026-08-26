# Deployment Hardening Checklist

## 1) Environment-only DB configuration

This project now expects DB credentials from environment variables:

- `DB_HOST`
- `DB_USER`
- `DB_PASS`
- `DB_NAME`

Do **not** commit DB credentials into source files.

### cPanel example (`.htaccess`)

```apacheconf
SetEnv DB_HOST localhost
SetEnv DB_USER your_db_user
SetEnv DB_PASS your_db_password
SetEnv DB_NAME your_db_name
SetEnv APP_ENV production
```

## 2) Run schema migrations during deploy

Run:

```bash
php scripts/run_migrations.php
```

This executes SQL files in `migrations/` and tracks applied files in `schema_migrations`.

### Multi-tenant upgrade

`003_multi_tenant_saas.sql` creates the tenant, subscription, and tenant-scoping
columns. It assigns all existing data to the initial `renee-farms` workspace.
Run it in a maintenance window after a verified database backup. Every application
query must be deployed with this migration because tenant columns are required for
data isolation.

`004_tenant_modules_and_roles.sql` adds the platform administrator role, per-farm
product modules, and multi-role user assignments. Deploy it immediately after
`003` before provisioning another farm. Use the Platform Farms page with a
`platform_admin` user to create a customer tenant and select its enabled modules.

`005_align_five_role_access_model.sql` replaces the interim role names with the
production model: **Owner / Developer**, **Admin / Farm Owner**, **Ruminant
Manager**, **Poultry Manager**, and **Sales Representative**. It converts the
existing role assignments; deploy it after `004` before allowing users back in.

## 3) Database account security

- Rotate DB password before production deploy.
- Use least privilege for app DB user:
  - `SELECT`, `INSERT`, `UPDATE`, `DELETE`
  - `CREATE`, `ALTER` only during migration windows (or separate migration user).

## 4) Production debug settings

Set:

```bash
APP_ENV=production
```

`dashboard.php` enables `display_errors` only for `APP_ENV=local|development`.
