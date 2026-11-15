<?php

/**
 * Contract of SlaSweepSource
 *
 * The public surface of SlaSweepSource that other components call, so they depend
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

use OCA\Pipelinq\Service\Sla\SlaSweepRun;
use OCA\Pipelinq\Service\Sla\SlaSweepSurface;

/**
 * Contract of SlaSweepSource.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SlaSweepSourceInterface {

	/**
	 * The sweep interval in seconds, clamped to 60-1800 (default 300).
	 *
	 * @return int
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function interval(): int;

	/**
	 * The OpenRegister ObjectService, or null (logged) when OpenRegister is unavailable.
	 *
	 * @return object|null
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function objectService(): ?object;

	/**
	 * The configured register UUID, empty when unset.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function register(): string;

	/**
	 * Every tracked surface to sweep in a run.
	 *
	 * @param SlaSweepRun $run The sweep run.
	 *
	 * @return array<int, SlaSweepSurface>
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function surfaces(SlaSweepRun $run): array;
}//end interface
