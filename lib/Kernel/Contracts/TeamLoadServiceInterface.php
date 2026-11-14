<?php

/**
 * Contract of TeamLoadService
 *
 * The public surface of TeamLoadService that other components call, so they depend
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
 * Contract of TeamLoadService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TeamLoadServiceInterface {

	/**
	 * The team's load: one row per colleague (the first agent profile of a
	 * user id), sorted by name.
	 *
	 * Each row holds the user id and name, whether the agent is available,
	 * their open request tickets and leads, their maxConcurrent and whether
	 * the open count has reached it. Empty when the register or the agent
	 * profile schema is not configured, or the profiles cannot be read.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-1.3
	 * @spec openspec/specs/skill-routing/spec.md#requirement-team-today-shows-each-colleague-once-by-name
	 */
	public function getTeamLoad(): array;
}//end interface
