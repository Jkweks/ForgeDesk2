<?php

declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';

/**
 * @return list<string>
 */
function databaseHealthAvailableMigrations(): array
{
    $root = __DIR__ . '/../admin_service';

    if (!is_dir($root)) {
        return [];
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $migrations = [];

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if ($file->getExtension() !== 'py' || $file->getFilename() === '__init__.py') {
            continue;
        }

        $path = $file->getPath();
        if (strpos($path, DIRECTORY_SEPARATOR . 'migrations') === false) {
            continue;
        }

        $appName = basename(dirname(dirname($file->getRealPath())));
        $migrationName = basename($file->getFilename(), '.py');
        $migrations[] = $appName . ':' . $migrationName;
    }

    sort($migrations);

    return $migrations;
}

$dbError = null;
$serverVersion = 'Unknown';
$connectionStatus = 'Disconnected';
$databaseName = $app['database']['name'];
$availableMigrations = databaseHealthAvailableMigrations();
$appliedMigrations = [];
$pendingMigrations = $availableMigrations;
$appliedMigrationError = null;
$storageStats = [
    'database_size' => 'Unknown',
    'table_count' => null,
    'largest_tables' => [],
];

try {
    $db = db($app['database']);
    $connectionStatus = 'Connected';

    $hasMigrationsTable = false;
    try {
        $statement = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table"
        );
        $statement->execute([':table' => 'django_migrations']);
        $hasMigrationsTable = ((int) $statement->fetchColumn()) > 0;
    } catch (\Throwable) {
        // Leave $hasMigrationsTable as false if table detection fails.
    }

    try {
        $serverVersion = (string) ($db->query('SHOW server_version')->fetchColumn() ?: $serverVersion);
    } catch (\Throwable) {
        try {
            $serverVersion = (string) ($db->query('SELECT version()')->fetchColumn() ?: $serverVersion);
        } catch (\Throwable) {
            // Ignore server version errors to keep the page rendering.
        }
    }

    if ($hasMigrationsTable) {
        try {
            $appliedMigrations = $db->query('SELECT app, name FROM django_migrations ORDER BY app, name')->fetchAll() ?: [];
            $appliedMigrations = array_map(
                static fn (array $row): string => $row['app'] . ':' . $row['name'],
                $appliedMigrations
            );
        } catch (\Throwable $exception) {
            $appliedMigrationError = 'Unable to load applied migrations: ' . $exception->getMessage();
        }
    } else {
        $appliedMigrationError = 'Django migrations table not found; run admin_service manage.py migrate.';
    }

    try {
        $storageStats['database_size'] = (string) ($db->query('SELECT pg_size_pretty(pg_database_size(current_database())) AS size')->fetchColumn() ?: $storageStats['database_size']);
    } catch (\Throwable) {
        // Ignore storage size errors.
    }

    try {
        $storageStats['table_count'] = (int) ($db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema')")->fetchColumn() ?: 0);
    } catch (\Throwable) {
        $storageStats['table_count'] = null;
    }

    try {
        $storageStats['largest_tables'] = $db->query(
            'SELECT schemaname, relname, pg_size_pretty(pg_total_relation_size(relid)) AS total_size, n_live_tup
             FROM pg_catalog.pg_statio_user_tables
             ORDER BY pg_total_relation_size(relid) DESC
             LIMIT 5'
        )->fetchAll();
    } catch (\Throwable) {
        $storageStats['largest_tables'] = [];
    }

    $pendingMigrations = array_values(array_diff($availableMigrations, $appliedMigrations));
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $isDatabaseHealth = ($item['label'] ?? '') === 'Database Health';
        $item['active'] = $isDatabaseHealth;

        if ($isDatabaseHealth) {
            $item['badge'] = $dbError === null ? 'Live' : 'Error';
            $item['badge_class'] = $dbError === null ? 'success' : 'danger';
        }
    }
}
unset($groupItems, $item);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> Database Health</title>
  <link rel="stylesheet" href="css/dashboard.css" />
