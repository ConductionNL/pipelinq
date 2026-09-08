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

// Repoint OCA\Pipelinq\* classmap entries at THIS checkout's own lib/.
//
// Local dev checkouts symlink vendor/ (and node_modules/) into a git worktree
// rather than running `composer install` per worktree. Composer's generated
// autoload_classmap.php computes its base directory as
// `dirname(dirname(__DIR__))` from inside vendor/composer/ — and PHP resolves
// __DIR__ through the symlink to vendor's REAL location, so in a worktree
// that base directory is the symlink TARGET (the main checkout), not this
// worktree. Every OCA\Pipelinq\* class then silently autoloads from a
// different git checkout than the one the tests are running in: a fix
// present here can be invisible to phpunit, and a class added here but not
// yet in the main checkout throws "Class not found". Composer's classmap is
// consulted before any PSR-4 prefix, so only overwriting the classmap itself
// (not addPsr4) fixes resolution. This has no effect in CI, where vendor/ is
// a real `composer install` inside the checkout being tested and the
// original paths already point at the right files.
if ($autoloader instanceof \Composer\Autoload\ClassLoader) {
	$appRoot = dirname(__DIR__);
	$localPipelinqMap = [];
	foreach ($autoloader->getClassMap() as $mappedClass => $mappedPath) {
		if (str_starts_with($mappedClass, 'OCA\\Pipelinq\\') === false) {
			continue;
		}

		$libPos = strrpos($mappedPath, '/lib/');
		if ($libPos === false) {
			continue;
		}

		$localPath = $appRoot . substr($mappedPath, $libPos);
		if (is_file($localPath) === true) {
			$localPipelinqMap[$mappedClass] = $localPath;
		}
	}

	if ($localPipelinqMap !== []) {
		$autoloader->addClassMap($localPipelinqMap);
	}

	// A class that exists ONLY in this worktree (added after the main
	// checkout's classmap was last generated — e.g. by a `git merge` of
	// development that the main checkout has not pulled) has no classmap
	// entry to remap at all, and falls through to the PSR-4 prefix, which
	// carries the exact same symlink-resolved-to-the-main-checkout problem
	// as the classmap did. `setPsr4()` (not `addPsr4()`) REPLACES the
	// registered base directory for the prefix rather than appending to
	// it, so `OCA\Pipelinq\` resolves against this worktree's lib/ only —
	// composer.json's own declared mapping, just re-pointed at where this
	// process is actually running.
	$autoloader->setPsr4('OCA\\Pipelinq\\', [$appRoot . '/lib']);
}

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
// The deep INTEGRATION tier (tests/e2e/workflows + Newman, run against a live,
// OR-loaded Nextcloud — see .github/workflows/code-quality.yml) is where
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
	'Service/Integration/Providers/MessageDispatchProvider.php',
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

// Bootstrap Nextcloud if not already done. Only an INSTALLED root is booted;
// a bare source tree runs in pure-unit mode with the composer autoload and the
// stubs above. NC's tests/autoload.php requires lib/base.php itself, so it
// sits behind the same guard.
if (!defined('OC_CONSOLE')) {
	$pipelinqNcRoot = realpath(__DIR__ . '/../../..');
	if ($pipelinqNcRoot !== false && file_exists($pipelinqNcRoot . '/lib/base.php') === true) {
		if (pipelinq_nc_root_is_installed($pipelinqNcRoot) === true) {
			try {
				require_once $pipelinqNcRoot . '/lib/base.php';

				// Load Test\TestCase and other NC test classes (NC convention).
				if (file_exists($pipelinqNcRoot . '/tests/autoload.php') === true) {
					require_once $pipelinqNcRoot . '/tests/autoload.php';
				}
			} catch (\Throwable $e) {
				// The tree IS installed, so the dangerous case this guard exists for
				// (loading a bare source tree) did not happen. base.php still failed
				// part-way.
				//
				// This does NOT abort. `OC::$server` is a typed static, so a half-built
				// container cannot be unset, and aborting was tried: it turned all six
				// PHPUnit legs red on a suite that passes (humaniq, 2026-09-08). The
				// runaway this guard exists for needs an autowiring lookup to reach the
				// poisoned container, this app has none in lib, and phpunit.xml's 2G cap
				// bounds one anyway.
				//
				// So: say plainly that the container is unreliable, and let the pure unit
				// tests run. A container-bound test failing loudly is the intended outcome.
				fwrite(
					STDERR,
					sprintf(
						"[pipelinq/tests/bootstrap] Nextcloud at %s could not finish booting (%s).\n"
						. "  \\OC::\$server now holds a HALF-BUILT container and cannot be unset. Pure unit tests\n"
						. "  continue; anything resolving a service from that container is UNVERIFIED by this run.\n",
						$pipelinqNcRoot,
						$e->getMessage()
					)
				);
			}
		} else {
			fwrite(
				STDERR,
				sprintf(
					"[pipelinq/tests/bootstrap] Nextcloud root at %s is not an installed instance (config/config.php lacks installed => true); "
					. "skipping lib/base.php and running with composer autoload and stubs only (pure-unit mode).\n",
					$pipelinqNcRoot
				)
			);
		}
	}

	unset($pipelinqNcRoot);

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

// NOTE: the ADR-078 deferral doubles (Unit/Listener/RecordingDeferralService.php,
// Unit/Listener/DeferredJobDrain.php) are deliberately NOT listed here. They used
// to be, and because this is only ONE of three bootstraps (bootstrap-unit.php and
// bootstrap-bare.php are the others) the unit suite — which runs on
// bootstrap-unit.php — could not see them, erroring 21 listener tests with
// "Class RecordingDeferralService not found". Each test that needs them now
// require_once's them itself, which is what the rest of this suite already does
// for its doubles and is the only form that survives a bootstrap it was not
// told about. Do not re-add per-bootstrap helper lists here.
