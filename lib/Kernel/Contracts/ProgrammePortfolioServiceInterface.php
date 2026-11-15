<?php

/**
 * Contract of ProgrammePortfolioService
 *
 * The public surface of ProgrammePortfolioService that other components call, so they depend
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
 * Contract of ProgrammePortfolioService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProgrammePortfolioServiceInterface {

	/**
	 * Link a piece of work to a programme.
	 *
	 * @param string $programmeId The programme.
	 * @param string $domainObjectType The `<app>:<schema>` literal.
	 * @param string $domainObjectRef The object's uuid.
	 * @param string $title The object's title as it reads now.
	 *
	 * @return array<string, mixed> `status` plus either `workItem` or `error`.
	 *
	 * @spec openspec/specs/project-portfolio/spec.md#requirement-a-project-shall-hold-work-it-does-not-own-by-reference-req-prj-002
	 */
	public function linkWork(
		string $programmeId,
		string $domainObjectType,
		string $domainObjectRef,
		string $title = '',
	): array;

	/**
	 * Present a work item, resolved or not.
	 *
	 * @param array<string, mixed> $workItem The stored work item.
	 * @param array<string, string> $resolvedTitles Titles the owning apps answered, keyed by uuid.
	 * @param array<int, string> $installedApps The apps this instance has.
	 *
	 * @return array<string, mixed> The row, with `resolved` false when the
	 *   owning app is absent or answered nothing.
	 *
	 * @spec openspec/specs/project-portfolio/spec.md#requirement-a-project-shall-hold-work-it-does-not-own-by-reference-req-prj-002
	 */
	public function presentWorkItem(array $workItem, array $resolvedTitles = [], array $installedApps = []): array;

	/**
	 * How far along a programme is, and where the figure came from.
	 *
	 * @param array<string, mixed> $programme The programme.
	 * @param array<int, array<string, mixed>> $tasks Its tasks, for `fromTasks`.
	 * @param array<string, mixed>|null $effort Hours booked and estimated, for
	 *   `fromEffort`, or null when humaniq cannot be resolved.
	 *
	 * @return array{mode: string, progress: int|null, computable: bool, reason: string}
	 *   The figure, always naming its mode.
	 *
	 * @spec openspec/specs/project-portfolio/spec.md#requirement-progress-shall-declare-which-mode-produced-it-req-prj-003
	 */
	public function progressFor(array $programme, array $tasks = [], ?array $effort = null): array;

	/**
	 * Read rows of one schema as plain arrays.
	 *
	 * @param string $schemaKey The app-config key holding the schema id.
	 * @param array<string, mixed> $filters Extra filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/project-portfolio/spec.md#requirement-progress-shall-declare-which-mode-produced-it-req-prj-003
	 */
	public function read(string $schemaKey, array $filters): array;

	/**
	 * The tasks of one programme.
	 *
	 * @param string $programmeId The programme's uuid.
	 *
	 * @return array<int, array<string, mixed>> The tasks.
	 *
	 * @spec openspec/specs/project-portfolio/spec.md#requirement-progress-shall-declare-which-mode-produced-it-req-prj-003
	 */
	public function tasksOf(string $programmeId): array;

	/**
	 * The work items of one programme.
	 *
	 * @param string $programmeId The programme's uuid.
	 *
	 * @return array<int, array<string, mixed>> The work items.
	 *
	 * @spec openspec/specs/project-portfolio/spec.md#requirement-progress-shall-declare-which-mode-produced-it-req-prj-003
	 */
	public function workItemsOf(string $programmeId): array;
}//end interface
