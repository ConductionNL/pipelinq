<?php

/**
 * Pipelinq StagedItemWrite.
 *
 * One create or update {@see StageLimitGuard} judges.
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
 * The item's schema, the item as it will be saved, and as it was.
 *
 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-no-write-puts-an-item-into-a-full-stage-req-psl-002
 */
final class StagedItemWrite {

	/**
	 * Constructor.
	 *
	 * @param string               $schemaId The item's schema id.
	 * @param array<string, mixed> $data     The item as it will be saved.
	 * @param array<string, mixed> $old      The item as it was (empty on create).
	 *
	 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-no-write-puts-an-item-into-a-full-stage-req-psl-002
	 */
	public function __construct(
		public readonly string $schemaId,
		public readonly array $data,
		public readonly array $old,
	) {
	}//end __construct()

	/**
	 * The trimmed pipeline id the item will sit in, or '' for none.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-no-write-puts-an-item-into-a-full-stage-req-psl-002
	 */
	public function pipelineId(): string {
		return trim((string)($this->data['pipeline'] ?? ''));
	}//end pipelineId()
}//end class
