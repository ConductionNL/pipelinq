<?php

/**
 * PHPUnit bootstrap for Pipelinq unit tests.
 *
 * Designed to run in two modes:
 *  - Bare php:8.3-cli CI container (no Nextcloud installed) — vendor/nextcloud/ocp/OCP
 *    is a broken symlink; we fall back to OCP.bak which holds the shipped stubs.
 *  - Full NC server / docker dev container — base.php available, real OCP loaded.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// Mark as unit test run so bootstrap guards elsewhere see this constant.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
$autoloader = require __DIR__ . '/../vendor/autoload.php';

// Register the test-only stub namespaces on the composer loader at test time.
// These previously lived in composer.json "autoload-dev" (mapping the real
// cross-app / framework namespaces OCA\OpenRegister\, Doctrine\DBAL\ and OC\ to
// tests/Stubs/). When vendor/ is built WITH dev dependencies — as the shared dev
// instance does — that mapping enters the RUNTIME classmap and the stubs SHADOW the
// real classes instance-wide, producing 500s everywhere (openregister#2036). The
// mapping is therefore removed from composer.json and re-registered here, at
// test-time only, so the unit suite still resolves the stubs while no runtime
// autoloader is ever polluted. Loading is lazy, so ordering relative to the
// OCP/NC registration below is irrelevant.
if ($autoloader instanceof \Composer\Autoload\ClassLoader) {
	$autoloader->addPsr4('OCA\\OpenRegister\\', __DIR__ . '/Stubs/');
	// Test-only helper classes that are NOT themselves tests, such as the
	// in-memory object store the social publishing suite shares. PHPUnit loads
	// only files matching the *Test.php suffix, so a helper beside them needs
	// an autoload rule or every test using it dies with "class not found".
	$autoloader->addPsr4('OCA\\Pipelinq\\Tests\\', __DIR__ . '/');
	$autoloader->addPsr4('Doctrine\\DBAL\\', __DIR__ . '/Stubs/DBAL/');
	$autoloader->addPsr4('OC\\', __DIR__ . '/Stubs/OC/');
}

// Register OCP\ and NCU\ namespaces.
// vendor/nextcloud/ocp/OCP is a symlink to the live NC server (/var/www/html/lib/public)
// that resolves on a deployed instance but is broken in the bare php:8.3-cli CI container.
// In CI we fall back to vendor/nextcloud/ocp/OCP.bak which holds the shipped stubs.
// This MUST happen before any class_exists() call that may trigger autoloading of
// stub classes that extend OCP\EventDispatcher\Event etc.
$ocpDir = __DIR__ . '/../vendor/nextcloud/ocp/OCP';
$ocpBakDir = __DIR__ . '/../vendor/nextcloud/ocp/OCP.bak';
$ncuDir = __DIR__ . '/../vendor/nextcloud/ocp/NCU';

if ($autoloader instanceof \Composer\Autoload\ClassLoader) {
	if (is_dir($ocpDir) === true) {
		$autoloader->addPsr4('OCP\\', $ocpDir . '/');
		$autoloader->addPsr4('NCU\\', $ncuDir . '/');
	} elseif (is_dir($ocpBakDir) === true) {
		// Bare CI environment — symlink broken, use the shipped backup stubs.
		$autoloader->addPsr4('OCP\\', $ocpBakDir . '/');
		$autoloader->addPsr4('NCU\\', $ncuDir . '/');
	}
}

// Deterministic OpenRegister-stub precedence (two-mode harness invariant).
//
// The pipelinq UNIT suite is isolated from the real OpenRegister app: it mocks a
// deliberately-simplified OR surface (e.g. ObjectService::find() returning an
// array, ObjectEntity::getSchema() / getUuid() as real DECLARED methods,
// saveObject() returning an array) supplied by the stubs under tests/Stubs/ and
// mapped via the autoload-dev PSR-4 prefix "OCA\OpenRegister\" => "tests/Stubs/".
//
// When phpunit happens to run INSIDE a Nextcloud that has OpenRegister enabled,
// NC's app autoloader (registered by lib/base.php / OC_App below) would otherwise
// resolve OCA\OpenRegister\* to the REAL classes first. Their stricter signatures
// are incompatible with the unit suite's stub-shaped mocks. So we EAGERLY declare
// the OR stub classes here, BEFORE the NC bootstrap registers OR's namespace.
foreach ([
	'Db/ObjectEntity.php',
	'Service/ObjectService.php',
	'Service/Integration/Providers/MessageDispatchProvider.php',
] as $stubRelativePath) {
	$stubFile = __DIR__ . '/Stubs/' . $stubRelativePath;
	if (is_file($stubFile) === true) {
		try {
			require_once $stubFile;
		} catch (\Throwable $e) {
			// A stub whose dependency is unavailable in this context is skipped.
		}
	}
}

/**
 * Tell whether a Nextcloud root is an INSTALLED instance, not just a source tree.
 *
 * `lib/base.php` from a source tree that was never installed (the workspace
 * checkout above apps-extra/ has a 0-byte config/config.php) still declares
 * `OC` and builds `\OC::$server` before it throws "Not installed". That server
 * cannot be undone (`OC::$server` is a typed static), so from then on every
 * `\OC::$server->get()` in the code under test hits a container that knows
 * none of this app's registrations and autowires from scratch; constructor
 * cycles then recurse until memory runs out (19 GB and 6 GB of swap in one
 * openregister run on 2026-09-08). So the decision has to be made BEFORE
 * base.php is loaded, and the only cheap signal is the `installed` flag in
 * config/config.php.
 *
 * @param string $ncRoot Candidate Nextcloud root.
 *
 * @return bool True when config/config.php declares `installed => true`.
 */
