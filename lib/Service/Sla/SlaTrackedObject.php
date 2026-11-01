<?php

/**
 * Pipelinq SlaTrackedObject.
 *
 * The object an SLA policy tracks.
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
 * The type and UUID of a tracked object.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
 */
class SlaTrackedObject {
	/**
	 * Constructor.
	 *
	 * @param string $type Tracked object type.
	 * @param string $id   Tracked object UUID.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
