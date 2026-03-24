<?php

/**
 * Bootstrap file for PHPUnit tests.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
$loader = include __DIR__.'/../vendor/autoload.php';

// Register OCP stubs for standalone testing (outside Nextcloud server tree).
// Check without triggering autoload to avoid failures.
$ocpDir = __DIR__.'/../vendor/nextcloud/ocp';
if (is_dir($ocpDir) && !interface_exists('OCP\\IUser', false)) {
    $loader->addPsr4('OCP\\', $ocpDir.'/OCP');
    $loader->addPsr4('NCU\\', $ocpDir.'/NCU');
}

// Bootstrap Nextcloud if not already done.
if (!defined('OC_CONSOLE')) {
    // Try to include the main Nextcloud bootstrap.
    if (file_exists(__DIR__.'/../../../lib/base.php')) {
        include_once __DIR__.'/../../../lib/base.php';
    }

    // Load Test\TestCase and other NC test classes (NC convention).
    if (file_exists(__DIR__.'/../../../tests/autoload.php')) {
        include_once __DIR__.'/../../../tests/autoload.php';
    }

    // Load all enabled apps if Nextcloud is available.
    if (class_exists('OC_App') === true) {
        \OC_App::loadApps();

        // Load our specific app.
        \OC_App::loadApp('pipelinq');

        // Clear hooks for testing.
        OC_Hook::clear();
    }
}//end if
