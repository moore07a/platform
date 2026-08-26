<?php
require_once(__DIR__ . '/../config.php');

$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

$applied = $pdo->query("SELECT filename FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$appliedSet = array_fill_keys($applied, true);

$migrationFiles = glob(__DIR__ . '/../migrations/*.sql');
sort($migrationFiles);

$ignoredMysqlCodes = [1060, 1061, 1050, 1091]; // duplicate column / key / table, missing drop target

function markMigrationApplied(PDO $pdo, string $fileName): void
{
    $insert = $pdo->prepare("INSERT IGNORE INTO schema_migrations (filename) VALUES (?)");
    $insert->execute([$fileName]);
}

function ensureTenantScopedUsernames(PDO $pdo): void
{
    $pdo->exec('SET SESSION lock_wait_timeout = 5');

    $legacyStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'username'");
    $legacyStmt->execute();
    if ((int) $legacyStmt->fetchColumn() > 0) {
        echo "Dropping legacy global users.username index...\n";
        $pdo->exec('ALTER TABLE users DROP INDEX username');
        echo "Dropped legacy global users.username index.\n";
    }

    $tenantStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'uniq_farm_username'");
    $tenantStmt->execute();
    if ((int) $tenantStmt->fetchColumn() === 0) {
        echo "Creating tenant-scoped users index uniq_farm_username...\n";
        $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uniq_farm_username (farm_id, username)');
        echo "Created tenant-scoped users index: uniq_farm_username.\n";
    }
}

foreach ($migrationFiles as $filePath) {
    $fileName = basename($filePath);

    $alreadyApplied = isset($appliedSet[$fileName]);
    $needsRecoveryRun = false;
    if ($alreadyApplied && $fileName === '002_production_cycles.sql') {
        $hasCycleTable = $pdo->query("SHOW TABLES LIKE 'production_cycles'")->rowCount() > 0;
        $hasBatchTable = $pdo->query("SHOW TABLES LIKE 'stock_batches'")->rowCount() > 0;
        $needsRecoveryRun = (!$hasCycleTable || !$hasBatchTable);
    }

    if ($alreadyApplied && !$needsRecoveryRun) {
        echo "Skipping already applied migration: {$fileName}\n";
        continue;
    }
    if ($alreadyApplied && $needsRecoveryRun) {
        echo "Re-running migration due missing objects: {$fileName}\n";
    } else {
        echo "Applying migration: {$fileName}\n";
    }

    if (preg_match('/^00[89]_enforce_tenant_username_scope\.sql$/', $fileName)) {
        echo "Deferring tenant username index enforcement to runner safety check.\n";
        if (!$alreadyApplied) {
            markMigrationApplied($pdo, $fileName);
        }
        continue;
    }

    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException("Failed to read migration file: {$fileName}");
    }

    // Drop SQL single-line comments before splitting so comment+statement blocks still execute.
    $sqlWithoutComments = preg_replace('/^\\s*--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', (string)$sqlWithoutComments)));

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        foreach ($statements as $statement) {
            if ($statement === '') {
                continue;
            }

            try {
                if (preg_match('/^\s*SELECT\b/i', $statement)) {
                    $pdo->query($statement)->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $pdo->exec($statement);
                }
            } catch (PDOException $e) {
                $driverCode = (int) ($e->errorInfo[1] ?? 0);
                if (!in_array($driverCode, $ignoredMysqlCodes, true)) {
                    throw $e;
                }
            }
        }

        if (!$alreadyApplied) {
            markMigrationApplied($pdo, $fileName);
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        echo "Applied migration: {$fileName}\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

ensureTenantScopedUsernames($pdo);

echo "Migrations complete.\n";
