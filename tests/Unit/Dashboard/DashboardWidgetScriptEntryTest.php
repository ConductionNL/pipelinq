<?php

/**
 * Unit tests for the script bundle each pipelinq dashboard widget loads.
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

use OCA\Pipelinq\Dashboard\AbstractPipelinqWidget;
use OCA\Pipelinq\Dashboard\CreateLeadWidget;
use OCA\Pipelinq\Dashboard\DealsOverviewWidget;
use OCA\Pipelinq\Dashboard\FindClientWidget;
use OCA\Pipelinq\Dashboard\MyLeadsWidget;
use OCA\Pipelinq\Dashboard\RecentActivitiesWidget;
use OCA\Pipelinq\Dashboard\StartRequestWidget;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Each widget names a script entry that webpack builds and that has a source file.
 *
 * @covers \OCA\Pipelinq\Dashboard\CreateLeadWidget
 * @covers \OCA\Pipelinq\Dashboard\DealsOverviewWidget
 * @covers \OCA\Pipelinq\Dashboard\FindClientWidget
 * @covers \OCA\Pipelinq\Dashboard\MyLeadsWidget
 * @covers \OCA\Pipelinq\Dashboard\RecentActivitiesWidget
 * @covers \OCA\Pipelinq\Dashboard\StartRequestWidget
 */
class DashboardWidgetScriptEntryTest extends TestCase {

	/**
	 * Each widget class with the script entry it loads.
	 *
	 * @return array<string, array{class-string<AbstractPipelinqWidget>, string}>
	 */
	public static function widgets(): array {
		return [
			'create lead'       => [CreateLeadWidget::class, 'createLeadWidget'],
			'deals overview'    => [DealsOverviewWidget::class, 'dealsOverviewWidget'],
			'find client'       => [FindClientWidget::class, 'findClientWidget'],
			'my leads'          => [MyLeadsWidget::class, 'myLeadsWidget'],
			'recent activities' => [RecentActivitiesWidget::class, 'recentActivitiesWidget'],
			'start request'     => [StartRequestWidget::class, 'startRequestWidget'],
		];
	}//end widgets()

	/**
	 * The widget's entry is the expected one, has a source file and is a webpack output.
	 *
	 * @param string $class The widget class.
	 * @param string $entry The expected script entry.
	 *
	 * @return void
	 */
	#[DataProvider('widgets')]
	public function testScriptEntryIsABuiltBundle(string $class, string $entry): void {
		$widget = new $class($this->createMock(IL10N::class));
		$method = new ReflectionMethod($widget, 'scriptEntry');

		$this->assertSame($entry, $method->invoke($widget));

		$root = dirname(__DIR__, 3);
		$this->assertFileExists($root . '/src/' . $entry . '.js');
		$this->assertStringContainsString(
			"filename: appId + '-" . $entry . ".js'",
			(string)file_get_contents($root . '/webpack.config.js')
		);
	}//end testScriptEntryIsABuiltBundle()

	/**
	 * No two widgets share a script entry.
	 *
	 * @return void
	 */
	public function testScriptEntriesAreDistinct(): void {
		$entries = [];
		foreach (self::widgets() as [$class]) {
			$widget    = new $class($this->createMock(IL10N::class));
			$entries[] = (new ReflectionMethod($widget, 'scriptEntry'))->invoke($widget);
		}

		$this->assertSame($entries, array_values(array_unique($entries)));
		$this->assertCount(6, $entries);
	}//end testScriptEntriesAreDistinct()
}//end class
