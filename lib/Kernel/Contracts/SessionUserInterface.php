<?php

/**
 * Contract of SessionUser
 *
 * The public surface of SessionUser that other components call, so they depend
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
 * Contract of SessionUser.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-2
 */
interface SessionUserInterface {

	/**
	 * The session user's id.
	 *
	 * @return string The UID, or an empty string without a session.
	 *
	 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-2
	 */
	public function uid(): string;

	/**
	 * Whether a user is an administrator.
	 *
	 * @param string $uid The user.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-2
	 */
	public function isAdmin(string $uid): bool;
}//end interface
