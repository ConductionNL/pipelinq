<?php

/**
 * Integriq's call event, as integriq development b1b52b7d declares it
 * (`lib/Event/CallEvent.php`): same namespace, same constructor, same public
 * readonly properties. Pipelinq never imports the real class, so a test needs
 * this copy to dispatch one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

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
