<?php

/**
 * Contract of CostReconciliationService
 *
 * The public surface of CostReconciliationService that other components call, so they depend
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

/**
 * Contract of CostReconciliationService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CostReconciliationServiceInterface {

	/**
	 * Reconcile every pending-cost message row.
	 *
	 * @return array{scanned: int, reconciled: int} Summary.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential per-row guard clauses
	 *  over the pending-cost scan; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same flat per-row guards; path count is
	 *  a product of independent skip conditions, not nesting.
	 */
	public function reconcile(): array;
}//end interface
