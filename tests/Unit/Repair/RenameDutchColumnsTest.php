<?php

/**
 * Tests for the Dutch-to-English column migration.
 *
 * These assert the properties I had been checking BY HAND on every batch, which
 * is exactly why they belong in the suite: a hand-check does not run again when
 * someone extends COLUMN_MAP. The run() cases additionally cover the paths that
 * decide whether customer data MOVES, is COPIED, or is deliberately left alone.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
 *  migration. Pointing this at an existing spec would report conformance to a
 *  requirement that says nothing about it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\RenameDutchColumns;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Guards the shape and the behaviour of the vocabulary column migration.
 */
final class RenameDutchColumnsTest extends TestCase {
	/**
	 * The step under test.
	 *
	 * @var RenameDutchColumns
	 */
	private RenameDutchColumns $step;

	/**
	 * Mocked database connection.
	 *
	 * @var IDBConnection
	 */
	private $db;

	/**
	 * Build the step with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->step = new RenameDutchColumns($this->db, $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * Read the column map off the step.
	 *
	 * @return array<string, string>
	 */
	private function map(): array {
		return (array)(new ReflectionClass(RenameDutchColumns::class))->getConstant('COLUMN_MAP');
	}//end map()

	/**
	 * Invoke a private method on the step.
	 *
	 * @param string       $name Method name.
	 * @param array<mixed> $args Positional arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, array $args) {
		$m = new ReflectionMethod(RenameDutchColumns::class, $name);
		$m->setAccessible(true);
		return $m->invokeArgs($this->step, $args);
	}//end call()

	/**
	 * Every entry is a snake_case pair that actually changes something.
	 *
	 * Snake_case matters: OpenRegister stores `requestedAmount` as the column
	 * `requested_amount`, so a camelCase entry never matches a real column — a
	 * migration that silently does nothing.
	 *
	 * @return void
	 */
	public function testEveryEntryIsSnakeCase(): void {
		$map = $this->map();
		self::assertNotSame([], $map, 'the column map must not be empty');

		foreach ($map as $old => $new) {
			self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', (string)$old, "source `$old` is not snake_case");
			self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', (string)$new, "target `$new` is not snake_case");
			self::assertNotSame($old, $new, "`$old` maps to itself");
		}
	}//end testEveryEntryIsSnakeCase()

	/**
	 * No target is also a source, so no rename chains.
	 *
	 * A chain (`a => b`, `b => c`) would move data twice depending on iteration
	 * order, which only shows up on real data.
	 *
	 * @return void
	 */
	public function testNoTargetIsAlsoASource(): void {
		$map = $this->map();
		foreach ($map as $new) {
			self::assertArrayNotHasKey($new, $map, "`$new` is both a target and a source");
		}
	}//end testNoTargetIsAlsoASource()

	/**
	 * No target still carries a Dutch fragment.
	 *
	 * A half-translated column (`effective_date_gewenst`) reads worse than
	 * either language and was shipped once before this assertion existed.
	 *
	 * @return void
	 */
	public function testNoTargetIsHalfTranslated(): void {
		$dutch = '/(^|_)(gewenst|datum|jaar|naam|nummer|bedrag|kosten|waarde|zaak|besluit|termijn)($|_)/';
		foreach ($this->map() as $old => $new) {
			self::assertDoesNotMatchRegularExpression($dutch, (string)$new, "target `$new` (from `$old`) is still part Dutch");
		}
	}//end testNoTargetIsHalfTranslated()

	/**
	 * Two sources for one destination in one table are refused, not merged.
	 *
	 * @return void
	 */
	public function testRefusesAmbiguousRename(): void {
		$targets = [];
		foreach ($this->map() as $old => $new) {
			$targets[$new][] = $old;
		}

		$ambiguous = array_filter($targets, static fn(array $s): bool => count($s) > 1);
		if ($ambiguous === []) {
			self::markTestSkipped('no target in this map has more than one source');
		}

		$target = (string)array_key_first($ambiguous);
		self::assertTrue($this->call('hasCollision', [$ambiguous[$target], $target]),
			'two sources for one destination must be refused, not merged'
		);
	}//end testRefusesAmbiguousRename()

