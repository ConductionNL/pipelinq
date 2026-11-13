<?php

/**
 * Pipelinq ForecastSlipRule.
 *
 * Whether an update moves a commit or best-case deal's expected close date past
 * the end of the fiscal period its old close date was in (REQ-FRC-012).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/forecast-slip-reason/proposal.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;

/**
 * Decides whether a deal's close date slipped out of its forecast period.
 *
 * @spec openspec/changes/forecast-slip-reason/proposal.md
 */
class ForecastSlipRule {

	/**
	 * The open categories whose deals must say why they slip out of their period.
	 */
	public const CATEGORIES = ['commit', 'best_case'];

	/**
	 * Constructor.
	 *
	 * @param FiscalPeriodService $periods The fiscal quarters a close date falls in.
	 *
	 * @return void
	 */
	public function __construct(
		private FiscalPeriodService $periods = new FiscalPeriodService(),
	) {
	}//end __construct()

	/**
	 * Whether an update moves a commit or best-case deal's expected close date past
	 * the end of the fiscal period its old close date was in (REQ-FRC-012).
	 *
	 * @param array<string, mixed> $oldData The deal before the update.
	 * @param array<string, mixed> $newData The deal after the update.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/forecast-slip-reason/proposal.md
	 */
	public function slips(array $oldData, array $newData): bool {
		if (in_array(($newData['forecast_category'] ?? null), self::CATEGORIES, true) === false) {
			return false;
		}

		$oldClose = $this->closeDate(value: ($oldData['expectedCloseDate'] ?? null));
		$newClose = $this->closeDate(value: ($newData['expectedCloseDate'] ?? null));
		if ($oldClose === null || $newClose === null) {
			return false;
		}

		$periodEnd = $this->periods->periodEnd(periodId: $this->periods->periodIdFor(date: $oldClose));

		return $periodEnd !== null && $newClose > $periodEnd;
	}//end slips()

	/**
	 * The calendar day of a close date value.
	 *
	 * @param mixed $value A `Y-m-d` date or a date-time.
	 *
	 * @return DateTimeImmutable|null Midnight on that day, or null when it is no date.
	 */
	private function closeDate(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value)) !== 1) {
			return null;
		}

		$day = substr(trim($value), 0, 10);
		if (checkdate((int)substr($day, 5, 2), (int)substr($day, 8, 2), (int)substr($day, 0, 4)) === false) {
			return null;
		}

		return new DateTimeImmutable($day);
	}//end closeDate()
}//end class
