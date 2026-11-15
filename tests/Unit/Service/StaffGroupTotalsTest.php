<?php

/**
 * Unit tests for StaffGroupTotals.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\Pipelinq\Service\PosTransactionTable;
use OCA\Pipelinq\Service\StaffGroupTotals;
use PHPUnit\Framework\TestCase;

/**
 * StaffGroupTotals aggregates POS transactions per staff member.
 */
class StaffGroupTotalsTest extends TestCase {

	/**
	 * A runner that records its query and answers the given groups.
	 *
	 * @param array<string, mixed> $result The aggregation result.
	 *
	 * @return object
	 */
	private static function runner(array $result): object {
		return new class($result) {
			/**
			 * The calls, as [register, schema, query].
			 *
			 * @var list<array{0: string, 1: string, 2: AggregationQuery}>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $result The answer.
			 */
			public function __construct(private array $result) {
			}

			/**
			 * Run a query.
			 *
			 * @param string           $registerRef The register.
			 * @param string           $schemaRef   The schema.
			 * @param AggregationQuery $query       The query.
			 *
			 * @return array<string, mixed>
			 */
			public function runAdhocByRef(string $registerRef, string $schemaRef, AggregationQuery $query): array {
				$this->calls[] = [$registerRef, $schemaRef, $query];
				return $this->result;
			}
		};
	}//end runner()

	/**
	 * Values come keyed by staff member; groups without a key are dropped and a missing value is zero.
	 *
	 * @return void
	 */
	public function testValuesAreKeyedByStaffMember(): void {
		$runner = self::runner(['groups' => [['key' => 'anna', 'value' => 120.5], ['key' => 42, 'value' => 3], ['value' => 99], ['key' => 'bram']]]);
		$totals = new StaffGroupTotals(runner: $runner, table: new PosTransactionTable(register: 'reg-1', schema: 'pos-tx'));

		self::assertSame(['anna' => 120.5, '42' => 3, 'bram' => 0], $totals->valuesFor(sumField: 'total', filter: ['status' => 'completed']));
		self::assertSame('reg-1', $runner->calls[0][0]);
		self::assertSame('pos-tx', $runner->calls[0][1]);
		self::assertTrue($runner->calls[0][2]->isGrouped());
		self::assertSame('staffMemberId', $runner->calls[0][2]->getGroupByField());
	}//end testValuesAreKeyedByStaffMember()

	/**
	 * Without groups there are no values.
	 *
	 * @return void
	 */
	public function testNoGroupsGiveNoValues(): void {
		$totals = new StaffGroupTotals(runner: self::runner(['value' => 0]), table: new PosTransactionTable(register: 'r', schema: 's'));

		self::assertSame([], $totals->valuesFor(sumField: null, filter: []));
	}//end testNoGroupsGiveNoValues()
}//end class
