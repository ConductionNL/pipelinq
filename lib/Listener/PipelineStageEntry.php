<?php

/**
 * Pipelinq PipelineStageEntry.
 *
 * The stage of a pipeline an item enters, as {@see StageLimitGuard} counts it.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-no-write-puts-an-item-into-a-full-stage-req-psl-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

/**
 * A pipeline, its id and the stage entered.
 *
 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-no-write-puts-an-item-into-a-full-stage-req-psl-002
 */
final class PipelineStageEntry {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $pipeline   The pipeline.
	 * @param string               $pipelineId The pipeline id.
	 * @param string               $stageName  The stage entered, or '' when none.
	 *
	 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-no-write-puts-an-item-into-a-full-stage-req-psl-002
	 */
	public function __construct(
		public readonly array $pipeline,
		public readonly string $pipelineId,
		public readonly string $stageName,
	) {
	}//end __construct()
}//end class
