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
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\Pipelinq\AppInfo\Application;
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
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10.2
 */
class PosStaffReportService {
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
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10.2
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

		// Resolve display names from posStaff. Failures fall back to the UUID.
		foreach ($byStaff as $staffId => $_row) {
			try {
				$staff = $this->posStaffService->getStaff(id: $staffId);
				$byStaff[$staffId]['displayName'] = (string)($staff['displayName'] ?? $staffId);
			} catch (\Throwable $e) {
				$byStaff[$staffId]['displayName'] = $staffId;
			}
		}

		// Round monetary values to cents (server-authoritative).
		foreach ($byStaff as $staffId => $_row) {
			$byStaff[$staffId]['total'] = round($byStaff[$staffId]['total'], 2);
			$byStaff[$staffId]['totalTax'] = round($byStaff[$staffId]['totalTax'], 2);
		}

		return array_values($byStaff);
	}//end staffSalesReport()

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
		$runner = $this->getAggregationRunner();

		$finalStatuses = ['confirmed', 'settled', 'refunded'];
		$positive = ['confirmed', 'settled'];

		$counts = $this->groupedAgg(
			runner: $runner,
			register: $register,
			schema: $schema,
			metric: 'count',
			field: null,
			filter: ['status' => ['in' => $finalStatuses]]
		);
		$posTotal = $this->groupedAgg(
			runner: $runner,
			register: $register,
			schema: $schema,
			metric: 'sum',
			field: 'total',
			filter: ['status' => ['in' => $positive]]
		);
		$refTotal = $this->groupedAgg(
			runner: $runner,
			register: $register,
			schema: $schema,
			metric: 'sum',
			field: 'total',
			filter: ['status' => 'refunded']
		);
		$posTotalTax = $this->groupedAgg(
			runner: $runner,
			register: $register,
			schema: $schema,
			metric: 'sum',
			field: 'totalTax',
			filter: ['status' => ['in' => $positive]]
		);
		$refTotalTax = $this->groupedAgg(
			runner: $runner,
			register: $register,
			schema: $schema,
			metric: 'sum',
			field: 'totalTax',
			filter: ['status' => 'refunded']
		);

		$staffIds = array_keys(
			($counts + $posTotal + $refTotal + $posTotalTax + $refTotalTax)
		);

		$byStaff = [];
		foreach ($staffIds as $staffId) {
			// Drop the empty-staffMemberId bucket — the prior PHP path skipped
			// rows with an empty staff id.
			if ((string)$staffId === '') {
				continue;
			}

			$byStaff[(string)$staffId] = [
				'staffMemberId' => (string)$staffId,
				'displayName' => '',
				'transactionCount' => (int)($counts[$staffId] ?? 0),
				'total' => ((float)($posTotal[$staffId] ?? 0.0) - (float)($refTotal[$staffId] ?? 0.0)),
				'totalTax' => ((float)($posTotalTax[$staffId] ?? 0.0) - (float)($refTotalTax[$staffId] ?? 0.0)),
			];
		}

		return $byStaff;
	}//end aggregateStaffTotals()

	/**
	 * Run one grouped aggregation and flatten its groups into a
	 * `staffMemberId => value` map.
	 *
	 * @param object $runner The OpenRegister aggregation runner.
	 * @param string $register The register ref.
	 * @param string $schema The schema ref.
	 * @param string $metric count|sum.
	 * @param string|null $field The metric field (null for count).
	 * @param array<string, mixed> $filter The filter map.
	 *
	 * @return array<string, float|int> Map of staffMemberId to the aggregated value.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AggregationQuery::create() is
	 *  OpenRegister's documented static query-builder factory; there is no
	 *  instance to inject.
	 */
	private function groupedAgg(
		object $runner,
		string $register,
		string $schema,
		string $metric,
		?string $field,
		array $filter,
	): array {
		$query = AggregationQuery::create(
			metric: $metric,
			field: $field,
			filter: $filter,
			groupBy: ['field' => 'staffMemberId'],
		);
		$result = $runner->runAdhocByRef(registerRef: $register, schemaRef: $schema, query: $query);

		$map = [];
		foreach (($result['groups'] ?? []) as $group) {
			$key = $group['key'] ?? null;
			if ($key === null) {
				continue;
			}

			$map[(string)$key] = ($group['value'] ?? 0);
		}

		return $map;
	}//end groupedAgg()

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

			if (isset($byStaff[$staffId]) === false) {
				$byStaff[$staffId] = [
					'staffMemberId' => $staffId,
					'displayName' => '',
					'transactionCount' => 0,
					'total' => 0.0,
					'totalTax' => 0.0,
				];
			}

			$sign = 1.0;
			if (($tx['status'] ?? '') === 'refunded') {
				$sign = -1.0;
			}

			$byStaff[$staffId]['transactionCount']++;
			$byStaff[$staffId]['total'] += ($sign * (float)($tx['total'] ?? 0));
			$byStaff[$staffId]['totalTax'] += ($sign * (float)($tx['totalTax'] ?? 0));
		}//end foreach

		return $byStaff;
	}//end aggregateStaffTotalsPhp()

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

		$allowed = ['confirmed', 'settled', 'refunded'];
		$out = [];
		foreach ($results as $result) {
			$transaction = $this->toArray(object: $result);
			if (in_array((string)($transaction['status'] ?? ''), $allowed, true) === false) {
				continue;
			}

			$out[] = $transaction;
		}

		return $out;
	}//end fetchFinalTransactions()

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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}//end config()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException If OpenRegister is not available.
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

	/**
	 * Normalise an OR object into a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The object as an array.
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}//end toArray()
}//end class
