<?php

/**
 * Unit tests for DsarMigrationSummary.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
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

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\DsarMigrationSummary;
use PHPUnit\Framework\TestCase;

/**
 * DsarMigrationSummary writes the one line the AVG to DSAR repair step reports.
 */
class DsarMigrationSummaryTest extends TestCase {

	/**
	 * The line names every count and the total.
	 *
	 * @return void
	 */
	public function testTheLine(): void {
		$summary = new DsarMigrationSummary(counts: ['migrated' => 12, 'skipped' => 3, 'unmappable' => 1, 'failed' => 2], total: 18);

		self::assertSame(
			'Pipelinq AVG→DSAR migration: 12 migrated, 3 skipped (already migrated or trashed), 1 unmappable, 2 failed (of 18 source objects).',
			$summary->line()
		);
		self::assertSame(18, $summary->total);
	}//end testTheLine()

	/**
	 * An empty run reports zeroes.
	 *
	 * @return void
	 */
	public function testAnEmptyRun(): void {
		$summary = new DsarMigrationSummary(counts: ['migrated' => 0, 'skipped' => 0, 'unmappable' => 0, 'failed' => 0], total: 0);

		self::assertStringContainsString('0 migrated, 0 skipped', $summary->line());
		self::assertStringEndsWith('(of 0 source objects).', $summary->line());
	}//end testAnEmptyRun()
}//end class
