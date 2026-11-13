<?php

/**
 * Pipelinq RecurringRevenueService.
 *
 * Computes recurring-revenue metrics (MRR, ARR, per-client recurring value,
 * per-period renewal rate and churned MRR) from contract objects. Reads
 * contracts through the OpenRegister ObjectService (ADR-022); contains no CRUD
 * wrappers — only aggregation logic over the contract set.
 *
 * Normalization (REQ Recurring Revenue Roll-Up):
 *   monthly   → value
 *   quarterly → value / 3
 *   annual    → value / 12
 *   one-off   → excluded
 * Only `active` and `expiring` contracts contribute to MRR/ARR.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recurring-revenue roll-up over contract objects.
 *
 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
 */
class RecurringRevenueService {
	/**
	 * Statuses that contribute to live recurring revenue.
	 *
	 * @var string[]
	 */
	private const REVENUE_STATUSES = ['active', 'expiring'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param ContainerInterface $container The DI container (ObjectService lookup).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Normalize a single contract's interval value to a monthly figure.
	 *
	 * @param string $billingInterval The billing interval.
	 * @param float $valuePerInterval The value per interval.
	 *
	 * @return float The normalized monthly recurring revenue (0 for one-off).
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
	 */
	public function normalizeToMonthly(string $billingInterval, float $valuePerInterval): float {
		switch ($billingInterval) {
			case 'monthly':
				return $valuePerInterval;
			case 'quarterly':
				return ($valuePerInterval / 3.0);
			case 'annual':
				return ($valuePerInterval / 12.0);
			case 'one-off':
			default:
				return 0.0;
		}
	}//end normalizeToMonthly()

	/**
	 * Compute MRR (sum of normalized monthly values of revenue-status contracts).
	 *
	 * @param array<int, array<string,mixed>> $contracts The contract objects.
	 *
	 * @return float The monthly recurring revenue.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
	 */
	public function computeMrr(array $contracts): float {
		$mrr = 0.0;
		foreach ($contracts as $contract) {
			$status = (string)($contract['status'] ?? '');
			if (in_array($status, self::REVENUE_STATUSES, true) === false) {
				continue;
			}

			$mrr += $this->normalizeToMonthly(
				billingInterval: (string)($contract['billingInterval'] ?? ''),
				valuePerInterval: (float)($contract['valuePerInterval'] ?? 0)
			);
		}

		return round($mrr, 2);
	}//end computeMrr()

	/**
	 * Compute ARR (MRR × 12).
	 *
	 * @param array<int, array<string,mixed>> $contracts The contract objects.
	 *
	 * @return float The annual recurring revenue.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
	 */
	public function computeArr(array $contracts): float {
		return round(($this->computeMrr(contracts: $contracts) * 12.0), 2);
	}//end computeArr()

	/**
	 * Compute the per-client recurring value (MRR) for a given client.
	 *
	 * @param array<int, array<string,mixed>> $contracts All contracts.
	 * @param string $clientRef The client UUID.
	 *
	 * @return float The client's monthly recurring value.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
	 */
	public function computeClientMrr(array $contracts, string $clientRef): float {
		$clientContracts = array_filter(
			$contracts,
			static fn (array $c): bool => ((string)($c['clientRef'] ?? '')) === $clientRef
		);

		return $this->computeMrr(contracts: array_values($clientContracts));
	}//end computeClientMrr()

	/**
	 * Compute the renewal rate and churned MRR for contracts whose renewal
	 * window closed within the given period.
	 *
	 * Renewal rate = renewed / (renewed + churned). Churned MRR = sum of the
	 * normalized monthly value of the churned contracts.
	 *
	 * @param array<int, array<string,mixed>> $contracts All contracts.
	 * @param string $periodFrom ISO date (inclusive lower bound on endDate).
	 * @param string $periodTo ISO date (inclusive upper bound on endDate).
	 *
	 * @return array{renewed:int, churned:int, renewalRate:float, churnedMrr:float}
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
	 */
	public function computeRenewalMetrics(array $contracts, string $periodFrom, string $periodTo): array {
		$tally = ['renewed' => 0, 'churned' => 0, 'churnedMrr' => 0.0];
		foreach ($this->endingWithin(contracts: $contracts, period: [$periodFrom, $periodTo]) as $contract) {
			$tally = $this->tallied(tally: $tally, contract: $contract);
		}

		return $this->renewalFigures(tally: $tally);
	}//end computeRenewalMetrics()

	/**
	 * The contracts whose end date falls within the period, bounds included.
	 *
	 * @param array<int, array<string, mixed>> $contracts The contracts.
	 * @param array{0: string, 1: string}      $period    From and to (YYYY-MM-DD).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function endingWithin(array $contracts, array $period): array {
		[$periodFrom, $periodTo] = $period;
		return array_values(
			array_filter(
				$contracts,
				static function ($contract) use ($periodFrom, $periodTo): bool {
					$endDate = (string)($contract['endDate'] ?? '');
					return $endDate !== '' && $endDate >= $periodFrom && $endDate <= $periodTo;
				}
			)
		);
	}//end endingWithin()

	/**
	 * The tally with one closing contract counted: a renewal, or a churn with
	 * its monthly value; any other status leaves it as it is.
	 *
	 * @param array{renewed: int, churned: int, churnedMrr: float} $tally    The tally so far.
	 * @param array<string, mixed>                                 $contract The contract.
	 *
	 * @return array{renewed: int, churned: int, churnedMrr: float}
	 */
	private function tallied(array $tally, array $contract): array {
		$status = (string)($contract['status'] ?? '');
		if ($status === 'renewed') {
			$tally['renewed']++;
		} elseif ($status === 'churned') {
			$tally['churned']++;
			$tally['churnedMrr'] += $this->normalizeToMonthly(
				billingInterval: (string)($contract['billingInterval'] ?? ''),
				valuePerInterval: (float)($contract['valuePerInterval'] ?? 0)
			);
		}

		return $tally;
	}//end tallied()

	/**
	 * The renewal figures of a tally: the rate over closed contracts, rounded
	 * to one decimal, and the churned MRR rounded to cents.
	 *
	 * @param array{renewed: int, churned: int, churnedMrr: float} $tally The tally.
	 *
	 * @return array{renewed: int, churned: int, renewalRate: float, churnedMrr: float}
	 */
	private function renewalFigures(array $tally): array {
		$closed = ($tally['renewed'] + $tally['churned']);
		$renewalRate = 0.0;
		if ($closed > 0) {
			$renewalRate = round((($tally['renewed'] / $closed) * 100.0), 1);
		}

		return [
			'renewed' => $tally['renewed'],
			'churned' => $tally['churned'],
			'renewalRate' => $renewalRate,
			'churnedMrr' => round($tally['churnedMrr'], 2),
		];
	}//end renewalFigures()

	/**
	 * Load all contracts from OpenRegister, returning [] on any failure
	 * (graceful degradation — recurring-revenue widgets render an empty state).
	 *
	 * @return array<int, array<string,mixed>> The contract objects.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
	 */
	public function loadContracts(): array {
		$scope = $this->contractScope();
		if ($scope === null) {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			return $this->asArrays(rows: $objectService->findAll(['filters' => $scope, 'limit' => 10000]));
		} catch (Throwable $e) {
			$this->logger->warning('RecurringRevenueService: failed to load contracts', ['error' => $e->getMessage()]);
			return [];
		}
	}//end loadContracts()

	/**
	 * The configured register and contract schema; null when either is missing.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private function contractScope(): ?array {
		$registerId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'contract_schema', '');
		if ($registerId === '' || $schemaId === '') {
			return null;
		}

		return ['register' => $registerId, 'schema' => $schemaId];
	}//end contractScope()

	/**
	 * The rows as arrays, in order: entity rows serialised, array rows as
	 * they are, anything else dropped.
	 *
	 * @param iterable<mixed> $rows The rows read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function asArrays(iterable $rows): array {
		$contracts = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$contracts[] = $row->jsonSerialize();
			} elseif (is_array($row) === true) {
				$contracts[] = $row;
			}
		}

		return $contracts;
	}//end asArrays()

	/**
	 * Build the full recurring-revenue summary for the dashboard.
	 *
	 * @return array{mrr:float, arr:float, activeCount:int, expiringCount:int}
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
	 */
	public function getSummary(): array {
		$contracts = $this->loadContracts();
		$statuses = array_count_values(array_map(static fn ($contract): string => (string)($contract['status'] ?? ''), $contracts));

		return [
			'mrr' => $this->computeMrr(contracts: $contracts),
			'arr' => $this->computeArr(contracts: $contracts),
			'activeCount' => ($statuses['active'] ?? 0),
			'expiringCount' => ($statuses['expiring'] ?? 0),
		];
	}//end getSummary()
}//end class
