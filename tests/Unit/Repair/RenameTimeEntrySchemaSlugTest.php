<?php

/**
 * Unit tests for RenameTimeEntrySchemaSlug.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
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

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\RenameTimeEntrySchemaSlug;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Guards the time-entry schema slug migration.
 *
 * The step exists because OpenRegister matches a schema by (application, slug) and
 * CREATES a new one when that misses — so a slug rename in the shipped register
 * fragment orphans the old schema and every object on it, without erroring. These
 * tests pin the one case that must write and the two that must refuse.
 */
final class RenameTimeEntrySchemaSlugTest extends TestCase {
	/**
	 * Mocked database connection.
	 *
	 * @var IDBConnection
	 */
	private $db;

	/**
	 * The step under test.
	 *
	 * @var RenameTimeEntrySchemaSlug
	 */
	private RenameTimeEntrySchemaSlug $step;

	/**
	 * Build the step with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->step = new RenameTimeEntrySchemaSlug($this->db, $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * Queue the two slug lookups the step performs, in order.
	 *
	 * @param array<int, mixed> $old Ids returned for the old slug.
	 * @param array<int, mixed> $new Ids returned for the namespaced slug.
	 *
	 * @return void
	 */
	private function queueLookups(array $old, array $new): void {
		$oldResult = $this->createMock(IResult::class);
		$oldResult->method('fetchAll')->willReturn($old);
		$newResult = $this->createMock(IResult::class);
		$newResult->method('fetchAll')->willReturn($new);
		$this->db->method('executeQuery')->willReturnOnConsecutiveCalls($oldResult, $newResult);
	}//end queueLookups()

