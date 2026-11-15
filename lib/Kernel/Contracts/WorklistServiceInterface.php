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
	 * @param string $userId The Nextcloud UID whose work to list (assignee scoping).
	 * @param int|null $limit Optional cap on returned rows (widget uses 5); null = all.
	 *
	 * @return array{
	 *   items: array<int, array<string, mixed>>,
	 *   total: int,
	 *   leadCount: int,
	 *   requestCount: int
	 * }
	 *
	 * @throws RuntimeException When OpenRegister is unreachable.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function getMine(string $userId, ?int $limit = null): array;

	/**
	 * Every open item of the user's work, tickets, leads and follow-up tasks,
	 * in one shape, sorted into My work's lists (`bucket`) and the phone's
	 * due groups (`dueGroup`), the most urgent first.
	 *
	 * Each item: `{ kind, id, title, priority, routeName, record, dueAt,
	 * bucket, dueGroup }`; `kind` is the ticket type, `lead` or `task`, and
	 * `record` the object as stored, for the list's templates.
	 *
	 * @param string $userId The Nextcloud UID whose work to list.
	 * @param string $bucket Keep one list (`answer-today`, `waiting-on-me`, `waiting-on-customer`); '' for all.
	 * @param string $lens   Keep one kind of work (`tickets`, `leads`, `tasks`); '' or `all` for all.
	 * @param string $sort   `deadline` (default), `waiting` (oldest first), `newest` or `priority`.
	 * @param string $scope  `open` (default), or `all` to list finished work too (the page's "Toon afgerond").
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 *
	 * @throws RuntimeException When OpenRegister is unreachable.
	 *
	 * @spec openspec/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
	 */
	public function items(string $userId, string $bucket='', string $lens='', string $sort='deadline', string $scope='open'): array;

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
}//end interface
