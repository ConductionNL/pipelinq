<?php

/**
 * PHPStan bootstrap file - registers OCP autoloader for static analysis.
 *
 * In a full Nextcloud container the vendor/nextcloud/ocp/OCP directory is a
 * symlink to /var/www/html/lib/public; in a bare php:8.3-cli CI container
 * (no Nextcloud) the symlink is broken, so we fall back to OCP.bak which
 * ships the real stubs. Same pattern as tests/bootstrap-unit.php.
 */

$autoloader = require __DIR__ . '/vendor/autoload.php';

$ocpDir    = __DIR__ . '/vendor/nextcloud/ocp/OCP';
$ocpBakDir = __DIR__ . '/vendor/nextcloud/ocp/OCP.bak';

if (is_dir($ocpDir) === true) {
    $autoloader->addPsr4('OCP\\', $ocpDir . '/');
} elseif (is_dir($ocpBakDir) === true) {
    $autoloader->addPsr4('OCP\\', $ocpBakDir . '/');
}

$autoloader->addPsr4('NCU\\', __DIR__ . '/vendor/nextcloud/ocp/NCU/');
