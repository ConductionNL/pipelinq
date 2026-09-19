<?php

/**
 * Test stub for OCA\Integriq\Event\ConnectionStatusReportedEvent.
 *
 * Mirrors the real class (integriq `lib/Event/ConnectionStatusReportedEvent.php`,
 * merged into integriq `development` with integriq#1996) and hydra change
 * connection-registry, design D6, verbatim: parameter names, order and
 * defaults. A stub that differs from the contract would encode the caller's
 * bug as correct.
 *
 * Declaration only. It is scanned by psalm and phpstan (both read
 * tests/Stubs) and loaded by tests/bootstrap.php only when the real class is
 * absent. This app's PSR-4 map covers `OCA\Pipelinq\` only, so production
 * never loads it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

if (class_exists(ConnectionStatusReportedEvent::class, false) === false) {
	/**
	 * An app reports what only it can observe about one declared connection.
	 */
	final class ConnectionStatusReportedEvent extends Event {

		/**
		 * Constructor.
		 *
		 * @param string $app The declaring app id.
		 * @param string $key The connection key from the app's connections.json.
		 * @param string $status configured, unconfigured, simulated, unavailable or error.
		 * @param string $message What the app observed.
		 */
		public function __construct(
			public readonly string $app,
			public readonly string $key,
			public readonly string $status,
			public readonly string $message = '',
		) {
			parent::__construct();
		}//end __construct()
	}//end class
}//end if
