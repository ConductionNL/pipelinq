<?php

/**
 * Pipelinq WorkBuckets.
 *
 * Sorts a work item into the list of My work it belongs to, and into the
 * due group the phone shows it under.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Worklist
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/my-work-one-list-of-my-items/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Worklist;

use DateTimeImmutable;

/**
 * Pure classification of work items: the due date by kind, the list
 * ("answer-today", "waiting-on-me", "waiting-on-customer") and the due group
 * ("late", "today", "week", "later", "none").
 *
 * @spec openspec/changes/my-work-one-list-of-my-items/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
 */
class WorkBuckets {

	/**
	 * The field that holds an item's due date, by kind; a ticket kind not
	 * listed reads `slaDeadline`.
	 *
	 * @var array<string, string>
	 */
	private const DUE_FIELDS = [
		'lead' => 'expectedCloseDate',
		'task' => 'deadline',
	];

	/**
	 * Priority ranks, urgent first.
	 *
	 * @var array<string, int>
	 */
	private const PRIORITY_RANK = ['urgent' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];

	/**
	 * The ticket status that hands the turn to the customer.
	 */
	private const WAITING_ON_CUSTOMER = 'awaiting_customer';

	/**
	 * The row with `dueAt`, `bucket` and `dueGroup` added, from its `kind`
	 * and its raw `record`.
	 *
	 * @param array<string, mixed> $row The row (`kind`, `record`).
	 * @param DateTimeImmutable    $now Now.
	 *
	 * @return array<string, mixed> The decorated row.
	 *
	 * @spec openspec/changes/my-work-one-list-of-my-items/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
	 */
	public function decorate(array $row, DateTimeImmutable $now): array {
		$kind   = (string)($row['kind'] ?? '');
		$record = $row['record'] ?? [];
		if (is_array($record) === false) {
			$record = [];
		}

		$dueAt    = self::dueAt(kind: $kind, record: $record);
		$dueTs    = self::timestamp(value: $dueAt);
		$today    = $now->setTime(0, 0)->getTimestamp();
		$tomorrow = ($today + 86400);

		$bucket = 'waiting-on-me';
		if (self::isTicket(kind: $kind) === true && (string)($record['status'] ?? '') === self::WAITING_ON_CUSTOMER) {
			$bucket = 'waiting-on-customer';
		} else if ($dueTs !== null && $dueTs < $tomorrow) {
			$bucket = 'answer-today';
		}

		$row['dueAt']    = $dueAt;
		$row['bucket']   = $bucket;
		$row['dueGroup'] = self::dueGroup(dueTs: $dueTs, today: $today);
		return $row;
	}//end decorate()

	/**
	 * Whether a lens (`all`, `tickets`, `leads`, `tasks`; '' for all) keeps a kind.
	 *
	 * @param string $kind The item's kind.
	 * @param string $lens The lens.
	 *
	 * @return bool True when the item belongs in the lens.
	 *
	 * @spec openspec/changes/my-work-one-list-of-my-items/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
	 */
	public function inLens(string $kind, string $lens): bool {
		if ($lens === 'tickets') {
			return self::isTicket(kind: $kind);
		}

		if ($lens === 'leads') {
			return $kind === 'lead';
		}

		if ($lens === 'tasks') {
			return $kind === 'task';
		}

		return true;
	}//end inLens()

	/**
	 * The order of a list: `deadline` (the due date, undated last), `waiting`
	 * (longest waiting first), `newest`, or `priority` (urgent first, then
	 * the due date).
	 *
	 * @param string $sort The sort key.
	 *
	 * @return callable(array<string, mixed>, array<string, mixed>): int The comparator.
	 *
	 * @spec openspec/changes/my-work-one-list-of-my-items/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
	 */
	public function comparator(string $sort): callable {
		$byDue = static fn (array $a, array $b): int => [($a['dueAt'] === null), (string)$a['dueAt']] <=> [($b['dueAt'] === null), (string)$b['dueAt']];
		$since = static fn (array $row): string => (string)($row['record']['occurredAt'] ?? ($row['record']['createdAt'] ?? ''));
		if ($sort === 'waiting') {
			return static fn (array $a, array $b): int => $since($a) <=> $since($b);
		}

		if ($sort === 'newest') {
			return static fn (array $a, array $b): int => $since($b) <=> $since($a);
		}

		if ($sort === 'priority') {
			$rank = static fn (array $row): int => (self::PRIORITY_RANK[$row['priority']] ?? 2);
			return static fn (array $a, array $b): int => (($rank($a) <=> $rank($b)) ?: $byDue($a, $b));
		}

		return $byDue;
	}//end comparator()

	/**
	 * Whether a kind is a ticket type (anything but a lead or a follow-up task).
	 *
	 * @param string $kind The kind.
	 *
	 * @return bool True for a ticket.
	 */
	private static function isTicket(string $kind): bool {
		return isset(self::DUE_FIELDS[$kind]) === false;
	}//end isTicket()

	/**
	 * The item's due date as stored, or null.
	 *
	 * @param string               $kind   The kind.
	 * @param array<string, mixed> $record The raw record.
	 *
	 * @return string|null The due date.
	 */
	private static function dueAt(string $kind, array $record): ?string {
		$field = (self::DUE_FIELDS[$kind] ?? 'slaDeadline');
		$value = ($record[$field] ?? null);
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return $value;
	}//end dueAt()

	/**
	 * A date string as a timestamp, or null when it does not parse.
	 *
	 * @param string|null $value The date.
	 *
	 * @return int|null The timestamp.
	 */
	private static function timestamp(?string $value): ?int {
		if ($value === null) {
			return null;
		}

		$stamp = strtotime($value);
		if ($stamp === false) {
			return null;
		}

		return $stamp;
	}//end timestamp()

	/**
	 * The due group: late (before today), today, week (within seven days),
	 * later, or none without a date.
	 *
	 * @param int|null $dueTs The due timestamp.
	 * @param int      $today Today's start.
	 *
	 * @return string The group.
	 */
	private static function dueGroup(?int $dueTs, int $today): string {
		if ($dueTs === null) {
			return 'none';
		}

		if ($dueTs < $today) {
			return 'late';
		}

		if ($dueTs < ($today + 86400)) {
			return 'today';
		}

		if ($dueTs < ($today + (7 * 86400))) {
			return 'week';
		}

		return 'later';
	}//end dueGroup()
}//end class
