<?php

/**
 * Unit tests for SchemaSlugTable.
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

use OCA\Pipelinq\Repair\Support\SchemaSlugTable;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * SchemaSlugTable reads and renames pipelinq's own schema rows.
 */
class SchemaSlugTableTest extends TestCase {

	/**
	 * The ids are read for the slug, scoped to the pipelinq application.
	 *
	 * @return void
	 */
	public function testIdsAreReadForTheSlugAndTheApp(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->with(\PDO::FETCH_COLUMN)->willReturn([4 => 17, 9 => 23]);
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('executeQuery')
			->with('SELECT id FROM `*PREFIX*openregister_schemas` WHERE slug = ? AND application = ?', ['klant', 'pipelinq'])
			->willReturn($result);

		self::assertSame([17, 23], (new SchemaSlugTable(db: $db))->idsWithSlug(slug: 'klant'));
	}//end testIdsAreReadForTheSlugAndTheApp()

	/**
	 * No rows give an empty list.
	 *
	 * @return void
	 */
	public function testNoRowsGiveAnEmptyList(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturn($result);

		self::assertSame([], (new SchemaSlugTable(db: $db))->idsWithSlug(slug: 'none'));
	}//end testNoRowsGiveAnEmptyList()

	/**
	 * Renaming updates exactly the one row by id.
	 *
	 * @return void
	 */
	public function testRenamingUpdatesTheRowById(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('executeStatement')
			->with('UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?', ['client', 17])
			->willReturn(1);

		(new SchemaSlugTable(db: $db))->renameSchema(id: 17, slug: 'client');
	}//end testRenamingUpdatesTheRowById()
}//end class
