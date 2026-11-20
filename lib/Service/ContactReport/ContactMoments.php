<?php

/**
 * Pipelinq ContactMoments.
 *
 * Reads the contact moments a contact report counts.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\ContactReport
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\ContactReport;

use DateInterval;
use DateTimeImmutable;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCA\Pipelinq\Service\TicketService;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * The contact moments of a period, narrowed by a report's filters, each as
 * at (datetime), day (Y-m-d), channel, employee, subject, outcome and
 * seconds (the duration, or null when it has none or cannot be read).
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md
 */
class ContactMoments {
	/**
	 * Constructor.
	 *
	 * @param TicketServiceInterface $tickets The ticket reader.
	 * @param IGroupManager          $groups  The group manager, for the team filter.
	 */
	public function __construct(
		private readonly TicketServiceInterface $tickets,
		private readonly IGroupManager $groups,
	) {
	}//end __construct()

	/**
	 * The contact moments between two days, both included, that pass the filter.
	 *
	 * @param string              $from   The first day (date or datetime), or '' for no lower bound.
	 * @param string              $to     The last day (date or datetime), or '' for no upper bound.
	 * @param ContactReportFilter $filter The filter.
	 *
	 * @return array<int, array<string, mixed>> The moments, in the order the store gave them.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md
	 */
	public function between(string $from, string $to, ContactReportFilter $filter): array {
		$members = $this->teamMembers(team: $filter->team);
		$moments = [];
		foreach ($this->tickets->findByType(ticketType: TicketService::TYPE_CONTACTMOMENT) as $row) {
			$moment = self::momentOf(row: $row);
			if ($moment === null || self::inWindow(day: $moment['day'], from: $from, to: $to) === false) {
				continue;
			}

			if (self::passes(moment: $moment, filter: $filter, members: $members) === true) {
				$moments[] = $moment;
			}
		}

		return $moments;
	}//end between()

	/**
	 * The members of a team, or null when the filter names no team.
	 *
	 * An unknown team has no members, so it matches no contact moment.
	 *
	 * @param string $team The team (group id).
	 *
	 * @return array<string, true>|null The member uids.
	 */
	private function teamMembers(string $team): ?array {
		if ($team === '') {
			return null;
		}

		$members = [];
		$group = $this->groups->get($team);
		if ($group === null) {
			return $members;
		}

		foreach ($group->getUsers() as $user) {
			if ($user instanceof IUser) {
				$members[$user->getUID()] = true;
			}
		}

		return $members;
	}//end teamMembers()

	/**
	 * Whether a moment passes the filter.
	 *
	 * @param array<string, mixed>     $moment  The moment.
	 * @param ContactReportFilter      $filter  The filter.
	 * @param array<string, true>|null $members The team's members, or null for no team filter.
	 *
	 * @return bool Whether it counts.
	 */
	private static function passes(array $moment, ContactReportFilter $filter, ?array $members): bool {
		if ($filter->channel !== '' && $moment['channel'] !== $filter->channel) {
			return false;
		}

		if ($filter->employee !== '' && $moment['employee'] !== $filter->employee) {
			return false;
		}

		return $members === null || isset($members[$moment['employee']]) === true;
	}//end passes()

	/**
	 * Whether a day lies in the window, both bounds included.
	 *
	 * @param string $day  The day, Y-m-d.
	 * @param string $from The first day, or ''.
	 * @param string $to   The last day, or ''.
	 *
	 * @return bool Whether it does.
	 */
	private static function inWindow(string $day, string $from, string $to): bool {
		if ($from !== '' && $day < substr($from, 0, 10)) {
			return false;
		}

		return $to === '' || $day <= substr($to, 0, 10);
	}//end inWindow()

	/**
	 * The moment a stored ticket stands for, or null when it has no readable time.
	 *
	 * @param mixed $row The stored ticket (array or serialisable object).
	 *
	 * @return array<string, mixed>|null The moment.
	 */
	private static function momentOf(mixed $row): ?array {
		if ($row instanceof \JsonSerializable) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return null;
		}

		$time = self::timeOf(value: $row['occurredAt'] ?? null);
		if ($time === null) {
			return null;
		}

		return [
			'at' => $time,
			'day' => $time->format('Y-m-d'),
			'channel' => self::textOf(value: $row['channel'] ?? null, fallback: 'unknown'),
			'employee' => self::textOf(value: $row['assignee'] ?? null, fallback: 'unknown'),
			// A contact moment's title is the subject it was about.
			'subject' => self::textOf(value: $row['title'] ?? null, fallback: ''),
			'outcome' => self::textOf(value: $row['outcome'] ?? null, fallback: ''),
			'seconds' => self::secondsOf(duration: $row['duration'] ?? null),
		];
	}//end momentOf()

	/**
	 * A datetime, or null when the value is not one.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null The time.
	 */
	private static function timeOf(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}
	}//end timeOf()

	/**
	 * A trimmed text, or the fallback when the value is empty or not a string.
	 *
	 * @param mixed  $value    The stored value.
	 * @param string $fallback The fallback.
	 *
	 * @return string The text.
	 */
	private static function textOf(mixed $value, string $fallback): string {
		if (is_string($value) === false || trim($value) === '') {
			return $fallback;
		}

		return trim($value);
	}//end textOf()

	/**
	 * A duration in seconds: an ISO 8601 duration, or a plain number of seconds.
	 *
	 * @param mixed $duration The stored duration.
	 *
	 * @return int|null The seconds, or null when there is none or it cannot be read.
	 */
	private static function secondsOf(mixed $duration): ?int {
		if (is_int($duration) === true || (is_string($duration) === true && ctype_digit($duration) === true)) {
			return (int)$duration;
		}

		if (is_string($duration) === false || $duration === '') {
			return null;
		}

		try {
			$interval = new DateInterval($duration);
		} catch (\Exception) {
			return null;
		}

		return ((int)$interval->d * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
	}//end secondsOf()
}//end class
