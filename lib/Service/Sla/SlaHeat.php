<?php

/**
 * Pipelinq SlaHeat.
 *
 * How far the hottest SLA target has run when escalations are tested.
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
 * The highest consumed share across the targets and the kind of target that has it.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
 */
class SlaHeat {
	/**
	 * Constructor.
	 *
	 * @param float  $consumed    Highest consumed percentage.
	 * @param string $hottestKind Kind of the target with that share, or empty.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly float $consumed,
		public readonly string $hottestKind,
	) {
	}//end __construct()

	/**
	 * The target kind a breach event records: the hottest kind, or `resolution` when it has none.
	 *
	 * @return string Target kind.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
	 */
	public function kind(): string {
		if ($this->hottestKind === '') {
			return 'resolution';
		}

		return $this->hottestKind;
	}//end kind()
}//end class
