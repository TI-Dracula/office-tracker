<?php
/**
 * Lightweight schema migrations.
 *
 * The app is uploaded to shared hosting by hand, so we cannot rely on the admin
 * running a separate script. Instead bootstrap calls maybe_migrate() on every
 * request: it is a no-op once the DB is current (one cached settings read), and
 * otherwise applies the pending, idempotent steps under an advisory lock.
 *
 * Every step is individually safe to re-run (MySQL auto-commits DDL, so there is
 * no single transaction to roll back) and only ever adds — never drops — data.
 */

const DB_VERSION = 2;

/** Run pending migrations if the stored db_version is behind the code. */
function maybe_migrate(): void {
    try {
        $stored = (int) setting_get('db_version', '1');
    } catch (Throwable $e) {
        return; // settings table not ready (pre-install) — nothing to do
    }
    if ($stored >= DB_VERSION) return;

    $db = db();

    // Only one request should migrate at a time.
    try {
        $got = $db->query("SELECT GET_LOCK('ibctrack_migrate', 5)")->fetchColumn();
        if ((int)$got !== 1) return; // someone else holds the lock — they'll do it
    } catch (Throwable $e) {
        return;
    }

    try {
        // Re-read inside the lock in case another request just finished.
        $cur = (int) ($db->query("SELECT v FROM settings WHERE k = 'db_version'")->fetchColumn() ?: 1);
        if ($cur < DB_VERSION) {
            run_migrations($db);
            $db->prepare("INSERT INTO settings (k, v) VALUES ('db_version', ?)
                          ON DUPLICATE KEY UPDATE v = VALUES(v)")
               ->execute([(string) DB_VERSION]);
        }
    } catch (Throwable $e) {
        error_log('Migration failed: ' . $e->getMessage());
        // Leave db_version behind so a later request retries; steps are idempotent.
    } finally {
        try { $db->query("SELECT RELEASE_LOCK('ibctrack_migrate')"); } catch (Throwable $e) {}
    }
}

/** Apply every additive change up to DB_VERSION. Idempotent. */
function run_migrations(PDO $db): void {
    // 1. Create any missing tables from the canonical schema (CREATE TABLE IF
    //    NOT EXISTS / INSERT IGNORE — existing tables and seed rows are untouched).
    $sql = @file_get_contents(ROOT . '/sql/schema.sql');
    if ($sql !== false && $sql !== '') {
        $sql = preg_replace('/^\s*--.*$/m', '', (string) $sql); // strip comment lines
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            try { $db->exec($stmt); }
            catch (Throwable $e) { error_log('schema stmt skipped during migrate: ' . $e->getMessage()); }
        }
    }

    // 2. Widen users.role to include 'viewer' (CREATE IF NOT EXISTS can't alter an existing table).
    if (!db_column_type_contains($db, 'users', 'role', 'viewer')) {
        $db->exec("ALTER TABLE users MODIFY role ENUM('admin','member','viewer') NOT NULL DEFAULT 'member'");
    }

    // 3. Add projects.project_type for the CS / Fit-out filter.
    if (!db_column_exists($db, 'projects', 'project_type')) {
        $db->exec("ALTER TABLE projects ADD COLUMN project_type VARCHAR(40) DEFAULT NULL AFTER status");
    }
}

/** True if $table.$col exists in the current database. */
function db_column_exists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$table, $col]);
    return (int) $st->fetchColumn() > 0;
}

/** True if $table.$col's column definition contains $needle (e.g. an enum value). */
function db_column_type_contains(PDO $db, string $table, string $col, string $needle): bool {
    $st = $db->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$table, $col]);
    $type = $st->fetchColumn();
    return $type !== false && stripos((string) $type, $needle) !== false;
}
