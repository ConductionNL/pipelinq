<?php

/**
 * Contract of SlaAttainmentService
 *
 * The public surface of SlaAttainmentService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of SlaAttainmentService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SlaAttainmentServiceInterface {

	/**
	 * Compute attainment for the requested filter.
	 *
	 * @param array<string, mixed> $params Filter parameters: bucket, date,
	 *                                     week, month, quarter, groupBy, policy.
	 *
	 * @return array<string, mixed> Attainment payload.
	 *
	 * @throws InvalidArgumentException When required params are invalid.
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function compute(array $params): array;
}//end interface
