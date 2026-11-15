<?php

/**
 * Contract of EmailSyncService
 *
 * The public surface of EmailSyncService that other components call, so they depend
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
 * Contract of EmailSyncService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmailSyncServiceInterface {

	/**
	 * Get the mail accounts configured for sync by a user.
	 *
	 * @param string $userId The user ID.
	 *
	 * @return array<int> Array of mail account IDs.
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-contact-comms/tasks.md#task-6
	 */
	public function getSyncAccounts(string $userId): array;

	/**
	 * Check whether email sync is enabled for a user.
	 *
	 * @param string $userId The user ID.
	 *
	 * @return bool True if sync is enabled.
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-contact-comms/tasks.md#task-7
	 */
	public function isSyncEnabled(string $userId): bool;

	/**
	 * Update the last sync timestamp for a user.
	 *
	 * @param string $userId The user ID.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-contact-comms/tasks.md#task-10
	 */
	public function updateLastSyncTime(string $userId): void;
}//end interface