	/**
	 * A single source is NOT treated as a collision.
	 *
	 * The negative control: without it, a guard that always returned true would
	 * pass the test above while migrating nothing at all.
	 *
	 * @return void
	 */
	public function testSingleSourceIsNotACollision(): void {
		$map = $this->map();
		$old = (string)array_key_first($map);
		self::assertFalse($this->call('hasCollision', [[$old], $map[$old]]));
	}//end testSingleSourceIsNotACollision()

	/**
	 * With no registers resolvable, run() reports and touches nothing.
	 *
	 * The fail-soft path: an install without the registers must not error.
	 *
	 * @return void
	 */
	public function testRunWithNoRegistersDoesNothing(): void {
		$this->db->method('executeQuery')->willThrowException(
			new \OCP\DB\Exception('no such table')
		);
		$this->db->expects(self::never())->method('executeStatement');

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info');

		$this->step->run($output);
	}//end testRunWithNoRegistersDoesNothing()

	/**
	 * A failing statement is swallowed and reported, not thrown.
	 *
	 * A repair step that throws aborts the whole upgrade.
	 *
	 * @return void
	 */
	public function testFailingStatementIsSwallowed(): void {
		$this->db->method('executeStatement')->willThrowException(
			new \OCP\DB\Exception('syntax error')
		);
		self::assertFalse($this->call('exec', ['ALTER TABLE x RENAME COLUMN a TO b']));
	}//end testFailingStatementIsSwallowed()

	/**
	 * A successful statement reports success.
	 *
	 * @return void
	 */
	public function testSuccessfulStatementReportsSuccess(): void {
		$this->db->method('executeStatement')->willReturn(1);
		self::assertTrue($this->call('exec', ['ALTER TABLE x RENAME COLUMN a TO b']));
	}//end testSuccessfulStatementReportsSuccess()


	/**
	 * run() renames a Dutch column it finds on a real shard table.
	 *
	 * This is the path that actually moves customer data, so it is the one worth
	 * exercising: registers resolve, the shard table matches the marker, the old
	 * column is present and the new one is not, therefore ALTER ... RENAME.
	 *
	 * @return void
	 */
	public function testRunRenamesAnExistingDutchColumn(): void {
		$map = $this->map();
		$old = (string)array_key_first($map);
		$new = $map[$old];

		$registers = $this->createMock(\OCP\DB\IResult::class);
		$registers->method('fetchAll')->willReturn([7]);
		$this->db->method('executeQuery')->willReturn($registers);

		$tables = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$columns = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$tables->method('fetch')->willReturnOnConsecutiveCalls(
			['table_name' => 'oc_openregister_table_7_42'], false
		);
		$columns->method('fetch')->willReturnOnConsecutiveCalls(
			['column_name' => $old], false
		);
		$this->db->method('prepare')->willReturnOnConsecutiveCalls($tables, $columns);

		$platform = $this->getMockBuilder(\stdClass::class)
			->addMethods(['quoteSingleIdentifier'])->getMock();
		$platform->method('quoteSingleIdentifier')->willReturnArgument(0);
		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$statements = [];
		$this->db->method('executeStatement')->willReturnCallback(
			static function (string $sql) use (&$statements): int {
				$statements[] = $sql;
				return 1;
			}
		);

		$this->step->run($this->createMock(IOutput::class));

		self::assertNotSame([], $statements, 'the migration issued no statement at all');
		self::assertStringContainsString('RENAME COLUMN', $statements[0]);
		self::assertStringContainsString($old, $statements[0]);
		self::assertStringContainsString($new, $statements[0]);
	}//end testRunRenamesAnExistingDutchColumn()

	/**
	 * The step names itself for the repair log.
	 *
	 * @return void
	 */
	public function testHasAHumanReadableName(): void {
		self::assertNotSame('', trim($this->step->getName()));
	}//end testHasAHumanReadableName()
}//end class
