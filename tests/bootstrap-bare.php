<?php

/**
 * Bare bootstrap for PHPUnit — skips NC app loading to avoid the PHP 8.4
 * #[Override] fatal that fires when OC\Settings\Manager is loaded before
 * OCP\Settings\IManager declares getAdminDelegatedSettings().
 *
 * Only used for local/CI runs where the NC container is present.
 * The real bootstrap.php (used in the CI gate runner) continues to load NC.
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

// Prevent NC bootstrap from running OC_App::loadApps() which triggers the fatal.
define('PHPUNIT_RUN', 1);
define('OC_CONSOLE', true);

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

// OpenRegister's PUBLISHED register-slug contracts, loaded from the hydra-gates
// package rather than copied into this tree.
//
// `RegisterSlugResolution` and `RegisterSlugResolverInterface` are the pair
// behind the register-slug resolution ADR-084 settled for `ObjectServiceInterface`.
// Unlike the two `Object*` contracts, this repository keeps no copy of them under
// tests/Stubs/, so the `OCA\OpenRegister\` prefix registered above finds nothing
// and a test naming either type would die with "class not found". Deliberately no
// copy: a contract copied here drags OpenRegister's own `@spec` annotations with
// it, and gate 46 (`spec-anchor-existence`) resolves every `@spec` target against
// THIS repository, where that spec does not exist. Measured: the two files
// produced seven blocking findings before they were removed.
//
// They ship in conduction/hydra-gates from v1.18.0 (ConductionNL/.github#739,
// which merged after v1.17.0 was cut), which is why composer.json asks for
// ^1.18.0 rather than ^1.17.0. Verified 2026-09-10: the vendored copies are byte
// identical to openregister's own `lib/Contract/`, which is what gate 67
// (`openregister-contract-parity`) exists to keep true.
//
// The guard asks BOTH questions on purpose. `RegisterSlugResolution` is a CLASS
// and `RegisterSlugResolverInterface` an INTERFACE, and `interface_exists()`
// answers false for a loaded class. Asking only that would re-require a file
// already in memory, and a duplicate declaration is a fatal, not a no-op.
foreach (['RegisterSlugResolution', 'RegisterSlugResolverInterface'] as $pipelinqContract) {
	$pipelinqContractFqcn = '\\OCA\\OpenRegister\\Contract\\' . $pipelinqContract;
	if (interface_exists($pipelinqContractFqcn) === true || class_exists($pipelinqContractFqcn) === true) {
		continue;
	}

	$pipelinqContractFile = __DIR__ . '/../vendor/conduction/hydra-gates/hydra-gates/contracts/'
		. $pipelinqContract . '.php';
	if (file_exists($pipelinqContractFile) === true) {
		require_once $pipelinqContractFile;
	}
}

unset($pipelinqContract, $pipelinqContractFqcn, $pipelinqContractFile);

if ($autoloader instanceof \Composer\Autoload\ClassLoader) {
	if (is_dir(__DIR__ . '/../vendor/nextcloud/ocp/OCP') === true) {
		$autoloader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
	}

	if (is_dir(__DIR__ . '/../vendor/nextcloud/ocp/NCU') === true) {
		$autoloader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
	}

	// Register NC 3rdparty paths for Psr\Http and Symfony\Component not bundled
	// in pipelinq's own vendor/ but available from the NC installation.
	$ncBase = dirname(dirname(dirname(__DIR__)));
	if (is_dir($ncBase . '/3rdparty') === true) {
		// Psr\Http\Client + Psr\Http\Message
		if (is_dir($ncBase . '/3rdparty/psr/http-client/src') === true) {
			$autoloader->addPsr4('Psr\\Http\\Client\\', $ncBase . '/3rdparty/psr/http-client/src/');
		}

		if (is_dir($ncBase . '/3rdparty/psr/http-message/src') === true) {
			$autoloader->addPsr4('Psr\\Http\\Message\\', $ncBase . '/3rdparty/psr/http-message/src/');
		}

		// Symfony\Component\HttpFoundation (needed by XWikiService + others)
		if (is_dir($ncBase . '/3rdparty/symfony/http-foundation') === true) {
			$autoloader->addPsr4('Symfony\\Component\\HttpFoundation\\', $ncBase . '/3rdparty/symfony/http-foundation/');
		}

		// GuzzleHttp
		if (is_dir($ncBase . '/3rdparty/guzzlehttp/guzzle/src') === true) {
			$autoloader->addPsr4('GuzzleHttp\\', $ncBase . '/3rdparty/guzzlehttp/guzzle/src/');
		}

		if (is_dir($ncBase . '/3rdparty/guzzlehttp/promises/src') === true) {
			$autoloader->addPsr4('GuzzleHttp\\Promise\\', $ncBase . '/3rdparty/guzzlehttp/promises/src/');
		}
	}
}

// Eager preload stubs before anything else declares the real classes.
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
			// A stub whose dependency is unavailable is skipped.
		}
	}
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
