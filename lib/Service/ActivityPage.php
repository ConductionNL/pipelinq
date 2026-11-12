<?php

/**
 * Pipelinq ActivityPage.
 *
 * The page of an activity feed that is asked for.
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
 * A 1-based page number and a page size, both already normalised.
 *
 * @spec openspec/changes/entity-notes/tasks.md#task-2
 */
final class ActivityPage {
	/**
	 * Constructor.
	 *
	 * @param int $page  1-based page number, at least 1.
	 * @param int $limit Page size, within `[1, MAX_LIMIT]`.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly int $page,
		public readonly int $limit,
	) {
	}//end __construct()
}//end class
