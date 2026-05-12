<?php

/**
 * Bootstrap file for PHPUnit tests.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

// Include Composer's autoloader. Use `require` (not `require_once`) so the
// ClassLoader instance is returned even when PHPUnit has already pulled it in.
$autoloader = require __DIR__ . '/../vendor/autoload.php';

// Register the OCP/NCU namespaces from the nextcloud/ocp dev dependency so that
// unit tests can run in a bare environment (no installed Nextcloud server). When
// NC is present its own autoloader provides these and these mappings are inert.
if ($autoloader instanceof \Composer\Autoload\ClassLoader && is_dir(__DIR__ . '/../vendor/nextcloud/ocp/OCP') === true) {
    $autoloader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
    if (is_dir(__DIR__ . '/../vendor/nextcloud/ocp/NCU') === true) {
        $autoloader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
    }
}

// Bootstrap Nextcloud if not already done.
if (!defined('OC_CONSOLE')) {
    // Try to include the main Nextcloud bootstrap.
    if (file_exists(__DIR__ . '/../../../lib/base.php')) {
        try {
            require_once __DIR__ . '/../../../lib/base.php';
        } catch (\Throwable $e) {
            // NC not fully installed — unit tests continue with vendor stubs only.
        }
    }

    // Load Test\TestCase and other NC test classes (NC convention).
    if (file_exists(__DIR__ . '/../../../tests/autoload.php')) {
        require_once __DIR__ . '/../../../tests/autoload.php';
    }

    // Load all enabled apps if Nextcloud is available.
    if (class_exists('OC_App')) {
        \OC_App::loadApps();

        // Load our specific app.
        \OC_App::loadApp('pipelinq');

        // Clear hooks for testing.
        OC_Hook::clear();
    }
}

// Load the IMcpToolProvider stub for cross-app classes not available as Composer
// dependencies (the real interface ships with OpenRegister PR #1466). The stub
// file guards itself with interface_exists(), so this is a no-op once the real
// OpenRegister app is installed. The stub is also registered via the
// autoload-dev PSR-4 mapping ("OCA\OpenRegister\" => "tests/Stubs/").
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
    require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
}
