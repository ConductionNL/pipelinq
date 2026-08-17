<?php

/**
 * Unit tests for RenameLoyaltyAccountSchemaSlug.
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

use OCA\Pipelinq\Repair\RenameLoyaltyAccountSchemaSlug;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Guards the loyalty-account schema slug migration.
 *
 * The step exists because OpenRegister matches a schema by (application, slug) and
 * CREATES a new one when that misses — so a slug rename in the shipped register
 * fragment orphans the old schema and every object on it, without erroring. These
 * tests pin the one case that must write and the two that must refuse.
 */
final class RenameLoyaltyAccountSchemaSlugTest extends TestCase {
	/**
	 * Mocked database connection.
	 *
	 * @var IDBConnection
	 */
	private $db;

	/**
	 * The step under test.
	 *
	 * @var RenameLoyaltyAccountSchemaSlug
	 */
	private RenameLoyaltyAccountSchemaSlug $step;

	/**
	 * Build the step with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->step = new RenameLoyaltyAccountSchemaSlug($this->db, $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * Queue the two slug lookups the step performs, in order.
	 *
	 * @param array<int, mixed> $old Ids returned for the Dutch slug.
	 * @param array<int, mixed> $new Ids returned for the English slug.
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
	 * The Dutch slug alone is renamed in place, keeping the schema id.
	 *
	 * Keeping the id is the whole point: the shard table is named after it, so a new
	 * schema would leave every existing loyalty account behind a slug nothing reads.
	 *
	 * @return void
	 */
	public function testRenamesTheDutchSlugInPlace(): void {
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
		$this->assertSame(['customerLoyaltyAccount', 495], $statements[0][1]);
	}//end testRenamesTheDutchSlugInPlace()

	/**
	 * An install already on the English slug is left alone.
	 *
	 * @return void
	 */
	public function testIsANoOpWhenTheDutchSlugIsAbsent(): void {
		$this->queueLookups(old: [], new: [495]);
		$this->db->expects($this->never())->method('executeStatement');

		$this->step->run($this->createMock(IOutput::class));
	}//end testIsANoOpWhenTheDutchSlugIsAbsent()

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
	 * Duplicate Dutch slugs are a refusal too — the step must not guess.
	 *
	 * @return void
	 */
	public function testRefusesWhenTheDutchSlugIsDuplicated(): void {
		$this->queueLookups(old: [495, 496], new: []);
		$this->db->expects($this->never())->method('executeStatement');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step->run($output);
	}//end testRefusesWhenTheDutchSlugIsDuplicated()
}//end class
