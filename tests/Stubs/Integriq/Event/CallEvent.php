<?php

/**
 * Test stub for OCA\Integriq\Event\CallEvent.
 *
 * Copied from integriq `lib/Event/CallEvent.php` at development 3b20303e:
 * same namespace, same parent, same constructor, same public readonly
 * properties. Pipelinq never imports the real class (CallEventListener names
 * it as a string), so a test needs this copy to dispatch one.
 *
 * Declared only when the real class is absent. The shared PHPUnit job installs
 * integriq beside pipelinq, so there the real class is loaded and a second
 * declaration is a fatal error (pipelinq#2458, stable35). The guard asks with
 * autoloading on, so the real class wins whenever it can be found.
 *
 * tests/Unit/Support/IntegriqCallEventStubDriftTest.php compares this file
 * with integriq's own, so a change there turns a test red.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

if (class_exists(CallEvent::class) === false) {
	class CallEvent extends Event {

		/**
		 * Constructor.
		 *
		 * @param string $kind ringing, answered, ended or transferred.
		 * @param string $callId The PBX's own call identifier.
		 * @param string $callerNumber The caller in E.164, or '' when withheld.
		 * @param array<string, mixed>|null $caller The resolved partij, or null.
		 * @param array<int, mixed> $openCases Open case references for that partij.
		 * @param string $agentId The agent the call is for, or ''.
		 * @param string $sourceId The CTI source this came through.
		 * @param string $at When the PBX says it happened, ISO 8601, or ''.
		 * @param int $durationSeconds How long the call lasted; 0 except on `ended`.
		 */
		public function __construct(
			public readonly string $kind,
			public readonly string $callId,
			public readonly string $callerNumber,
			public readonly ?array $caller,
			public readonly array $openCases,
			public readonly string $agentId,
			public readonly string $sourceId,
			public readonly string $at,
			public readonly int $durationSeconds = 0,
		) {
			parent::__construct();
		}//end __construct()
	}//end class
}//end if
