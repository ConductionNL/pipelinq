<?php

/**
 * Pipelinq ObjectSelection.
 *
 * The objects of one schema a Blast listing selects.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * A schema slug and the field filters that select objects in it.
 *
 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
 */
final class ObjectSelection {
	/**
	 * Constructor.
	 *
	 * @param string               $schemaSlug The schema slug.
	 * @param array<string, mixed> $filters    Field filter map.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $schemaSlug,
		public readonly array $filters,
	) {
	}//end __construct()

	/**
	 * The filters scoped to a register and this schema, register and schema first.
	 *
	 * @param string $register The register slug.
	 *
	 * @return array<string, mixed> The scoped filters.
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function scoped(string $register): array {
		return array_merge(['register' => $register, 'schema' => $this->schemaSlug], $this->filters);
	}//end scoped()
}//end class
