<?php

/**
 * Contract of EntityActivityService
 *
 * The public surface of EntityActivityService that other components call, so they depend
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
 * Contract of EntityActivityService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EntityActivityServiceInterface {

	/**
	 * Build the activity feed for an entity.
	 *
	 * @param string $entityType The entity type
	 *                           (`client|contact|lead|request`).
	 * @param string $entityId The OR object UUID.
	 * @param string $type The activity filter
	 *                     (`all|notes|contactmomenten`).
	 * @param int $page 1-based page number.
	 * @param int $limit Page size; clamped to
	 *                   `[1, MAX_LIMIT]`.
	 *
	 * @return array{total:int,page:int,pages:int,results:array<int,array<string,mixed>>}
	 *
	 * @throws InvalidArgumentException When the entity type is unknown.
	 *
	 * @spec openspec/changes/archive/2026-06-14-entity-notes/tasks.md#task-2
	 */
	public function getActivity(
		string $entityType,
		string $entityId,
		string $type,
		int $page,
		int $limit,
	): array;

	/**
	 * Whether an entity type is one the endpoint may expose.
	 *
	 * Exposed as a public helper so the controller can validate without
	 * duplicating the allowlist.
	 *
	 * @param string $entityType The entity type to validate.
	 *
	 * @return bool True when the type is allowed.
	 *
	 * @spec openspec/changes/archive/2026-06-14-entity-notes/tasks.md#task-2
	 */
	public function isAllowedEntityType(string $entityType): bool;
}//end interface
