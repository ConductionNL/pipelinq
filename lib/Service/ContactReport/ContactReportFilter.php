<?php

/**
 * Pipelinq ContactReportFilter.
 *
 * The team, channel and employee filters a contact report is read with.
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

/**
 * The filters of a contact report; an empty value does not filter.
 *
 * The team is a Nextcloud group: a contact moment counts for a team when
 * its employee is a member of that group.
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md
 */
final class ContactReportFilter {
	/**
	 * The team (Nextcloud group id), or '' for every team.
	 *
	 * @var string
	 */
	public readonly string $team;

	/**
	 * The channel, or '' for every channel.
	 *
	 * @var string
	 */
	public readonly string $channel;

	/**
	 * The employee (Nextcloud user id), or '' for everyone.
	 *
	 * @var string
	 */
	public readonly string $employee;

	/**
	 * Constructor; a value is trimmed, and a value that is not a string does not filter.
	 *
	 * @param mixed $team     The team (Nextcloud group id).
	 * @param mixed $channel  The channel.
	 * @param mixed $employee The employee (Nextcloud user id).
	 */
	public function __construct(mixed $team = '', mixed $channel = '', mixed $employee = '') {
		$this->team = self::textOf(value: $team);
		$this->channel = self::textOf(value: $channel);
		$this->employee = self::textOf(value: $employee);
	}//end __construct()

	/**
	 * The filters as label => value, for the exported report's header.
	 *
	 * @return array<string, string> Team, Channel and Employee; '' reads as all.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md
	 */
	public function labels(): array {
		return [
			'Team' => self::allWhenEmpty(value: $this->team),
			'Channel' => self::allWhenEmpty(value: $this->channel),
			'Employee' => self::allWhenEmpty(value: $this->employee),
		];
	}//end labels()

	/**
	 * A trimmed string, or '' for anything that is not a string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string The text.
	 */
	private static function textOf(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end textOf()

	/**
	 * The value, or "all" when it does not filter.
	 *
	 * @param string $value The value.
	 *
	 * @return string The label.
	 */
	private static function allWhenEmpty(string $value): string {
		if ($value === '') {
			return 'all';
		}

		return $value;
	}//end allWhenEmpty()
}//end class
