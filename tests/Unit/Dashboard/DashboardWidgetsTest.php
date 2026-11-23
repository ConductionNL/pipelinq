<?php

/**
 * Unit tests for the six pipelinq dashboard widgets: the IWidget metadata
 * every widget reports (id, translated title, order, icon class, header URL).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Dashboard
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-crm-dashboard-layout
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Dashboard;

use OCA\Pipelinq\Dashboard\CreateLeadWidget;
use OCA\Pipelinq\Dashboard\DealsOverviewWidget;
use OCA\Pipelinq\Dashboard\FindClientWidget;
use OCA\Pipelinq\Dashboard\MyLeadsWidget;
use OCA\Pipelinq\Dashboard\RecentActivitiesWidget;
use OCA\Pipelinq\Dashboard\StartRequestWidget;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the dashboard widgets' metadata.
 */
class DashboardWidgetsTest extends TestCase {

	/**
	 * Each widget class with its id, title source string and order.
	 *
	 * @return array<string, array{0: class-string<IWidget>, 1: string, 2: string, 3: int}>
	 */
	public static function widgets(): array {
		return [
			'create lead'       => [CreateLeadWidget::class, 'pipelinq_create_lead_widget', 'Create Lead', 15],
			'deals overview'    => [DealsOverviewWidget::class, 'pipelinq_deals_overview_widget', 'Deals overview', 10],
			'find client'       => [FindClientWidget::class, 'pipelinq_find_client_widget', 'Find Client', 13],
			'my leads'          => [MyLeadsWidget::class, 'pipelinq_my_leads_widget', 'My Leads', 11],
			'recent activities' => [RecentActivitiesWidget::class, 'pipelinq_recent_activities_widget', 'Recent Activities', 12],
			'start request'     => [StartRequestWidget::class, 'pipelinq_start_request_widget', 'Start Request', 14],
		];
	}//end widgets()

	/**
	 * Every widget reports its own id, title and order and the shared icon and
	 * header URL.
	 *
	 * @param string $class The widget class.
	 * @param string $id    The expected id.
	 * @param string $title The expected title source string.
	 * @param int    $order The expected order.
	 *
	 * @return void
	 *
	 * @dataProvider widgets
	 */
	public function testWidgetMetadata(string $class, string $id, string $title, int $order): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->once())->method('t')->with($title)->willReturn('translated:'.$title);

		$widget = new $class($l10n);

		$this->assertInstanceOf(IWidget::class, $widget);
		$this->assertSame($id, $widget->getId());
		$this->assertSame('translated:'.$title, $widget->getTitle());
		$this->assertSame($order, $widget->getOrder());
		$this->assertSame('icon-pipelinq-widget', $widget->getIconClass());
		$this->assertNull($widget->getUrl());
	}//end testWidgetMetadata()
}//end class
