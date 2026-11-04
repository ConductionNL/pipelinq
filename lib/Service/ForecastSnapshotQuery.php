<?php

/**
 * Pipelinq ForecastSnapshotQuery.
 *
 * The request values of one forecast snapshot export.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Period, level, owner, format and page of a snapshot export, with the page
 * clamped to 1..200 rows from offset 0 up.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010
 */
final class ForecastSnapshotQuery {
	/**
	 * The request parameters, as ReadsTypedRequestParameters reads them.
	 *
	 * @var array<string, array{0: string, 1: mixed}>
	 */
	public const PARAMETERS = [
		'periodId' => ['string', ''],
		'level' => ['string', ''],
		'ownerId' => ['?string', null],
		'format' => ['string', 'json'],
		'limit' => ['int', 50],
		'offset' => ['int', 0],
	];

	/**
	 * The allowed hierarchy levels for a snapshot query.
	 *
	 * @var array<int, string>
	 */
	private const READ_LEVELS = ['rep', 'team', 'division', 'company'];

	/**
	 * Constructor.
	 *
	 * @param array{periodId: string, level: string, ownerId: ?string, format: string, limit: int, offset: int} $values The request values.
	 */
	public function __construct(
		private readonly array $values,
	) {
	}//end __construct()

	/**
	 * Whether a period and a known level were given.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010
	 */
	public function isComplete(): bool {
		return $this->values['periodId'] !== '' && in_array($this->values['level'], self::READ_LEVELS, true) === true;
	}//end isComplete()

	/**
	 * The fiscal period id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010
	 */
	public function periodId(): string {
		return $this->values['periodId'];
	}//end periodId()

	/**
	 * The hierarchy level.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010
	 */
	public function level(): string {
		return $this->values['level'];
	}//end level()

	/**
	 * The owner filter, if any.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010
	 */
	public function ownerId(): ?string {
		return $this->values['ownerId'];
	}//end ownerId()

	/**
	 * The page size, 1 to 200.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010
	 */
	public function limit(): int {
		return max(1, min($this->values['limit'], 200));
	}//end limit()

	/**
	 * The page offset, 0 or more.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010
	 */
	public function offset(): int {
		return max(0, $this->values['offset']);
	}//end offset()

	/**
	 * Whether the export is asked for as CSV.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010
	 */
	public function wantsCsv(): bool {
		return strtolower($this->values['format']) === 'csv';
	}//end wantsCsv()

	/**
	 * The download name of the CSV export.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010
	 */
	public function csvFilename(): string {
		return sprintf('forecast-%s-%s.csv', $this->values['periodId'], $this->values['level']);
	}//end csvFilename()
}//end class
