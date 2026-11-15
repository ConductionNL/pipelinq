<?php

/**
 * Unit tests for SchemaEvolutionService.
 *
 * Verifies the column-drift detection used for the per-run schema snapshot:
 * added columns, removed columns, type changes, no-change, comparison against a
 * stored snapshot record (including the null first-run case) and deriving a
 * column => type map from representative rows.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\Pipelinq\Service\Export\SchemaEvolutionService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SchemaEvolutionService.
 */
class SchemaEvolutionServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var SchemaEvolutionService
	 */
	private SchemaEvolutionService $service;

	/**
	 * Instantiate the dependency-free service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new SchemaEvolutionService();
	}//end setUp()

	/**
	 * An added column is reported as "added: <col>".
	 *
	 * @return void
	 */
	public function testDetectsAddedColumn(): void {
		$changes = $this->service->compareColumns(
			current: ['id' => 'integer', 'name' => 'string', 'email' => 'string'],
			previous: ['id' => 'integer', 'name' => 'string']
		);

		$this->assertContains('added: email', $changes);
	}//end testDetectsAddedColumn()

	/**
	 * A removed column is reported as "removed: <col>".
	 *
	 * @return void
	 */
	public function testDetectsRemovedColumn(): void {
		$changes = $this->service->compareColumns(
			current: ['id' => 'integer'],
			previous: ['id' => 'integer', 'legacy' => 'string']
		);

		$this->assertContains('removed: legacy', $changes);
	}//end testDetectsRemovedColumn()

	/**
	 * A type change is reported as "changed: <col> (a -> b)".
	 *
	 * @return void
	 */
	public function testDetectsTypeChange(): void {
		$changes = $this->service->compareColumns(
			current: ['amount' => 'decimal'],
			previous: ['amount' => 'integer']
		);

		$this->assertContains('changed: amount (integer -> decimal)', $changes);
	}//end testDetectsTypeChange()

	/**
	 * Identical column maps produce no changes.
	 *
	 * @return void
	 */
	public function testNoChangesWhenIdentical(): void {
		$columns = ['id' => 'integer', 'name' => 'string'];

		$this->assertSame([], $this->service->compareColumns(current: $columns, previous: $columns));
	}//end testNoChangesWhenIdentical()

	/**
	 * Comparing against a null previous snapshot (first run) yields no drift.
	 *
	 * @return void
	 */
	public function testCompareToSnapshotNullIsEmpty(): void {
		$this->assertSame(
			[],
			$this->service->compareToSnapshot(current: ['id' => 'integer'], previous: null)
		);
	}//end testCompareToSnapshotNullIsEmpty()

	/**
	 * Comparing against a stored snapshot record reads columnDefinitionsJson.
	 *
	 * @return void
	 */
	public function testCompareToSnapshotReadsStoredDefinitions(): void {
		$previous = ['columnDefinitionsJson' => ['id' => 'integer']];

		$changes = $this->service->compareToSnapshot(
			current: ['id' => 'integer', 'status' => 'string'],
			previous: $previous
		);

		$this->assertContains('added: status', $changes);
	}//end testCompareToSnapshotReadsStoredDefinitions()

	/**
	 * Column definitions are derived per column from representative rows.
	 *
	 * @return void
	 */
	public function testDeriveColumnDefinitions(): void {
		$defs = $this->service->deriveColumnDefinitions(rows: [['id' => 1, 'name' => 'Alice']]);

		$this->assertArrayHasKey('id', $defs);
		$this->assertArrayHasKey('name', $defs);
	}//end testDeriveColumnDefinitions()
	/**
	 * Every column-type merge: the first type stands; a null later changes
	 * nothing; a typed value after a null takes over; two different types
	 * become string; keys are read as strings; every value type is named.
	 *
	 * @return void
	 */
	public function testEveryColumnTypeMerge(): void {
		$rows = [
			['a' => null, 'b' => 1, 'c' => 1, 'd' => 'x', 'e' => true, 0 => 1.5, 'f' => [1]],
			['a' => 2, 'b' => null, 'c' => 1.5, 'd' => 'y', 'e' => false, 0 => 2.5, 'f' => null, 'g' => null],
			['a' => 'x', 'b' => 3, 'c' => null, 'd' => null, 'e' => 'no', 'g' => null],
		];

		$this->assertSame(
			['a' => 'string', 'b' => 'integer', 'c' => 'string', 'd' => 'string', 'e' => 'string', '0' => 'double', 'f' => 'object', 'g' => 'null'],
			$this->service->deriveColumnDefinitions(rows: $rows)
		);
		$this->assertSame(['a' => 'integer'], $this->service->deriveColumnDefinitions(rows: [['a' => null], ['a' => 1], ['a' => null]]));
		$this->assertSame([], $this->service->deriveColumnDefinitions(rows: []));
	}//end testEveryColumnTypeMerge()
}//end class
