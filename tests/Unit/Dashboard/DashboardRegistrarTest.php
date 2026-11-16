<?php

/**
 * Unit test for DashboardRegistrar: which widgets pipelinq puts on the Nextcloud dashboard.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Dashboard;

use OCA\Pipelinq\Dashboard\CreateLeadWidget;
use OCA\Pipelinq\Dashboard\DashboardRegistrar;
use OCA\Pipelinq\Dashboard\DealsOverviewWidget;
use OCA\Pipelinq\Dashboard\FindClientWidget;
use OCA\Pipelinq\Dashboard\MyLeadsWidget;
use OCA\Pipelinq\Dashboard\RecentActivitiesWidget;
use OCA\Pipelinq\Dashboard\StartRequestWidget;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DashboardRegistrar.
 *
 * @covers \OCA\Pipelinq\Dashboard\DashboardRegistrar
 */
class DashboardRegistrarTest extends TestCase {

	/**
	 * All six widgets are registered, each exactly once, in the declared order,
	 * and nothing else is registered on the context.
	 *
	 * @return void
	 */
	public function testRegistersTheSixWidgetsInOrder(): void {
		$registered = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->expects($this->exactly(6))->method('registerDashboardWidget')->willReturnCallback(
			function (string $widget) use (&$registered): void {
				$registered[] = $widget;
			}
		);
		$context->expects($this->never())->method('registerService');
		$context->expects($this->never())->method('registerServiceAlias');
		$context->expects($this->never())->method('registerEventListener');

		(new DashboardRegistrar())->register($context);

		$this->assertSame(
			[
				DealsOverviewWidget::class,
				MyLeadsWidget::class,
				RecentActivitiesWidget::class,
				FindClientWidget::class,
				StartRequestWidget::class,
				CreateLeadWidget::class,
			],
			$registered
		);
	}//end testRegistersTheSixWidgetsInOrder()

	/**
	 * Every registered widget class exists and is unique, so the dashboard never
	 * receives a duplicate or a dangling class name.
	 *
	 * @return void
	 */
	public function testEveryRegisteredWidgetIsAnExistingDistinctClass(): void {
		$registered = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->method('registerDashboardWidget')->willReturnCallback(
			function (string $widget) use (&$registered): void {
				$registered[] = $widget;
			}
		);

		(new DashboardRegistrar())->register($context);

		$this->assertSame($registered, array_values(array_unique($registered)));
		foreach ($registered as $widget) {
			$this->assertTrue(class_exists($widget), $widget.' does not exist');
			$this->assertTrue(is_subclass_of($widget, \OCP\Dashboard\IWidget::class), $widget.' is not a widget');
		}
	}//end testEveryRegisteredWidgetIsAnExistingDistinctClass()
}//end class
