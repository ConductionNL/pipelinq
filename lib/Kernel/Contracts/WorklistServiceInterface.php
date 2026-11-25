<?php

/**
 * Contract of WorklistService
 *
 * The public surface of WorklistService that other components call, so they depend
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

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of WorklistService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WorklistServiceInterface {

	/**
	 * The current user's worklist: union of assigned leads + requests.
	 *
	 * Returns:
	 *   - items:        normalized rows (capped at $limit when given), each
	 *                    {entityType, id, title, stageOrStatus, priority,
	 *                    dueDate, isOverdue, routeName}
	 *   - total:        size of the full union (before the limit cap)
	 *   - leadCount:    leads in the full union
	 *   - requestCount: requests in the full union
	 *
	 * @param string   $userId The Nextcloud UID asking (assignee scoping).
	 * @param int|null $limit  Optional cap on returned rows (widget uses 5); null = all.
	 * @param string   $scope  `mine` (default), `team` or `organisation` (REQ-MW-180).
	 * @param string   $member With `team`: one member of the team; '' for the whole team.
	 *
	 * @return array{
	 *   items: array<int, array<string, mixed>>,
	 *   total: int,
	 *   leadCount: int,
	 *   requestCount: int
	 * }
	 *
	 * @throws RuntimeException When OpenRegister is unreachable.
	 * @throws \InvalidArgumentException For a scope the user may not choose, or a member outside their team.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
	 * @spec openspec/specs/my-work/spec.md
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 */
	public function getMine(string $userId, ?int $limit=null, string $scope='mine', string $member=''): array;

	/**
	 * Every open item of the user's work, tickets, leads and follow-up tasks,
	 * in one shape, sorted into My work's lists (`bucket`) and the phone's
	 * due groups (`dueGroup`), the most urgent first.
	 *
	 * Each item: `{ kind, id, title, priority, routeName, record, dueAt,
	 * bucket, dueGroup }`; `kind` is the ticket type, `lead` or `task`, and
	 * `record` the object as stored, for the list's templates.
	 *
	 * The view, every key optional: `bucket` (one list, '' for all), `lens`
	 * (one kind of work), `sort` (`deadline` by default), `done` (true lists
	 * finished work too), `scope` (`mine` by default, `team` or
	 * `organisation`, REQ-MW-180), `member` (one member of the team),
	 * `priority` (comma-separated priorities to keep) and `pipeline` (keep
	 * only the leads of this pipeline; tickets and follow-ups stay, REQ-MW-200).
	 *
	 * @param string $userId The Nextcloud UID asking.
	 * @param array<string, mixed> $view The view (the keys above).
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 *
	 * @throws RuntimeException When OpenRegister is unreachable.
	 * @throws \InvalidArgumentException For a scope the user may not choose, a member outside their team, or an unknown priority.
	 *
	 * @spec openspec/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-priority-and-pipeline-filters-req-mw-200
	 */
	public function items(string $userId, array $view=[]): array;

	/**
	 * One sentence on the user's open work, for My work's header: how many
	 * items, by kind, and how many are due today ("9 items are with you: 7
	 * tickets, 1 lead and 1 follow-up; 2 must be answered today").
	 *
	 * @param string $userId The Nextcloud UID.
	 *
	 * @return string The sentence.
	 *
	 * @throws RuntimeException When OpenRegister is unreachable.
	 *
	 * @spec openspec/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
	 */
	public function summary(string $userId): string;

	/**
	 * The figures of My work's "Your week so far" tiles over the current
	 * calendar week, for the tickets of the scope: the user's own (`mine`),
	 * their team's (`team`) or everyone's (`organisation`).
	 *
	 * @param string $userId The Nextcloud UID.
	 * @param string $scope  `mine`, `team` or `organisation`.
	 *
	 * @return array<string, mixed> The figures and the scope.
	 *
	 * @throws InvalidArgumentException For a scope the user may not choose.
	 *
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-kpi-summary-tiles-on-my-work-req-mw-090
	 */
	public function getWeekStats(string $userId, string $scope='mine'): array;
}//end interface
