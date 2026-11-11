<?php

/**
 * Pipelinq StaffGroupTotals.
 *
 * Grouped per-staff aggregations over the POS transactions.
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
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;

/**
 * Runs one grouped aggregation (count or sum) over the POS transactions and
 * flattens its groups into a `staffMemberId => value` map. Built by
 * PosStaffReportService for one report.
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10.2
 */
final class StaffGroupTotals {
	/**
	 * Constructor.
	 *
	 * @param object              $runner The OpenRegister aggregation runner.
	 * @param PosTransactionTable $table  The register and schema of the transactions.
	 */
	public function __construct(
		private object $runner,
		private PosTransactionTable $table,
	) {
	}//end __construct()

	/**
	 * Run one aggregation grouped by staffMemberId: a count when no sum field is given.
	 *
	 * @param string|null          $sumField The field to sum, or null to count.
	 * @param array<string, mixed> $filter   The filter map.
	 *
	 * @return array<string, float|int> Map of staffMemberId to the aggregated value; groups without a key are left out.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AggregationQuery::create() is
	 *  OpenRegister's documented static query-builder factory; there is no
	 *  instance to inject.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10.2
	 */
	public function valuesFor(?string $sumField, array $filter): array {
		$metric = 'sum';
		if ($sumField === null) {
			$metric = 'count';
		}

		$query = AggregationQuery::create(
			metric: $metric,
			field: $sumField,
			filter: $filter,
			groupBy: ['field' => 'staffMemberId'],
		);
		$result = $this->runner->runAdhocByRef(registerRef: $this->table->register, schemaRef: $this->table->schema, query: $query);
		return $this->valuesByKey(groups: ($result['groups'] ?? []));
	}//end valuesFor()

	/**
	 * The groups as a `key => value` map, leaving out groups without a key.
	 *
	 * @param iterable<array<string, mixed>> $groups The result groups.
	 *
	 * @return array<string, float|int> The map.
	 */
	private function valuesByKey(iterable $groups): array {
		$map = [];
		foreach ($groups as $group) {
			$key = $group['key'] ?? null;
			if ($key === null) {
				continue;
			}

			$map[(string)$key] = ($group['value'] ?? 0);
		}

		return $map;
	}//end valuesByKey()
}//end class
