<?php

/**
 * Pipelinq ContractFiguresService.
 *
 * The figures a contract's page shows (board PqContract): the monthly and
 * annual value, the notice date and the days to the end, and this year's
 * invoicing read from the contract's invoice history.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use Exception;

/**
 * Reads a contract's figures from the contract alone.
 *
 * @spec openspec/changes/contract-figures/specs/contract-renewal-tracking/spec.md#requirement-a-contract-page-reads-its-figures
 */
class ContractFiguresService {
	/**
	 * Months per billing period.
	 *
	 * @var array<string, int>
	 */
	private const PERIOD_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'annual' => 12];

	/**
	 * Constructor.
	 *
	 * @param RecurringRevenueService $revenueService Normalises a period value to a month.
	 */
	public function __construct(
		private RecurringRevenueService $revenueService,
	) {
	}//end __construct()

	/**
	 * The figures of a contract on a day.
	 *
	 * @param array<string, mixed> $contract The contract.
	 * @param DateTimeImmutable $today The day.
	 *
	 * @return array<string, mixed> The figures.
	 *
	 * @spec openspec/changes/contract-figures/specs/contract-renewal-tracking/spec.md#requirement-a-contract-page-reads-its-figures
	 */
	public function figures(array $contract, DateTimeImmutable $today): array {
		$interval = (string)($contract['billingInterval'] ?? '');
		$perPeriod = (float)($contract['valuePerInterval'] ?? $contract['value'] ?? 0);
		$monthly = $this->revenueService->normalizeToMonthly(billingInterval: $interval, valuePerInterval: $perPeriod);
		$annual = ($monthly * 12.0);
		if ($interval === 'one-off') {
			$annual = $perPeriod;
		}

		$end = $this->dateOf(value: $contract['endDate'] ?? null);
		$year = (int)$today->format('Y');
		[$sent, $invoiced] = $this->invoicedIn(contract: $contract, year: $year, perPeriod: $perPeriod);

		return [
			'currency' => (string)($contract['currency'] ?? ''),
			'monthlyValue' => $monthly,
			'annualValue' => $annual,
			'noticeDate' => $this->noticeDate(end: $end, noticeDays: (int)($contract['noticePeriodDays'] ?? 0)),
			'daysToEnd' => $this->daysTo(end: $end, today: $today),
			'invoicedThisYear' => $invoiced,
			'invoicesSent' => $sent,
			'invoicesDue' => $this->periodsInYear(
				interval: $interval,
				start: $this->dateOf(value: $contract['startDate'] ?? null),
				end: $end,
				year: $year
			),
		];
	}//end figures()

	/**
	 * The last day to give notice: the end date less the notice period.
	 *
	 * @param DateTimeImmutable|null $end The contract's end.
	 * @param int $noticeDays The notice period in days.
	 *
	 * @return string|null The day, or null without an end or a notice period.
	 */
	private function noticeDate(?DateTimeImmutable $end, int $noticeDays): ?string {
		if ($end === null || $noticeDays <= 0) {
			return null;
		}

		return $end->modify(sprintf('-%d days', $noticeDays))->format('Y-m-d');
	}//end noticeDate()

	/**
	 * Days from today to the end, negative once it passed.
	 *
	 * @param DateTimeImmutable|null $end The contract's end.
	 * @param DateTimeImmutable $today The day.
	 *
	 * @return int|null The days, or null without an end.
	 */
	private function daysTo(?DateTimeImmutable $end, DateTimeImmutable $today): ?int {
		if ($end === null) {
			return null;
		}

		return (int)$today->setTime(0, 0)->diff($end->setTime(0, 0))->format('%r%a');
	}//end daysTo()

	/**
	 * The sends of a year and their amount. Read from `invoiceHistory`; a
	 * contract without one counts its last send (`invoicedAt`) at the period value.
	 *
	 * @param array<string, mixed> $contract The contract.
	 * @param int $year The year.
	 * @param float $perPeriod The value per period.
	 *
	 * @return array{0: int, 1: float} The sends and the amount.
	 */
	private function invoicedIn(array $contract, int $year, float $perPeriod): array {
		$sends = $contract['invoiceHistory'] ?? [];
		if (is_array($sends) === false || count($sends) === 0) {
			$sends = [];
			if (empty($contract['invoicedAt']) === false) {
				$sends[] = ['sentAt' => $contract['invoicedAt'], 'amount' => $perPeriod];
			}
		}

		$sent = 0;
		$amount = 0.0;
		foreach ($sends as $send) {
			$sentAt = null;
			if (is_array($send) === true) {
				$sentAt = $this->dateOf(value: $send['sentAt'] ?? null);
			}

			if ($sentAt !== null && (int)$sentAt->format('Y') === $year) {
				$sent++;
				$amount += (float)($send['amount'] ?? $perPeriod);
			}
		}

		return [$sent, $amount];
	}//end invoicedIn()

	/**
	 * How many billing periods start within a year and the contract's term.
	 *
	 * @param string $interval The billing interval.
	 * @param DateTimeImmutable|null $start The contract's start.
	 * @param DateTimeImmutable|null $end The contract's end.
	 * @param int $year The year.
	 *
	 * @return int The periods.
	 */
	private function periodsInYear(string $interval, ?DateTimeImmutable $start, ?DateTimeImmutable $end, int $year): int {
		if ($start === null) {
			return 0;
		}

		$months = (self::PERIOD_MONTHS[$interval] ?? 0);
		if ($months === 0) {
			return (int)((int)$start->format('Y') === $year);
		}

		$count = 0;
		$periodStart = $start;
		while ((int)$periodStart->format('Y') <= $year && ($end === null || $periodStart <= $end)) {
			if ((int)$periodStart->format('Y') === $year) {
				$count++;
			}

			$periodStart = $periodStart->modify(sprintf('+%d months', $months));
		}

		return $count;
	}//end periodsInYear()

	/**
	 * A date value as a day, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null The day.
	 */
	private function dateOf(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception $e) {
			return null;
		}
	}//end dateOf()
}//end class
