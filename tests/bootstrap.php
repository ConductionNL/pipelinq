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

// Deterministic OpenRegister-stub precedence (two-mode harness invariant).
//
// The pipelinq UNIT suite is isolated from the real OpenRegister app: it mocks a
// deliberately-simplified OR surface (e.g. ObjectService::find() returning an
// array, ObjectEntity::getSchema() / getUuid() as real DECLARED methods,
// saveObject() returning an array) supplied by the stubs under tests/Stubs/ and
// mapped via the autoload-dev PSR-4 prefix "OCA\OpenRegister\" => "tests/Stubs/".
// The deep INTEGRATION tier (tests/e2e/workflows + Newman, run by
// .forgejo/workflows/tests-live.yml against a live, OR-loaded Nextcloud) is where
// the suite exercises the REAL OpenRegister classes.
//
// When phpunit happens to run INSIDE a Nextcloud that has OpenRegister enabled,
// NC's app autoloader (registered by lib/base.php / OC_App below) would otherwise
// resolve OCA\OpenRegister\* to the REAL classes first. Their stricter signatures
// (find(): ?ObjectEntity, saveObject(): ObjectEntity, no declared getSchema())
// are incompatible with the unit suite's stub-shaped mocks, producing the
// ~65 errors / 11 failures (CannotUseOnlyMethods getSchema, IncompatibleReturnValue
// find/saveObject) — pure stub-vs-real API divergence, NOT real regressions — plus
// a hard "Declaration must be compatible" fatal on the one anonymous ObjectService
// subclass. So we EAGERLY declare the OR stub classes here, BEFORE the NC bootstrap
// registers OR's namespace. Once a stub class is declared, PHP will not load the
// real same-named class, so every OCA\OpenRegister\* the suite stubs resolves to
// the stub regardless of whether OR is installed — the bare host run and the
// OR-loaded container run now behave identically. Non-stubbed OR classes still fall
// through to the real app (only the listed files are pre-declared). Each require is
// fault-tolerant: a stub whose dependency is unavailable is skipped, never fatal.
foreach ([
    'Db/ObjectEntity.php',
    'Service/ObjectService.php',
] as $stubRelativePath) {
    $stubFile = __DIR__ . '/Stubs/' . $stubRelativePath;
    if (is_file($stubFile) === true) {
        try {
            require_once $stubFile;
        } catch (\Throwable $e) {
            // A stub that depends on a class not present in this context is
            // skipped; the suite continues with whatever surface is available.
        }
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

// The OpenRegister stubs are pre-declared above (before the NC bootstrap) so they
// win deterministically in both run modes. The remaining require_once guards below
// are kept as defensive no-ops: in a bare run they are already satisfied by the
// eager preload, and in an OR-loaded run the eager preload has already declared the
// stub, so each guard's class_exists/interface_exists check short-circuits.

// Load the IMcpToolProvider stub for cross-app classes not available as Composer
// dependencies (the real interface ships with OpenRegister PR #1466). The stub
// file guards itself with interface_exists(), so this is a no-op once the real
// OpenRegister app is installed. The stub is also registered via the
// autoload-dev PSR-4 mapping ("OCA\OpenRegister\" => "tests/Stubs/").
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
    require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
}

// Load the ObjectService stub so unit tests can create PHPUnit mocks for
// OpenRegister's ObjectService without requiring the openregister app to be
// installed.
if (class_exists(\OCA\OpenRegister\Service\ObjectService::class) === false) {
    require_once __DIR__ . '/Stubs/Service/ObjectService.php';
}

// Load the lifecycle contract stubs so the POS lifecycle guards (which implement
// OCA\OpenRegister\Lifecycle\LifecycleGuardInterface and return GuardResult) and
// the POS services (which consume TransitionEngine) can be unit-tested without
// the openregister app installed. Each guards itself; the real classes win when
// OpenRegister is present.
if (interface_exists(\OCA\OpenRegister\Lifecycle\LifecycleGuardInterface::class) === false) {
    require_once __DIR__ . '/Stubs/Lifecycle/GuardResult.php';
    require_once __DIR__ . '/Stubs/Lifecycle/LifecycleGuardInterface.php';
}

if (class_exists(\OCA\OpenRegister\Service\Lifecycle\TransitionEngine::class) === false) {
    require_once __DIR__ . '/Stubs/Service/Lifecycle/TransitionEngine.php';
}

// Portal test helpers live in the Tests namespace, which has no PSR-4 mapping
// in autoload-dev; load the in-memory repository double explicitly so the
// portal service tests can use it without a composer.json change.
if (file_exists(__DIR__ . '/Unit/Service/Portal/FakePortalObjectRepository.php') === true) {
    require_once __DIR__ . '/Unit/Service/Portal/FakePortalObjectRepository.php';
}

if (file_exists(__DIR__ . '/Unit/Service/Portal/FakeMainRegisterReader.php') === true) {
    require_once __DIR__ . '/Unit/Service/Portal/FakeMainRegisterReader.php';
}
