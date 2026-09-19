<?php
// Standalone bootstrap/readiness helper; deliberately avoids loading LocalSettings.
declare(strict_types=1);
function requiredEnv(string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '' || str_contains($value, 'CHANGE_ME')) {
        throw new RuntimeException("Missing or placeholder environment variable: $name");
    }
    return $value;
}
function connection() {
    $parts = [];
    foreach (['host' => 'HOSTNAME', 'port' => 'PORT', 'dbname' => 'DATABASE_NAME',
              'user' => 'LOGIN', 'password' => 'PASSWORD'] as $key => $env) {
        $parts[] = $key . "='" . str_replace(["\\", "'"], ["\\\\", "\\'"], requiredEnv($env)) . "'";
    }
    $db = @pg_connect(implode(' ', $parts) . ' connect_timeout=5');
    if (!$db) {
        throw new RuntimeException('Database connection unavailable.');
    }
    return $db;
}
function query($db, string $sql, array $params = []) {
    $result = @pg_query_params($db, $sql, $params);
    if ($result === false) {
        throw new RuntimeException('Database operation failed; inspect PostgreSQL logs.');
    }
    return $result;
}
function ready($db): bool {
    $exists = pg_fetch_result(query($db, "SELECT to_regclass('cloud_lab.bootstrap') IS NOT NULL"), 0, 0);
    if ($exists !== 't') {
        return false;
    }
    return pg_fetch_result(query($db, 'SELECT count(*) FROM cloud_lab.bootstrap WHERE ready = true'), 0, 0) === '1';
}
function runCommand(array $command): void {
    // Installer errors may contain credentials. Keep raw output in ephemeral /tmp,
    // never emit it to Kubernetes logs. Inspection requires an explicit admin action.
    $log = tempnam('/tmp', 'mediawiki-install-');
    chmod($log, 0600);
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'],
        1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, '/var/www/html');
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Maintenance command failed. Do not blindly reinstall; see runbook.');
    }
    unlink($log);
}
try {
    $mode = $argv[1] ?? '--install';
    if (!in_array($mode, ['--install', '--wait', '--check', '--backup-point'], true)) {
        throw new RuntimeException('Unknown mode.');
    }
    if ($mode === '--wait') {
        for ($attempt = 0; $attempt < 180; $attempt++) {
            try {
                $db = connection();
                if (ready($db)) { exit(0); }
                pg_close($db);
            } catch (RuntimeException $e) {
                // Database/role provisioning is asynchronous.
            }
            sleep(5);
        }
        throw new RuntimeException('Initialization did not complete within 15 minutes.');
    }
    $db = connection();
    if ($mode === '--check' || $mode === '--backup-point') {
        if (!ready($db)) { exit(1); }
        if ($mode === '--backup-point') {
            $point = pg_fetch_assoc(query($db,
                "SELECT clock_timestamp() AT TIME ZONE 'UTC' AS utc, pg_current_wal_lsn() AS lsn"));
            echo json_encode($point, JSON_THROW_ON_ERROR) . PHP_EOL;
        }
        exit(0);
    }
    // Serialize independently submitted initialization Jobs on this database.
    query($db, 'SELECT pg_advisory_lock(71431, 1)');
    if (ready($db)) {
        echo "Already initialized; leaving accounts and passwords unchanged.\n";
        exit(0);
    }
    $count = pg_fetch_result(query($db,
        "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'mediawiki'"), 0, 0);
    if ($count !== '0') {
        throw new RuntimeException('Existing schema without completion marker; manual recovery required.');
    }
    requiredEnv('MW_SECRET_KEY');
    requiredEnv('MW_UPGRADE_KEY');
    $admin = requiredEnv('MW_ADMIN_NAME');
    requiredEnv('MW_ADMIN_PASSWORD');
    if (file_exists('/var/www/html/LocalSettings.php')) {
        throw new RuntimeException('Installation Job must not mount LocalSettings at document root.');
    }
    $conf = '/tmp/mediawiki-generated';
    if (!is_dir($conf) && !mkdir($conf, 0700)) {
        throw new RuntimeException('Cannot create temporary configuration directory.');
    }
    $dbPass = tempnam('/tmp', 'mw-db-');
    $adminPass = tempnam('/tmp', 'mw-admin-');
    chmod($dbPass, 0600);
    chmod($adminPass, 0600);
    file_put_contents($dbPass, requiredEnv('PASSWORD'));
    file_put_contents($adminPass, requiredEnv('MW_ADMIN_PASSWORD'));
    try {
        runCommand(['php', 'maintenance/run.php', 'install', '--dbtype=postgres',
            '--dbserver=' . requiredEnv('HOSTNAME'), '--dbport=' . requiredEnv('PORT'),
            '--dbname=' . requiredEnv('DATABASE_NAME'), '--dbuser=' . requiredEnv('LOGIN'),
            '--dbpassfile=' . $dbPass, '--dbschema=mediawiki', '--lang=' . requiredEnv('MW_LANGUAGE'),
            '--server=' . requiredEnv('MW_SERVER'), '--scriptpath=', '--skins=Vector',
            '--confpath=' . $conf, '--passfile=' . $adminPass, requiredEnv('MW_SITE_NAME'), $admin]);
    } finally {
        unlink($dbPass);
        unlink($adminPass);
        if (is_file($conf . '/LocalSettings.php')) { unlink($conf . '/LocalSettings.php'); }
    }
    runCommand(['php', 'maintenance/run.php', 'update', '--quick',
        '--conf=/etc/mediawiki/LocalSettings.php']);
    // Fresh install must contain exactly the one seeded account. Do this before
    // opening the application, so no live user/cache state can race the grant.
    $users = query($db, 'SELECT user_id FROM mediawiki."user"');
    if (pg_num_rows($users) !== 1) {
        throw new RuntimeException('Expected exactly one initial administrator.');
    }
    $id = pg_fetch_result($users, 0, 0);
    query($db, 'BEGIN');
    query($db, 'INSERT INTO mediawiki.user_groups (ug_user, ug_group) VALUES ($1, $2) ON CONFLICT DO NOTHING',
        [$id, 'accountcreator']);
    query($db, 'CREATE SCHEMA IF NOT EXISTS cloud_lab');
    query($db, 'CREATE TABLE cloud_lab.bootstrap (ready boolean PRIMARY KEY CHECK (ready), installed_at timestamptz NOT NULL DEFAULT now())');
    query($db, 'INSERT INTO cloud_lab.bootstrap (ready) VALUES (true)');
    query($db, 'COMMIT');
    echo "Initialization complete.\n";
} catch (Throwable $e) {
    // Avoid exception traces, SQL errors, or connection strings in logs.
    fwrite(STDERR, $e instanceof RuntimeException ? $e->getMessage() . "\n" : "Bootstrap failed; see runbook.\n");
    exit(1);
}