function pipelinq_nc_root_is_installed(string $ncRoot): bool
{
	$configFile = $ncRoot . '/config/config.php';
	if (is_file($configFile) === false || filesize($configFile) === 0) {
		return false;
	}

	// The config file is a plain `$CONFIG = [...]` script; including it in a
	// closure keeps `$CONFIG` out of the global scope.
	$config = (static function () use ($configFile): array {
		$CONFIG = [];
		try {
			include $configFile;
		} catch (\Throwable) {
			return [];
		}

		if (is_array($CONFIG) === false) {
			return [];
		}

		return $CONFIG;
	})();

	return ($config['installed'] ?? false) === true;
}//end pipelinq_nc_root_is_installed()

// Bootstrap Nextcloud when an INSTALLED server is above apps-extra/. A bare
// source tree is reported once and the suite runs in standalone mode with the
// vendor stubs only.
$pipelinqNcRoot = realpath(__DIR__ . '/../../..');
if ($pipelinqNcRoot !== false && file_exists($pipelinqNcRoot . '/lib/base.php') === true) {
	if (pipelinq_nc_root_is_installed($pipelinqNcRoot) === true) {
		try {
			require_once $pipelinqNcRoot . '/lib/base.php';
		} catch (\Throwable $e) {
			// The root passed the installed check but base.php still failed
			// (unreachable database, broken app, ...). `OC::$server` is a typed
			// static that already holds a half-built container, so falling
			// through to "vendor stubs only" would be a lie that costs
			// gigabytes. Stop the run and say why.
			fwrite(
				STDERR,
				sprintf(
					"[pipelinq/tests/bootstrap-unit] Nextcloud root at %s could not be initialised (%s).\n"
					. "  A half-booted server cannot be undone, so the run stops here rather than pretending to be pure-unit.\n"
					. "  Fix the instance, or use phpunit-bare.xml (defines OC_CONSOLE) for pure-unit mode.\n",
					$pipelinqNcRoot,
					$e->getMessage()
				)
			);
			exit(1);
		}
	} else {
		fwrite(
			STDERR,
			sprintf(
				"[pipelinq/tests/bootstrap-unit] Nextcloud root at %s is not an installed instance (config/config.php lacks installed => true); "
				. "skipping lib/base.php and running with composer autoload and stubs only (pure-unit mode).\n",
				$pipelinqNcRoot
			)
		);
	}
}

unset($pipelinqNcRoot);

// Register Test\ namespace for NC test classes (only when NC server is present).
$serverTestsLib = __DIR__ . '/../../../tests/lib/';
if (is_dir($serverTestsLib) === true) {
	$loader = new \Composer\Autoload\ClassLoader();
	$loader->addPsr4('Test\\', $serverTestsLib);
	$loader->register(true);
}

// Load remaining stubs via class_exists / interface_exists guards.
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
	$stubFile = __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
	if (file_exists($stubFile) === true) {
		require_once $stubFile;
	}
}

if (class_exists(\OCA\OpenRegister\Service\ObjectService::class) === false) {
	$stubFile = __DIR__ . '/Stubs/Service/ObjectService.php';
	if (file_exists($stubFile) === true) {
		require_once $stubFile;
	}
}

if (interface_exists(\OCA\OpenRegister\Lifecycle\LifecycleGuardInterface::class) === false) {
	$f1 = __DIR__ . '/Stubs/Lifecycle/GuardResult.php';
	$f2 = __DIR__ . '/Stubs/Lifecycle/LifecycleGuardInterface.php';
	if (file_exists($f1) === true) {
		require_once $f1;
	}

	if (file_exists($f2) === true) {
		require_once $f2;
	}
}

if (class_exists(\OCA\OpenRegister\Service\Lifecycle\TransitionEngine::class) === false) {
	$stubFile = __DIR__ . '/Stubs/Service/Lifecycle/TransitionEngine.php';
	if (file_exists($stubFile) === true) {
		require_once $stubFile;
	}
}

// Portal test helpers.
if (file_exists(__DIR__ . '/Unit/Service/Portal/FakePortalObjectRepository.php') === true) {
	require_once __DIR__ . '/Unit/Service/Portal/FakePortalObjectRepository.php';
}

if (file_exists(__DIR__ . '/Unit/Service/Portal/FakeMainRegisterReader.php') === true) {
	require_once __DIR__ . '/Unit/Service/Portal/FakeMainRegisterReader.php';
}
