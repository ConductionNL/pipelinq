<?php

/**
 * Unit tests for ForecastSummaryService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Pipelinq\Service\ForecastExportService;
use OCA\Pipelinq\Service\ForecastSummaryService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The forecast page's figures, worked out on the server.
 */
class ForecastSummaryServiceTest extends TestCase {

	/**
	 * Build the service over doubles.
	 *
	 * @param array<int, array<string, mixed>> $snapshots What the export reads.
	 *
	 * @return ForecastSummaryService
	 */
	private function service(array $snapshots = []): ForecastSummaryService {
		$export = $this->createMock(ForecastExportService::class);
		$export->method('exportSnapshots')->willReturn(['snapshots' => $snapshots, 'total' => count($snapshots), 'limit' => 200, 'offset' => 0]);

		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnMap([['pieter', 'Pieter Jansen'], ['sales', null], ['ghost', null]]);

		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('Sales');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $id): ?IGroup => $id === 'sales' ? $group : null);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $vars = []): string => vsprintf($text, $vars));

		return new ForecastSummaryService(exportService: $export, userManager: $users, groupManager: $groups, l10n: $l10n);
	}//end service()

	/**
	 * The current quarter and the days left in it.
	 *
	 * @return void
	 */
	public function testTheCurrentQuarterAndTheDaysLeft(): void {
		$period = $this->service()->currentPeriod(now: new DateTimeImmutable('2026-10-05 10:00:00'));

		$this->assertSame(['periodId' => 'Q4-2026', 'quarter' => 4, 'year' => 2026, 'daysRemaining' => 87], $period);
	}//end testTheCurrentQuarterAndTheDaysLeft()

	/**
	 * The totals come from the latest snapshot per owner, with the browser's rules.
	 *
	 * @return void
	 */
	public function testTheTotalsReadTheLatestSnapshotPerOwner(): void {
		$snapshots = [
			['owner_id' => 'pieter', 'as_of_date' => '2026-09-28', 'commit' => 10, 'best_case' => 0, 'pipeline' => 0, 'closed_won' => 0, 'quota' => 100],
			['owner_id' => 'pieter', 'as_of_date' => '2026-10-05', 'commit' => 40, 'best_case' => 20, 'pipeline' => 60, 'closed_won' => 20, 'quota' => 100, 'commit_override_reason' => 'Dagblad deal', 'original_commit' => 30],
			['owner_id' => 'sales', 'as_of_date' => '2026-10-05', 'commit' => 0, 'best_case' => 0, 'pipeline' => 10, 'closed_won' => 0, 'quota' => 100],
		];
		$period = ['periodId' => 'Q4-2026', 'quarter' => 4, 'year' => 2026, 'daysRemaining' => 20];

		$result = $this->service()->summarize(snapshots: $snapshots, period: $period);

		$this->assertSame('Quarter 4 of 2026', $result['period']['label']);
		$this->assertSame(200.0, $result['summary']['quota']);
		$this->assertSame(70.0, $result['summary']['projected']);
		$this->assertSame(130.0, $result['summary']['gap']);
		$this->assertSame(65, $result['summary']['gapPercent']);
		$this->assertSame(60.0, $result['summary']['onTrack']);
		$this->assertSame(30, $result['summary']['onTrackPercent']);
		$this->assertTrue($result['summary']['atRisk']);
		$this->assertSame('This team is 65% below quota with 20 days to close. Action recommended.', $result['summary']['atRiskText']);
		$this->assertSame(['Pieter Jansen', 'Sales'], array_column($result['rows'], 'ownerName'));
		$this->assertTrue($result['rows'][0]['overridden']);
		$this->assertSame(['2026-09-28', '2026-10-05'], $result['trend']['labels']);
		$this->assertSame([10.0, 40.0], $result['trend']['commit']);
		$this->assertSame(30.0, $result['trend']['intoCommit']);
		$this->assertSame(0.0, $result['trend']['outOfCommit']);
	}//end testTheTotalsReadTheLatestSnapshotPerOwner()

	/**
	 * Not at risk with time left, and an owner nobody can name keeps its id.
	 *
	 * @return void
	 */
	public function testNotAtRiskWithTimeLeftAndAnUnknownOwnerKeepsItsId(): void {
		$result = $this->service()->summarize(
			snapshots: [['owner_id' => 'ghost', 'as_of_date' => '2026-10-05', 'commit' => 0, 'quota' => 100]],
			period: ['periodId' => 'Q4-2026', 'quarter' => 4, 'year' => 2026, 'daysRemaining' => 60]
		);

		$this->assertFalse($result['summary']['atRisk']);
		$this->assertSame('', $result['summary']['atRiskText']);
		$this->assertSame('ghost', $result['rows'][0]['ownerName']);
	}//end testNotAtRiskWithTimeLeftAndAnUnknownOwnerKeepsItsId()
}//end class
