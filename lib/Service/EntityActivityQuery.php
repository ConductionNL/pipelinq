<?php

/**
 * Pipelinq EntityActivityQuery.
 *
 * Which entity's activity feed EntityActivityService builds, and in which register.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/entity-notes/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The register, entity type and entity id one activity feed is read for.
 *
 * @spec openspec/changes/entity-notes/tasks.md#task-2
 */
final class EntityActivityQuery {
	/**
	 * Constructor.
	 *
	 * @param string $registerId The configured register id.
	 * @param string $entityType The validated entity type.
	 * @param string $entityId   The OR object UUID.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $registerId,
		public readonly string $entityType,
		public readonly string $entityId,
	) {
	}//end __construct()

	/**
	 * Equality filters scoped to this register and one schema, register and
	 * schema first.
	 *
	 * @param string              $schemaId The schema id.
	 * @param array<string,mixed> $filters  The source's own equality filters.
	 *
	 * @return array<string,mixed> The scoped filters.
	 *
	 * @spec openspec/changes/entity-notes/tasks.md#task-2
	 */
	public function schemaFilters(string $schemaId, array $filters): array {
		return array_merge(
			[
				'register' => $this->registerId,
				'schema' => $schemaId,
			],
			$filters
		);
	}//end schemaFilters()
}//end class
