<?php

/**
 * Contract of MockPipelineCleanupService
 *
 * The public surface of MockPipelineCleanupService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of MockPipelineCleanupService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MockPipelineCleanupServiceInterface {

	/**
	 * Remove every mock pipeline no lead or ticket is on, and report the rest.
	 *
	 * The usage count reads past RBAC and organisation scoping on purpose: a
	 * lead hidden from the acting account still sits on the pipeline, and must
	 * keep it. The delete itself keeps OpenRegister's checks.
	 *
	 * @param bool $dryRun Report what would be removed without removing it.
	 *
	 * @return array<string, list<array<string, mixed>>> `removed`, `kept` (with `usedBy`), `failed` and `defaults`,
	 *                                                  each a list of pipelines by `id` and `title`.
	 *
	 * @throws RuntimeException When the register, the pipeline schema or a schema objects sit on is not configured.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $dryRun is the command's --dry-run option.
	 *
	 * @spec openspec/changes/only-the-drawn-pipelines/specs/pipeline/spec.md#requirement-mock-pipelines-can-be-removed-when-nothing-is-on-them
	 */
	public function run(bool $dryRun=false): array;
}//end interface
