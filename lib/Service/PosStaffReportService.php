<?php

/**
 * Pipelinq PosStaffReportService.
 *
 * Server-side aggregator for the per-staff sales report
 * (pos-staff-pin-permissions REQ-PSP-008). Groups fiscally-final
 * posTransaction objects (status in confirmed / settled / refunded) by
 * staffMemberId and sums the persisted server-authoritative totals.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#10.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Util\CastingPayloadToArray;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;

/**
 * Service that aggregates posTransaction objects per staff member.
 *
 * Reads the persisted server-authoritative `total` / `totalTax` fields on
 * each posTransaction (these were written by PosTransactionService on
 * confirm), so the report is always consistent with the receipts and the
 * BTW report. Refunded transactions are netted out (negative contribution
 * via the persisted refund flag).
 *
 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#10.2
 */
class PosStaffReportService {
	use CastingPayloadToArray;

	/**
	 * The fiscally-final statuses that count toward the report.
	 *
	 * @var array<int, string>
	 */
	private const FINAL_STATUSES = ['confirmed', 'settled', 'refunded'];

	/**
	 * The five grouped aggregations, in the order they run: each a sum field (null for a
	 * count) and a status filter. A single grouped SUM cannot apply the per-row refund
	 * sign, so the netting is reconstructed from the positive and the refunded sums.
	 *
	 * @var array<string, array{0: string|null, 1: array<string, mixed>}>
	 */
	private const STAFF_METRICS = [
		'transactionCount' => [null, ['status' => ['in' => ['confirmed', 'settled', 'refunded']]]],
		'positiveTotal' => ['total', ['status' => ['in' => ['confirmed', 'settled']]]],
		'refundedTotal' => ['total', ['status' => 'refunded']],
		'positiveTax' => ['totalTax', ['status' => ['in' => ['confirmed', 'settled']]]],
		'refundedTax' => ['totalTax', ['status' => 'refunded']],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param PosStaffService $posStaffService The POS staff service (for name lookup).
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 * @param AggregationRunner $aggregationRunner Runs the staff-report aggregations.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private PosStaffService $posStaffService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly AggregationRunner $aggregationRunner,
	) {
	}//end __construct()

	/**
	 * Build the per-staff sales aggregation report.
	 *
	 * @return array<int, array{staffMemberId: string, displayName: string, transactionCount: int, total: float, totalTax: float}> Per-staff rows.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#10.2
	 */
	public function staffSalesReport(): array {
		try {
			$byStaff = $this->aggregateStaffTotals();
		} catch (\Throwable $e) {
			// OpenRegister aggregation unavailable — fall back to the original
			// hydrate-and-reduce path so the report still renders.
			$this->logger->debug(
				'Pipelinq: staff-sales aggregation failed; using PHP fallback',
				['exception' => $e->getMessage()]
			);
			$byStaff = $this->aggregateStaffTotalsPhp(rows: $this->fetchFinalTransactions());
		}

		return array_values($this->roundedToCents(byStaff: $this->withDisplayNames(byStaff: $byStaff)));
	}//end staffSalesReport()

	/**
	 * Resolve display names from posStaff. Failures fall back to the UUID.
	 *
	 * @param array<string, array<string, mixed>> $byStaff The per-staff rows.
	 *
	 * @return array<string, array<string, mixed>> The rows with their display names.
	 */
	private function withDisplayNames(array $byStaff): array {
		foreach (array_keys($byStaff) as $staffId) {
			try {
				$staff = $this->posStaffService->getStaff(id: $staffId);
				$byStaff[$staffId]['displayName'] = (string)($staff['displayName'] ?? $staffId);
			} catch (\Throwable $e) {
				$byStaff[$staffId]['displayName'] = $staffId;
			}
		}

		return $byStaff;
	}//end withDisplayNames()

