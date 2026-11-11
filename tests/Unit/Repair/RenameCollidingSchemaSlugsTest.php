<?php

/**
 * Unit tests for RenameCollidingSchemaSlugs.
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

use OCA\Pipelinq\Repair\RenameCollidingSchemaSlugs;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Guards the two-slug namespacing pass.
 *
 * The step exists because OpenRegister matches a schema by (application, slug) and
 * CREATES a new one when that misses, so a slug rename in the shipped fragment
 * orphans the old schema and every object on it without erroring.
 *
 * Handling two slugs in one step adds a failure mode a single-slug step cannot
 * have: one refusal must not stop the other rename, and the two must not be
 * confused for one another. Both are pinned below.
 */
final class RenameCollidingSchemaSlugsTest extends TestCase {

	/**
	 * Mocked database connection.
	 *
	 * @var IDBConnection
	 */
	private $db;

	/**
	 * The step under test.
	 *
	 * @var RenameCollidingSchemaSlugs
	 */
	private RenameCollidingSchemaSlugs $step;

	/**
	 * Build the step with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->step = new RenameCollidingSchemaSlugs($this->db, $this->createMock(LoggerInterface::class));

	}//end setUp()

	/**
	 * Answer each slug lookup from a map, so the test does not depend on the order
	 * the step walks its renames in.
	 *
	 * @param array<string, array<int, mixed>> $bySlug Slug => ids present.
	 *
	 * @return void
	 */
	private function lookups(array $bySlug): void {
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use ($bySlug): IResult {
				$slug = (string)($params[0] ?? '');
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn(($bySlug[$slug] ?? []));
				return $result;
			}
		);

	}//end lookups()

	/**
	 * Capture the UPDATEs the step issues.
	 *
	 * @return array<int, array{0:string,1:array}> The statements, by reference.
	 */
	private function &captureStatements(): array {
		$statements = [];
		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []) use (&$statements): int {
				$statements[] = [$sql, $params];
				return 1;
			}
		);

		return $statements;

	}//end captureStatements()

	/**
	 * Both slugs are renamed in place, each keeping its schema id.
	 *
	 * Keeping the id is the whole point: the shard table is named after it, so a
	 * new schema would leave every existing row behind a slug nothing reads.
	 *
	 * @return void
	 */
	public function testRenamesEverySlugInPlace(): void {
		$this->lookups(
			[
				'cashCount' => [601],
				'conversation' => [602],
				'contract' => [603],
				'portalAccount' => [604],
				'portalSession' => [605],
				'resource' => [606],
				'message' => [607],
				'booking' => [608],
				'service' => [609],
				'task' => [610],
				'expense' => [611],
				'mergeOperation' => [612],
				'survey' => [613],
				'surveyInvitation' => [614],
			]
		);
		$statements = &$this->captureStatements();

		$this->step->run($this->createMock(IOutput::class));

		$this->assertCount(14, $statements, 'exactly fourteen rows may be rewritten');
		foreach ($statements as $statement) {
			$this->assertStringContainsString('openregister_schemas', $statement[0]);
			$this->assertStringContainsString('SET slug', $statement[0]);
		}

		$written = array_map(static fn (array $s): array => $s[1], $statements);
		$this->assertContains(['posCashCount', 601], $written);
		$this->assertContains(['channelConversation', 602], $written);
		$this->assertContains(['salesContract', 603], $written);
		$this->assertContains(['crmPortalAccount', 604], $written);
		$this->assertContains(['crmPortalSession', 605], $written);
		$this->assertContains(['appointmentResource', 606], $written);
		$this->assertContains(['channelMessage', 607], $written);
		$this->assertContains(['appointmentBooking', 608], $written);
		$this->assertContains(['appointmentService', 609], $written);
		$this->assertContains(['crmTask', 610], $written);
		$this->assertContains(['billableExpense', 611], $written);
		$this->assertContains(['masterMergeOperation', 612], $written);
		$this->assertContains(['satisfactionSurvey', 613], $written);
		$this->assertContains(['satisfactionSurveyInvitation', 614], $written);

	}//end testRenamesEverySlugInPlace()

	/**
	 * An install already namespaced is left alone.
	 *
	 * @return void
	 */
	public function testIsANoOpWhenTheOldSlugsAreAbsent(): void {
		$this->lookups(
			[
				'posCashCount' => [601],
				'channelConversation' => [602],
				'salesContract' => [603],
				'crmPortalAccount' => [604],
				'crmPortalSession' => [605],
				'appointmentResource' => [606],
				'channelMessage' => [607],
				'appointmentBooking' => [608],
				'appointmentService' => [609],
				'crmTask' => [610],
				'billableExpense' => [611],
				'masterMergeOperation' => [612],
			]
		);
		$this->db->expects($this->never())->method('executeStatement');

		$this->step->run($this->createMock(IOutput::class));

	}//end testIsANoOpWhenTheOldSlugsAreAbsent()

	/**
	 * Both slugs of a pair present is a refusal, not a merge. Each schema may own
	 * objects, and renaming one onto the other would decide which set to abandon.
	 *
	 * @return void
	 */
	public function testRefusesWhenBothSlugsOfAPairExist(): void {
		$this->lookups(['cashCount' => [601], 'posCashCount' => [700]]);
		$this->db->expects($this->never())->method('executeStatement');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step->run($output);

	}//end testRefusesWhenBothSlugsOfAPairExist()

	/**
	 * Duplicate old slugs are a refusal too. The step must not guess which row owns
	 * the objects.
	 *
	 * @return void
	 */
	public function testRefusesOnDuplicateOldSlugs(): void {
		$this->lookups(['cashCount' => [601, 602]]);
		$this->db->expects($this->never())->method('executeStatement');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step->run($output);

	}//end testRefusesOnDuplicateOldSlugs()

	/**
	 * A refusal on one slug must not stop the other rename.
	 *
	 * This is the failure mode a single-slug step cannot have. Without it, an
	 * instance that hand-resolved one collision would silently never get the
	 * other, and the second slug would keep answering for another app.
	 *
	 * @return void
	 */
	public function testARefusalOnOneSlugDoesNotStopTheOther(): void {
		// `cashCount` is ambiguous (both spellings present) and must be refused;
		// `conversation` is clean and must still be renamed.
		$this->lookups(
			[
				'cashCount' => [601],
				'posCashCount' => [700],
				'conversation' => [602],
			]
		);
		$statements = &$this->captureStatements();

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step->run($output);

		$this->assertCount(1, $statements, 'the clean slug is still renamed');
		$this->assertSame(['channelConversation', 602], $statements[0][1]);

	}//end testARefusalOnOneSlugDoesNotStopTheOther()

	/**
	 * Golden: every outcome reports exactly the same output and log lines, in order.
	 *
	 * @return void
	 */
	public function testEveryOutcomeReportsExactly(): void {
		$cases = [
			'table unreadable' => [['cashCount' => 'throw'], false, [
				[
					'log.warning',
					'RenameCollidingSchemaSlugs: could not read the schema table; skipping.',
					[
						'slug' => 'cashCount',
						'exception' => 'table gone',
					],
				],
				[
					'output.info',
					'RenameCollidingSchemaSlugs: schema table unreadable; leaving `cashCount` alone.',
				],
			]],
			'old slug absent' => [['posCashCount' => [7]], false, [
				[
					'output.info',
					'RenameCollidingSchemaSlugs: no `cashCount` on this install; nothing to do.',
				],
			]],
			'both slugs present' => [['cashCount' => [4], 'posCashCount' => [5]], false, [
				[
					'log.warning',
					'RenameCollidingSchemaSlugs: both slugs exist; refusing to merge them.',
					[
						'from' => 'cashCount',
						'to' => 'posCashCount',
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
					'RenameCollidingSchemaSlugs: both `cashCount` and `posCashCount` exist; refusing to merge them. Resolve by hand.',
				],
			]],
			'old slug duplicated' => [['cashCount' => [4, 6]], false, [
				[
					'log.warning',
					'RenameCollidingSchemaSlugs: duplicate slugs; refusing to guess.',
					[
						'from' => 'cashCount',
						'ids' => [
							4,
							6,
						],
					],
				],
				[
					'output.warning',
					'RenameCollidingSchemaSlugs: duplicate `cashCount` schemas; refusing to guess.',
				],
			]],
			'rename fails' => [['cashCount' => [4]], true, [
				[
					'statement',
					[
						'posCashCount',
						4,
					],
				],
				[
					'log.error',
					'RenameCollidingSchemaSlugs: slug rename failed; the import will create a second schema.',
					[
						'from' => 'cashCount',
						'id' => 4,
						'exception' => 'locked',
					],
				],
				[
					'output.warning',
					'RenameCollidingSchemaSlugs: renaming `cashCount` failed; see the log.',
				],
			]],
			'rename succeeds' => [['cashCount' => [4]], false, [
				[
					'statement',
					[
						'posCashCount',
						4,
					],
				],
				[
					'output.info',
					'RenameCollidingSchemaSlugs: schema 4 renamed `cashCount` -> `posCashCount` (collided with shillinq); its objects stay attached.',
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

		(new RenameCollidingSchemaSlugs($db, $logger))->run($output);
		$calls = array_values(array_filter($calls, static fn (array $call): bool => str_contains((string)json_encode($call), 'ashCount')));
		return $calls;
	}//end recordOutcome()

}//end class
