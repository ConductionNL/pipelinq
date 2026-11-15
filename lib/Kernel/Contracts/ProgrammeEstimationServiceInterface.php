<?php

/**
 * Contract of ProgrammeEstimationService
 *
 * The public surface of ProgrammeEstimationService that other components call, so they depend
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
 * Contract of ProgrammeEstimationService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProgrammeEstimationServiceInterface {

	/**
	 * What a cycle's chart should read, and what it is live.
	 *
	 * A closed cycle's chart reads its LAST snapshot; the live figures sit
	 * beside it rather than replacing it, so both questions can be asked.
	 *
	 * @param array<string, mixed> $cycle The cycle.
	 * @param array<int, array<string, mixed>> $tasks The tasks in it, now.
	 *
	 * @return array{chart: array<string, mixed>, live: array<string, int>, source: string}
	 *   The figures.
	 *
	 * @spec openspec/specs/project-portfolio/spec.md#requirement-a-cycle-shall-snapshot-its-progress-when-it-closes-req-prj-006
	 */
	public function chartFor(array $cycle, array $tasks): array;

	/**
	 * Close a cycle, writing its snapshot.
	 *
	 * @param array<string, mixed> $cycle The cycle.
	 * @param array<int, array<string, mixed>> $tasks The tasks in it.
	 *
	 * @return array<string, mixed> `status` plus either `cycle` or `error`.
	 *
	 * @spec openspec/specs/project-portfolio/spec.md#requirement-a-cycle-shall-snapshot-its-progress-when-it-closes-req-prj-006
	 */
	public function closeCycle(array $cycle, array $tasks): array;
}//end interface