	/**
	 * Round monetary values to cents (server-authoritative).
	 *
	 * @param array<string, array<string, mixed>> $byStaff The per-staff rows.
	 *
	 * @return array<string, array<string, mixed>> The rounded rows.
	 */
	private function roundedToCents(array $byStaff): array {
		foreach (array_keys($byStaff) as $staffId) {
			$byStaff[$staffId]['total'] = round($byStaff[$staffId]['total'], 2);
			$byStaff[$staffId]['totalTax'] = round($byStaff[$staffId]['totalTax'], 2);
		}

		return $byStaff;
	}//end roundedToCents()

	/**
	 * Compute the per-staff totals by pushing five grouped aggregations down
	 * into OpenRegister, then combining them in PHP.
	 *
	 * The original PHP reduce applied a per-row sign (+1 for confirmed/settled,
	 * -1 for refunded) before summing `total` / `totalTax` grouped by
	 * `staffMemberId`. A single grouped SUM cannot express that per-row sign, so
	 * the refund-netting is reconstructed from two grouped SUMs:
	 *
	 *   total(staff)    = SUM(total | confirmed,settled)   - SUM(total | refunded)
	 *   totalTax(staff) = SUM(totalTax | confirmed,settled) - SUM(totalTax | refunded)
	 *
	 * The `transactionCount` is a grouped COUNT over all three final statuses
	 * (refunds count toward the transaction count, exactly as before). Rows with
	 * an empty `staffMemberId` are excluded server-side via the COUNT grouping
	 * (an empty key is folded out below) to match the prior `if ($staffId === '')
	 * continue;` guard. Verified live to match the PHP reduce, refund netting
	 * included.
	 *
	 * @return array<string, array{staffMemberId: string, displayName: string, transactionCount: int, total: float, totalTax: float}>
	 *         Keyed by staffMemberId.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable.
	 */
	private function aggregateStaffTotals(): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');
		$groups = new StaffGroupTotals(
			runner: $this->getAggregationRunner(),
			table: new PosTransactionTable(register: $register, schema: $schema)
		);

		$maps = [];
		foreach (self::STAFF_METRICS as $name => [$sumField, $filter]) {
			$maps[$name] = $groups->valuesFor(sumField: $sumField, filter: $filter);
		}