</head>
<body class="has-sidebar-toggle">
  <div class="layout">
    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>

    <header class="topbar">
      <button
        class="topbar-toggle"
        type="button"
        data-sidebar-toggle
        aria-controls="app-sidebar"
        aria-expanded="false"
        aria-label="Toggle navigation"
      >
        <span aria-hidden="true"><?= icon('menu') ?></span>
      </button>
      <div class="page-title">
        <h1>Database Health</h1>
        <p class="small">Connection status, migrations, and storage diagnostics for <?= e($databaseName) ?>.</p>
      </div>
      <div class="topbar-status">
        <span class="badge <?= $dbError === null ? 'success' : 'danger' ?>">
          <?= $dbError === null ? 'Live' : 'Error' ?>
        </span>
      </div>
    </header>

    <main class="content">
      <section class="metrics" aria-label="Database status metrics">
        <article class="metric<?= $dbError === null ? ' accent' : '' ?>">
          <div class="metric-header">
            <span>Connection</span>
          </div>
          <p class="metric-value"><?= e($connectionStatus) ?></p>
          <p class="metric-delta small">
            <?= $dbError === null ? 'Connected to ' . e($databaseName) : e($dbError) ?>
          </p>
        </article>
        <article class="metric">
          <div class="metric-header">
            <span>PostgreSQL Version</span>
          </div>
          <p class="metric-value"><?= e($serverVersion) ?></p>
          <p class="metric-delta small">Reported by the database server.</p>
        </article>
        <article class="metric">
          <div class="metric-header">
            <span>Migrations</span>
          </div>
          <p class="metric-value"><?= e((string) count($appliedMigrations)) ?> / <?= e((string) count($availableMigrations)) ?></p>
          <p class="metric-delta small">
            <?= e(count($pendingMigrations) === 0 ? 'All migrations applied' : count($pendingMigrations) . ' pending') ?>
          </p>
        </article>
        <article class="metric">
          <div class="metric-header">
            <span>Database Size</span>
          </div>
          <p class="metric-value"><?= e($storageStats['database_size']) ?></p>
          <p class="metric-delta small">
            <?= $storageStats['table_count'] !== null ? e((string) $storageStats['table_count'] . ' user tables detected') : 'Table count unavailable' ?>
          </p>
        </article>
      </section>

      <section class="panel" aria-labelledby="migrations-title">
        <header>
          <div>
            <h2 id="migrations-title">Migration Status</h2>
            <p class="small">Tracking Django migrations detected in admin_service versus applied records.</p>
          </div>
          <?php if ($appliedMigrationError !== null): ?>
            <span class="badge danger" aria-live="polite">Check failed</span>
          <?php elseif ($pendingMigrations !== []): ?>
            <span class="badge warning" aria-live="polite">Pending</span>
          <?php else: ?>
            <span class="badge success" aria-live="polite">Current</span>
          <?php endif; ?>
        </header>

        <div class="grid two-col">
          <div>
            <h3 class="h6">Available migrations (<?= e((string) count($availableMigrations)) ?>)</h3>
            <ul class="list">
              <?php foreach ($availableMigrations as $migration): ?>
                <li class="list-item">
                  <span><?= e($migration) ?></span>
                  <?php if (in_array($migration, $appliedMigrations, true)): ?>
                    <span class="badge success">Applied</span>
                  <?php elseif ($dbError !== null || $appliedMigrationError !== null): ?>
                    <span class="badge">Unknown</span>
                  <?php else: ?>
                    <span class="badge warning">Pending</span>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
              <?php if ($availableMigrations === []): ?>
                <li class="list-item">No migration files found in admin_service/*/migrations.</li>
              <?php endif; ?>
            </ul>
          </div>
          <div>
            <h3 class="h6">Applied migrations</h3>
            <?php if ($appliedMigrationError !== null): ?>
              <div class="alert danger" role="alert">
                <?= e($appliedMigrationError) ?>
              </div>
            <?php elseif ($appliedMigrations === []): ?>
              <p class="small">No applied migrations detected.</p>
            <?php else: ?>
              <ul class="list">
                <?php foreach ($appliedMigrations as $migration): ?>
                  <li class="list-item">
                    <span><?= e($migration) ?></span>
                    <span class="badge success">Applied</span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <section class="panel" aria-labelledby="storage-title">
        <header>
          <div>
            <h2 id="storage-title">Storage &amp; Table Stats</h2>
            <p class="small">Quick visibility into table sizes and row counts.</p>
          </div>
        </header>

        <div class="grid two-col">
          <div>
            <h3 class="h6">Database size</h3>
            <p class="lead"><?= e($storageStats['database_size']) ?></p>
            <?php if ($storageStats['table_count'] !== null): ?>
              <p class="small"><?= e((string) $storageStats['table_count']) ?> user tables</p>
            <?php else: ?>
              <p class="small">Table counts unavailable.</p>
            <?php endif; ?>
          </div>
          <div>
            <h3 class="h6">Largest tables</h3>
            <?php if ($storageStats['largest_tables'] === []): ?>
              <p class="small">No table statistics available.</p>
            <?php else: ?>
              <div class="table-wrapper">
                <table>
                  <thead>
                    <tr>
                      <th scope="col">Table</th>
                      <th scope="col" class="text-right">Rows</th>
                      <th scope="col" class="text-right">Size</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($storageStats['largest_tables'] as $table): ?>
                      <tr>
                        <td><?= e($table['schemaname'] . '.' . $table['relname']) ?></td>
                        <td class="text-right"><?= e(number_format((int) $table['n_live_tup'])) ?></td>
                        <td class="text-right"><?= e($table['total_size']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </section>
    </main>
  </div>
</body>
</html>
