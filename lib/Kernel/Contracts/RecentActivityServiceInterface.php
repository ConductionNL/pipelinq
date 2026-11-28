<?php

/**
 * Contract of RecentActivityService
 *
 * The public surface of RecentActivityService that other components call, so they depend
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
 * Contract of RecentActivityService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecentActivityServiceInterface {

	/**
	 * The latest audit-trail entries of the user's assigned work, newest first.
	 *
	 * Each entry: `{ id, action, changes, user, userName, created, title,
	 * kind, routeName, recordId }`. Work that cannot be read answers no entries.
	 *
	 * @param string $userId The Nextcloud UID whose work to look at.
	 *
	 * @return array<int, array<string, string>> The entries.
	 *
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	public function forUser(string $userId): array;
}//end interface