		return $this->staffRows(maps: $maps);
	}//end aggregateStaffTotals()

	/**
	 * Combine the grouped aggregations into one row per staff member.
	 *
	 * @param array<string, array<string, float|int>> $maps Each metric's staffMemberId => value map, keyed as STAFF_METRICS.
	 *
	 * @return array<string, array{staffMemberId: string, displayName: string, transactionCount: int, total: float, totalTax: float}>
	 */
	private function staffRows(array $maps): array {
		$all = [];
		foreach ($maps as $map) {
			$all += $map;
		}

		$byStaff = [];
		foreach (array_keys($all) as $staffId) {
			// Drop the empty-staffMemberId bucket — the prior PHP path skipped
			// rows with an empty staff id.
			if ((string)$staffId === '') {
				continue;
			}

			$byStaff[(string)$staffId] = $this->staffRow(staffId: (string)$staffId, maps: $maps);
		}

		return $byStaff;
	}//end staffRows()

	/**
	 * One staff member's row: the transaction count and the refund-netted totals.
	 *
	 * @param string                                  $staffId The staff member id.
	 * @param array<string, array<string, float|int>> $maps    Each metric's staffMemberId => value map.
	 *
	 * @return array{staffMemberId: string, displayName: string, transactionCount: int, total: float, totalTax: float}
	 */
	private function staffRow(string $staffId, array $maps): array {
		return [
			'staffMemberId' => $staffId,
			'displayName' => '',
			'transactionCount' => (int)($maps['transactionCount'][$staffId] ?? 0),
			'total' => ((float)($maps['positiveTotal'][$staffId] ?? 0.0) - (float)($maps['refundedTotal'][$staffId] ?? 0.0)),
			'totalTax' => ((float)($maps['positiveTax'][$staffId] ?? 0.0) - (float)($maps['refundedTax'][$staffId] ?? 0.0)),
		];
	}//end staffRow()

	/**
	 * PHP fallback reduce — the original hydrate-and-sum implementation, applied
	 * to an already-fetched transaction set. Retained as the degradation path
	 * when the OpenRegister aggregation runner is unavailable.
	 *
	 * @param array<int, array<string, mixed>> $rows The final-status transactions.
	 *
	 * @return array<string, array{staffMemberId: string, displayName: string, transactionCount: int, total: float, totalTax: float}>
	 *         Keyed by staffMemberId.
	 */
	private function aggregateStaffTotalsPhp(array $rows): array {
		$byStaff = [];
		foreach ($rows as $tx) {
			$staffId = (string)($tx['staffMemberId'] ?? '');
			if ($staffId === '') {
				continue;
			}

			$byStaff[$staffId] = ($byStaff[$staffId] ?? $this->emptyStaffRow(staffId: $staffId));
			$sign = $this->signOf(transaction: $tx);
			$byStaff[$staffId]['transactionCount']++;
			$byStaff[$staffId]['total'] += ($sign * (float)($tx['total'] ?? 0));
			$byStaff[$staffId]['totalTax'] += ($sign * (float)($tx['totalTax'] ?? 0));
		}

		return $byStaff;
	}//end aggregateStaffTotalsPhp()

	/**
	 * A staff member's row before any transaction is counted.
	 *
	 * @param string $staffId The staff member id.
	 *
	 * @return array{staffMemberId: string, displayName: string, transactionCount: int, total: float, totalTax: float}
	 */
	private function emptyStaffRow(string $staffId): array {
		return [
			'staffMemberId' => $staffId,
			'displayName' => '',
			'transactionCount' => 0,
			'total' => 0.0,
			'totalTax' => 0.0,
		];
	}//end emptyStaffRow()

	/**
	 * The sign a transaction counts with: -1 for a refund, +1 otherwise.
	 *
	 * @param array<string, mixed> $transaction The transaction.
	 *
	 * @return float The sign.
	 */
	private function signOf(array $transaction): float {
		if (($transaction['status'] ?? '') === 'refunded') {
			return -1.0;
		}

		return 1.0;
	}//end signOf()

	/**
	 * Read posTransaction objects that count toward the report (final statuses).
	 *
	 * @return array<int, array<string, mixed>> The transactions as flat arrays.
	 */
	private function fetchFinalTransactions(): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 5000,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: staff-sales report failed to load transactions',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return $this->finalOnly(results: $results);
	}//end fetchFinalTransactions()

	/**
	 * The results with a final status, as flat arrays.
	 *
	 * @param iterable<mixed> $results The fetched objects.
	 *
	 * @return array<int, array<string, mixed>> The final-status transactions.
	 */
	private function finalOnly(iterable $results): array {
		$out = [];
		foreach ($results as $result) {
			$transaction = $this->toArray(object: $result);
			if (in_array((string)($transaction['status'] ?? ''), self::FINAL_STATUSES, true) === false) {
				continue;
			}

			$out[] = $transaction;
		}

		return $out;
	}//end finalOnly()

	/**
	 * Resolve the register + schema config key into stored IDs.
	 *
	 * @param string $schemaKey The app-config schema key.
	 *
	 * @return array{0: string, 1: string} The [register, schema] IDs.
	 *
	 * @throws OCSNotFoundException If the register or schema is not configured.
	 */
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}//end config()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object The object service.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Get the OpenRegister ad-hoc AggregationRunner.
	 *
	 * Constructor-injected the same way ObjectService is, so the per-staff COUNT
	 * and signed SUMs are computed by OpenRegister (ADR-022) instead of hydrating
	 * every transaction and reducing in PHP. It was formerly resolved from the DI
	 * container inside a try/catch; since the migration to injection that catch
	 * was unreachable — phpstan reports it as a dead catch.
	 *
	 * @return object The aggregation runner.
	 */
	private function getAggregationRunner(): object {
		return $this->aggregationRunner;
	}//end getAggregationRunner()
}//end class
