<?php

/**
 * Contract of PosCartRepricer
 *
 * The public surface of PosCartRepricer that other components call, so they depend
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

use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Contract of PosCartRepricer.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PosCartRepricerInterface {

	/**
	 * Price an open sale and return it with its lines.
	 *
	 * @param string $transactionId The sale's id.
	 *
	 * @return array{transaction: array<string, mixed>, lines: array<int, array<string, mixed>>} The sale as the server prices it.
	 *
	 * @throws OCSNotFoundException   When the sale does not exist.
	 * @throws OCSForbiddenException  When the user may not change the sale.
	 * @throws OCSBadRequestException When the sale is no longer open.
	 *
	 * @spec openspec/changes/archive/2026-11-12-till-server-priced-lines/tasks.md#task-2
	 */
	public function reprice(string $transactionId): array;
}//end interface
