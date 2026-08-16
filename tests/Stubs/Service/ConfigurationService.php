<?php

/**
 * Test stub for OpenRegister's ConfigurationService.
 *
 * SIGNATURE PARITY CONTRACT
 * -------------------------
 * Mirrors the `importFromApp()` surface pipelinq consumes; OpenRegister is not
 * a test-time dependency. Resolved via the `OCA\OpenRegister\ => tests/Stubs/`
 * PSR-4 mapping registered in tests/bootstrap.php.
 *
 * Matched against ConductionNL/openregister@origin/development,
 * lib/Service/ConfigurationService.php:
 *
 *   class ConfigurationService                                          (line  69)
 *   public function importFromApp(
 *       string $appId, array $data, string $version, bool $force = false
 *   ): array                                                            (line 551)
 *
 * WHY THIS FILE EXISTS: `SettingsLoadService` used to reach OpenRegister
 * through an untyped `ContainerInterface`, so nothing in the unit suite ever
 * had to name this class. It now takes a CONSTRUCTOR-INJECTED, concretely
 * typed `ConfigurationService` (ADR-083), and a type-hint the suite cannot
 * load is a test that cannot be written — an in-test anonymous class no longer
 * satisfies it and `createMock()` has no class to derive from.
 *
 * The constructor is deliberately omitted: the real one takes eight mappers,
 * and a test double must be constructible without them. Adding one here would
 * make every `createMock()` call in the suite need arguments it has no reason
 * to know about.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal ConfigurationService stub.
 */
class ConfigurationService {
	/**
	 * Import an app's shipped register configuration.
	 *
	 * @param string $appId The importing app's id.
	 * @param array<string, mixed> $data The configuration payload.
	 * @param string $version The importing app's version.
	 * @param bool $force Re-import even when the deployed version is current.
	 *
	 * @return array<string, mixed> The import result (registers, schemas, views).
	 */
	public function importFromApp(string $appId, array $data, string $version, bool $force = false): array {
		return [];
	}//end importFromApp()
}//end class
