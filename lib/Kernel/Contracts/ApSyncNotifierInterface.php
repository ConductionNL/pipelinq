<?php

/**
 * Contract of ApSyncNotifier
 *
 * The public surface of ApSyncNotifier that other components call, so they depend
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
 * Contract of ApSyncNotifier.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApSyncNotifierInterface {

	/**
	 * Notify every admin user that an AP dispatch permanently failed.
	 *
	 * @param string $expenseTitle The expense title (for the notification body).
	 * @param string $uuid The expense UUID (for the detail-view reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-14-pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
	 */
	public function notifyFailure(string $expenseTitle, string $uuid): void;
}//end interface
