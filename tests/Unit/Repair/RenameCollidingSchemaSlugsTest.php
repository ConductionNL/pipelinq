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
			]
		);
		$statements = &$this->captureStatements();

		$this->step->run($this->createMock(IOutput::class));

		$this->assertCount(7, $statements, 'exactly seven rows may be rewritten');
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

}//end class