	/**
	 * The old slug alone is renamed in place, keeping the schema id.
	 *
	 * Keeping the id is the whole point: the shard table is named after it, so a new
	 * schema would leave every existing billing line behind a slug nothing reads.
	 *
	 * @return void
	 */
	public function testRenamesTheOldSlugInPlace(): void {
		$this->queueLookups(old: [495], new: []);

		$statements = [];
		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []) use (&$statements): int {
				$statements[] = [$sql, $params];
				return 1;
			}
		);

		$this->step->run($this->createMock(IOutput::class));

		$this->assertCount(1, $statements, 'exactly one row may be rewritten');
		$this->assertStringContainsString('openregister_schemas', $statements[0][0]);
		$this->assertStringContainsString('SET slug', $statements[0][0]);
		$this->assertSame(['billingTimeEntry', 495], $statements[0][1]);
	}//end testRenamesTheOldSlugInPlace()

	/**
	 * An install already on the namespaced slug is left alone.
	 *
	 * @return void
	 */
	public function testIsANoOpWhenTheOldSlugIsAbsent(): void {
		$this->queueLookups(old: [], new: [495]);
		$this->db->expects($this->never())->method('executeStatement');

		$this->step->run($this->createMock(IOutput::class));
	}//end testIsANoOpWhenTheOldSlugIsAbsent()

	/**
	 * Both slugs present is a refusal, not a merge.
	 *
	 * Each schema may own objects. Renaming one onto the other would decide which set
	 * to abandon, so the step declines and says so rather than picking silently.
	 *
	 * @return void
	 */
	public function testRefusesWhenBothSlugsExist(): void {
		$this->queueLookups(old: [495], new: [512]);
		$this->db->expects($this->never())->method('executeStatement');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step->run($output);
	}//end testRefusesWhenBothSlugsExist()

	/**
	 * Duplicate old slugs are a refusal too — the step must not guess.
	 *
	 * @return void
	 */
	public function testRefusesWhenTheOldSlugIsDuplicated(): void {
		$this->queueLookups(old: [495, 496], new: []);
		$this->db->expects($this->never())->method('executeStatement');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step->run($output);
	}//end testRefusesWhenTheOldSlugIsDuplicated()

	/**
	 * Golden: every outcome reports exactly the same output and log lines, in order.
	 *
	 * @return void
	 */
	public function testEveryOutcomeReportsExactly(): void {
		$cases = [
			'table unreadable' => [['timeEntry' => 'throw'], false, [
				[
					'log.warning',
					'RenameTimeEntrySchemaSlug: could not read the schema table; skipping.',
					[
						'exception' => 'table gone',
					],
				],
				[
					'output.info',
					'RenameTimeEntrySchemaSlug: schema table unreadable; leaving the slug alone.',
				],
			]],
			'old slug absent' => [['billingTimeEntry' => [7]], false, [
				[
					'output.info',
					'RenameTimeEntrySchemaSlug: no time-entry schema on this install; nothing to do.',
				],
			]],
			'both slugs present' => [['timeEntry' => [4], 'billingTimeEntry' => [5]], false, [
				[
					'log.warning',
					'RenameTimeEntrySchemaSlug: both slugs exist; refusing to merge them.',
					[
						'old' => [
							4,
						],
						'new' => [
							5,
						],
					],
				],
				[
					'output.warning',
					'RenameTimeEntrySchemaSlug: both `timeEntry` and `billingTimeEntry` exist; refusing to merge them. Resolve by hand.',
				],
			]],
			'old slug duplicated' => [['timeEntry' => [4, 6]], false, [
				[
					'log.warning',
					'RenameTimeEntrySchemaSlug: duplicate time-entry slugs; refusing to guess.',
					[
						'ids' => [
							4,
							6,
						],
					],
				],
				[
					'output.warning',
					'RenameTimeEntrySchemaSlug: duplicate `timeEntry` schemas; refusing to guess.',
				],
			]],
			'rename fails' => [['timeEntry' => [4]], true, [
				[
					'statement',
					[
						'billingTimeEntry',
						4,
					],
				],
				[
					'log.error',
					'RenameTimeEntrySchemaSlug: slug rename failed; the import will create a second schema.',
					[
						'id' => 4,
						'exception' => 'locked',
					],
				],
				[
					'output.warning',
					'RenameTimeEntrySchemaSlug: slug rename failed; see the log.',
				],
			]],
			'rename succeeds' => [['timeEntry' => [4]], false, [
				[
					'statement',
					[
						'billingTimeEntry',
						4,
					],
				],
				[
					'output.info',
					'RenameTimeEntrySchemaSlug: schema 4 renamed `timeEntry` -> `billingTimeEntry`; its objects stay attached.',
				],
			]],
		];
		foreach ($cases as $name => [$ids, $failUpdate, $expected]) {
			$this->assertSame($expected, $this->recordOutcome($ids, $failUpdate), $name);
		}
	}//end testEveryOutcomeReportsExactly()

	/**
	 * Run the step against canned slug lookups and record what it reports.
	 *
	 * @param array<string, mixed> $ids        Ids per slug, or 'throw' to make that lookup fail.
	 * @param bool                 $failUpdate Whether the rename statement fails.
	 *
	 * @return array<int, array<int, mixed>> The statements, log and output calls, in order.
	 */
	private function recordOutcome(array $ids, bool $failUpdate): array {
		$calls = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params) use ($ids): IResult {
				$slugIds = ($ids[$params[0]] ?? []);
				if ($slugIds === 'throw') {
					throw new DbException('table gone');
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($slugIds);
				return $result;
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params) use ($failUpdate, &$calls): int {
				$calls[] = ['statement', $params];
				if ($failUpdate === true) {
					throw new DbException('locked');
				}

				return 1;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level, &$calls): void {
					$calls[] = ['log.'.$level, $message, $context];
				}
			);
		}

		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level, &$calls): void {
					$calls[] = ['output.'.$level, $message];
				}
			);
		}

		(new RenameTimeEntrySchemaSlug($db, $logger))->run($output);
		return $calls;
	}//end recordOutcome()

}//end class
