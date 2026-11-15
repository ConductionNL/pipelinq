<?php

/**
 * Pipelinq SlaBreachEvent.
 *
 * The audit record of one fired escalation.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Sla
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Sla;

/**
 * What an slaBreachEvent row records: the escalation, the heat and who was notified.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
 */
class SlaBreachEvent {
	/**
	 * Constructor.
	 *
	 * @param SlaEscalation      $escalation The fired escalation.
	 * @param SlaHeat            $heat       The heat it fired at.
	 * @param array<int, string> $actors     Notified actors.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly SlaEscalation $escalation,
		public readonly SlaHeat $heat,
		public readonly array $actors,
	) {
	}//end __construct()
}//end class
