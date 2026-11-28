<?php

/**
 * Unit tests for InPlaceSchemaSlugRename.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair\Support
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

namespace OCA\Pipelinq\Tests\Unit\Repair\Support;

use OCA\Pipelinq\Repair\Support\InPlaceSchemaSlugRename;
use OCP\DB\Exception;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * InPlaceSchemaSlugRename renames one schema's slug, or explains why it will not.
 */
class InPlaceSchemaSlugRenameTest extends TestCase {

	/**
	 * Info and warning lines written to the repair output.
	 *
	 * @var list<string>
	 */
	private array $lines = [];

	/**
	 * Slug renames executed, as [slug, id].
	 *
	 * @var list<array{0: string, 1: mixed}>
	 */
	private array $renames = [];

	/**
	 * A rename step over a schema table holding the given ids per slug.
	 *
	 * @param array<string, list<int>> $ids        Schema ids per slug.
	 * @param bool                     $readFails  Whether reading the table fails.
	 * @param bool                     $writeFails Whether the rename fails.
	 * @param LoggerInterface|null     $logger     The logger.
	 *
	 * @return InPlaceSchemaSlugRename
	 */
	private function step(array $ids, bool $readFails=false, bool $writeFails=false, ?LoggerInterface $logger=null): InPlaceSchemaSlugRename {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params) use ($ids, $readFails): IResult {
				if ($readFails === true) {
					throw new Exception('table missing');
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($ids[$params[0]] ?? []);
				return $result;
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params) use ($writeFails): int {
				if ($writeFails === true) {
					throw new Exception('locked');
				}

				$this->renames[] = $params;
				return 1;
			}
		);

		return new class($db, ($logger ?? $this->createMock(LoggerInterface::class))) extends InPlaceSchemaSlugRename {
			protected const OLD_SLUG = 'klant';
			protected const NEW_SLUG = 'client';
			protected const ABSENT_NOTICE = 'No klant schema; nothing to rename.';
			protected const DUPLICATE_NOTICE = 'Several klant schemas; not renaming.';
		};
	}//end step()

	/**
	 * Repair output that records its lines.
	 *
	 * @return IOutput
	 */
	private function repairOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->lines[] = 'info: ' . $line;
		});
		$output->method('warning')->willReturnCallback(function (string $line): void {
			$this->lines[] = 'warning: ' . $line;
		});

		return $output;
	}//end repairOutput()

	/**
	 * One old schema and no new one is renamed in place.
	 *
	 * @return void
	 */
	public function testTheOneOldSchemaIsRenamed(): void {
		$this->step(ids: ['klant' => [17]])->run(output: $this->repairOutput());

		self::assertSame([['client', 17]], $this->renames);
		self::assertCount(1, $this->lines);
		self::assertStringContainsString('schema 17 renamed `klant` -> `client`; its objects stay attached.', $this->lines[0]);
		self::assertStringStartsWith('info: ', $this->lines[0]);
	}//end testTheOneOldSchemaIsRenamed()

	/**
	 * Without an old schema the step says so and changes nothing.
	 *
	 * @return void
	 */
	public function testNoOldSchema(): void {
		$this->step(ids: [])->run(output: $this->repairOutput());

		self::assertSame([], $this->renames);
		self::assertSame(['info: No klant schema; nothing to rename.'], $this->lines);
	}//end testNoOldSchema()

	/**
	 * When both slugs exist it refuses to merge them.
	 *
	 * @return void
	 */
	public function testBothSlugsAreNotMerged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(self::stringContains('both slugs exist; refusing to merge them.'), ['old' => [17], 'new' => [30]]);

		$this->step(ids: ['klant' => [17], 'client' => [30]], logger: $logger)->run(output: $this->repairOutput());

		self::assertSame([], $this->renames);
		self::assertStringContainsString('both `klant` and `client` exist; refusing to merge them. Resolve by hand.', $this->lines[0]);
	}//end testBothSlugsAreNotMerged()

	/**
	 * Several old schemas are not guessed between.
	 *
	 * @return void
	 */
	public function testDuplicatesAreNotGuessed(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('Several klant schemas; not renaming.', ['ids' => [17, 18]]);

		$this->step(ids: ['klant' => [17, 18]], logger: $logger)->run(output: $this->repairOutput());

		self::assertSame([], $this->renames);
		self::assertStringContainsString('duplicate `klant` schemas; refusing to guess.', $this->lines[0]);
	}//end testDuplicatesAreNotGuessed()

	/**
	 * An unreadable schema table leaves the slug alone.
	 *
	 * @return void
	 */
	public function testAnUnreadableTable(): void {
		$this->step(ids: ['klant' => [17]], readFails: true)->run(output: $this->repairOutput());

		self::assertSame([], $this->renames);
		self::assertCount(1, $this->lines);
		self::assertStringContainsString('schema table unreadable; leaving the slug alone.', $this->lines[0]);
	}//end testAnUnreadableTable()

	/**
	 * A failed rename is logged and reported, not thrown.
	 *
	 * @return void
	 */
	public function testAFailedRenameIsReported(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(self::stringContains('slug rename failed; the import will create a second schema.'), ['id' => 17, 'exception' => 'locked']);

		$this->step(ids: ['klant' => [17]], writeFails: true, logger: $logger)->run(output: $this->repairOutput());

		self::assertCount(1, $this->lines);
		self::assertStringStartsWith('warning: ', $this->lines[0]);
		self::assertStringContainsString('slug rename failed; see the log.', $this->lines[0]);
	}//end testAFailedRenameIsReported()
}//end class
