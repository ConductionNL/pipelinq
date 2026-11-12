<?php

/**
 * Pipelinq ObjectQuery.
 *
 * One findAll a Blast loader sends to OpenRegister.
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
 * The findAll config of a loader and what it logs when the query fails.
 *
 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
 */
final class ObjectQuery {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config     The findAll config.
	 * @param string               $failure    The warning logged when findAll throws.
	 * @param array<string, mixed> $logContext The context logged with it, before the exception.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $config,
		public readonly string $failure,
		public readonly array $logContext,
	) {
	}//end __construct()
}//end class
