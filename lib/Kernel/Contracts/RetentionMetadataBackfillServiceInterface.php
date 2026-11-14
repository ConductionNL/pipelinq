<?php

/**
 * Contract of RetentionMetadataBackfillService
 *
 * The public surface of RetentionMetadataBackfillService that other components call, so they depend
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
 * Contract of RetentionMetadataBackfillService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RetentionMetadataBackfillServiceInterface {

	/**
	 * Apply retention metadata to every record of the schemas named by the given
	 * app config keys that has none.
	 *
	 * @param array<int, string> $schemaKeys App config keys holding schema ids.
	 *
	 * @return array{status: string, applied: int, skipped: int, failed: int}
	 *         status is done, not-configured or openregister-unavailable.
	 *
	 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
	 */
	public function backfill(array $schemaKeys): array;
}//end interface
