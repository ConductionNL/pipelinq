<?php

/**
 * Contract of SlaEntitySweeper
 *
 * The public surface of SlaEntitySweeper that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use OCA\Pipelinq\Service\Sla\SlaSweepSurface;

/**
 * Contract of SlaEntitySweeper.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SlaEntitySweeperInterface {

	/**
	 * The active policies by identity.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function indexPolicies(): array;

	/**
	 * Sweep every surface and add up the counters.
	 *
	 * @param array<int, SlaSweepSurface> $surfaces The surfaces.
	 *
	 * @return array{processed: int, escalated: int}
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function sweepAll(array $surfaces): array;
}//end interface
