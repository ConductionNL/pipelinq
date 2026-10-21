<?php

/**
 * Unit tests for CampaignReportView.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
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

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\CampaignReportView;
use PHPUnit\Framework\TestCase;

/**
 * The campaign report as the report page's widgets read it.
 */
class CampaignReportViewTest extends TestCase {
	/**
	 * A report with two channels, two models and a lead.
	 *
	 * @return array<string, mixed>
	 */
	private function report(): array {
		return [
			'campaign' => ['defaultModel' => 'first'],
			'channels' => [['channel' => 'email', 'reach' => 300, 'opened' => 120, 'click' => 40, 'visit' => 30, 'submit' => 5], ['channel' => 'social', 'reach' => null]],
			'engagement' => ['click' => 40, 'submit' => 5],
			'totals' => ['leads' => 1, 'attributedValue' => 900.0],
			'models' => [
				'first' => ['byChannel' => ['email' => 900.0], 'total' => 900.0],
				'last' => ['byChannel' => ['social' => 300.0, 'email' => 600.0], 'total' => 900.0],
			],
			'leads' => [['leadId' => 'l-1', 'title' => '', 'basis' => 'won_lead', 'value' => 900]],
			'cost' => ['totalEur' => null],
		];
	}//end report()

	/**
	 * The tiles, channel rows and lead rows, and the campaign's own model by default.
	 *
	 * @return void
	 */
	public function testTheRowsFollowTheCampaignsOwnModel(): void {
		$rows = (new CampaignReportView())->rows(report: $this->report(), model: '');

		$this->assertSame(['clicks' => 40, 'submissions' => 5, 'leads' => 1, 'attributedValue' => 900.0, 'cost' => null], $rows['tiles']);
		$this->assertSame('first', $rows['model']);
		$this->assertSame([['channel' => 'email', 'value' => 900.0, 'sharePercent' => 100.0]], $rows['modelRows']);
		$this->assertNull($rows['channelRows'][1]['reach']);
		$this->assertSame(40, $rows['channelRows'][0]['clicks']);
		$this->assertSame([['leadId' => 'l-1', 'title' => 'l-1', 'basis' => 'won_lead', 'value' => 900.0]], $rows['leadRows']);
	}//end testTheRowsFollowTheCampaignsOwnModel()

	/**
	 * A chosen model splits by that model, largest share first.
	 *
	 * @return void
	 */
	public function testAChosenModelSplitsLargestFirst(): void {
		$rows = (new CampaignReportView())->rows(report: $this->report(), model: 'last');

		$this->assertSame(['email', 'social'], array_column($rows['modelRows'], 'channel'));
		$this->assertSame([66.7, 33.3], array_column($rows['modelRows'], 'sharePercent'));
	}//end testAChosenModelSplitsLargestFirst()
}//end class
