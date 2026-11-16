<?php

/**
 * Contract of ProspectDiscoveryService
 *
 * The public surface of ProspectDiscoveryService that other components call, so they depend
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
 * Contract of ProspectDiscoveryService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProspectDiscoveryServiceInterface {

	/**
	 * Discover prospects based on configured ICP, with the profile they were
	 * matched on under `icp`. The profile carries the criteria only, never the
	 * KVK API key, and comes along when no profile is set yet as well, so the
	 * prospects page can show what it searches for.
	 *
	 * @param bool $refresh Whether to bypass cache.
	 * @param int  $limit   How many of the best-scored prospects to return; 0 returns all of them.
	 *
	 * @return array The discovery results.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $refresh is a simple cache bypass toggle
	 * @spec                                        openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-prospect/tasks.md#task-15
	 */
	public function discover(bool $refresh=false, int $limit=10): array;
}//end interface
