<?php

/**
 * Contract of LineRollUp
 *
 * The public surface of LineRollUp that other components call, so they depend
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;

/**
 * Contract of LineRollUp.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LineRollUpInterface {

	/**
	 * Write the totals of the quote or lead this line is on.
	 *
	 * @param ObjectEntityInterface $line The line that was created, changed or deleted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-12-line-totals-roll-up/tasks.md#task-1
	 */
	public function apply(ObjectEntityInterface $line): void;
}//end interface
