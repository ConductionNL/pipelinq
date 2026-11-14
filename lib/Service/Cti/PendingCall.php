<?php

/**
 * Pipelinq PendingCall.
 *
 * A call that a pending contactmoment ticket is created for.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;

/**
 * The direction, the numbers and the agent of a call that gets a pending
 * contactmoment.
 *
 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
 */
final class PendingCall {
	/**
	 * Constructor.
	 *
	 * @param string    $direction  inbound|outbound.
	 * @param string    $fromNumber Caller number.
	 * @param string    $toNumber   Callee number.
	 * @param CallAgent $agent      The agent the call is assigned to.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $direction,
		public readonly string $fromNumber,
		public readonly string $toNumber,
		public readonly CallAgent $agent,
	) {
	}//end __construct()
}//end class
