<?php

/**
 * CI diagnostic: report what seeding actually produced.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * TEMPORARY. Delete once openregister#2291 is settled.
 *
 * The E2E job fails with 150 `relation "oc_openregister_table_15_<schema>" does
 * not exist` errors, but a faithful local replication of the same sequence — same
 * openregister commit, same pipelinq, fresh Nextcloud + postgres — provisions
 * every table and cannot reproduce it. So the evidence has to come from CI.
 *
 * This distinguishes the three possibilities that look identical from the outside:
 *
 *   1. the register/schemas were never created      -> registers empty
 *   2. schemas exist but tables were never made     -> MISSING rows below
 *   3. both existed and something dropped the table -> nothing missing here,
 *                                                      but the run still errors
 *
 * It also prints the import markers, because OpenRegister tracks import state in
 * BOTH the database and NC appconfig (`imported_config_<app>_version`/`_hash`).
 * Truncating the tables alone does not reset it — that asymmetry manufactured a
 * phantom "0 schemas created" while I was investigating locally, and it is
 * exactly the kind of thing worth seeing rather than assuming.
 *
 * Read-only: this inspects, it never writes.
 */

// Find the Nextcloud root by walking up from this file rather than hardcoding it.
// The container puts it at /var/www/html; CI checks it out under
// /home/runner/work/<repo>/<repo>/server. Hardcoding the container path made this
// script fatal in CI, which is the one place it needed to run.
$ncRoot = null;
for ($dir = __DIR__, $i = 0; $i < 8; $i++) {
    $dir = dirname($dir);
    if (is_file($dir.'/lib/base.php') === true) {
        $ncRoot = $dir;
        break;
    }
}

if ($ncRoot === null) {
    fwrite(STDERR, 'dump-seed-state: could not locate lib/base.php above '.__DIR__.PHP_EOL);
    exit(1);
}

require_once $ncRoot.'/lib/base.php';

\OC_App::loadApp('openregister');
\OC_App::loadApp('pipelinq');

/** @var \OCP\IDBConnection $db */
$db = \OC::$server->get(\OCP\IDBConnection::class);

echo PHP_EOL.'==================== SEED STATE DIAGNOSTIC ===================='.PHP_EOL;

// ---- 1. Import markers (the state a table-truncation does NOT clear). --------
$appConfig = \OC::$server->get(\OCP\IAppConfig::class);
echo PHP_EOL.'-- import markers (appconfig) --'.PHP_EOL;
$found = 0;
foreach ($appConfig->getKeys('openregister') as $key) {
    if (str_starts_with($key, 'imported_config_') === true) {
        $found++;
        echo sprintf("  %-56s = %s%s", $key, $appConfig->getValueString('openregister', $key), PHP_EOL);
    }
}

if ($found === 0) {
    echo '  (none)'.PHP_EOL;
}

// ---- 2. Configuration rows. -------------------------------------------------
echo PHP_EOL.'-- oc_openregister_configurations --'.PHP_EOL;
try {
    $q = $db->getQueryBuilder();
    $q->select('*')->from('openregister_configurations');
    $rows = $q->executeQuery()->fetchAll();
    if ($rows === []) {
        echo '  (none)'.PHP_EOL;
    }

    foreach ($rows as $row) {
        // Column is `app`, not `app_id` — verified against the live schema.
        echo sprintf(
            "  app=%-22s version=%-10s title=%s%s",
            (string) ($row['app'] ?? '?'),
            (string) ($row['version'] ?? '?'),
            substr((string) ($row['title'] ?? ''), 0, 40),
            PHP_EOL
        );
    }
} catch (\Throwable $e) {
    echo '  ERROR reading configurations: '.$e->getMessage().PHP_EOL;
}

// ---- 3. Registers, schemas, and whether each physical table exists. ----------
echo PHP_EOL.'-- registers / schemas / tables --'.PHP_EOL;

$missingTotal = 0;
$schemaTotal  = 0;

try {
    $q = $db->getQueryBuilder();
    $q->select('id', 'slug', 'schemas')->from('openregister_registers')->orderBy('id');
    $registers = $q->executeQuery()->fetchAll();

    if ($registers === []) {
        echo '  !! NO REGISTERS AT ALL — the import never ran or created nothing'.PHP_EOL;
    }

    foreach ($registers as $register) {
        $registerId = (int) $register['id'];
        $rawSchemas = (string) ($register['schemas'] ?? '[]');
        $schemaIds  = array_filter(array_map('trim', explode(',', trim($rawSchemas, '[]"'))));

        $missing = [];
        foreach ($schemaIds as $schemaId) {
            $schemaId = (int) trim((string) $schemaId, '"\' ');
            if ($schemaId === 0) {
                continue;
            }

            $schemaTotal++;
            $table = 'oc_openregister_table_'.$registerId.'_'.$schemaId;

            // to_regclass returns NULL when the relation does not exist.
            $exists = $db->executeQuery(
                'SELECT to_regclass(?) IS NOT NULL AS present',
                ['public.'.$table]
            )->fetchOne();

            if ((bool) $exists === false) {
                $missing[]     = $schemaId;
                $missingTotal++;
            }
        }

        echo sprintf(
            "  register %-4d %-24s schemas=%-4d missing_tables=%d%s",
            $registerId,
            (string) ($register['slug'] ?? '?'),
            count($schemaIds),
            count($missing),
            PHP_EOL
        );

        if ($missing !== []) {
            echo '      MISSING schema ids: '.implode(', ', array_slice($missing, 0, 40)).PHP_EOL;
        }
    }
} catch (\Throwable $e) {
    echo '  ERROR: '.$e->getMessage().PHP_EOL;
}

// ---- 4. Verdict. ------------------------------------------------------------
echo PHP_EOL.'-- verdict --'.PHP_EOL;
echo sprintf('  schemas checked: %d, tables missing: %d%s', $schemaTotal, $missingTotal, PHP_EOL);

if ($schemaTotal === 0) {
    echo '  => CASE 1: nothing was seeded (no registers/schemas).'.PHP_EOL;
} elseif ($missingTotal > 0) {
    echo '  => CASE 2: schemas exist but their tables were never provisioned.'.PHP_EOL;
} else {
    echo '  => CASE 3: every table exists at seed time; any later error is a'.PHP_EOL;
    echo '             different fault (dropped/renamed later, or a stale id).'.PHP_EOL;
}

echo '=============================================================='.PHP_EOL.PHP_EOL;
