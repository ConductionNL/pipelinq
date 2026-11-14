<?php

/**
 * Unit tests for what each pipelinq dashboard widget loads onto the page.
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
use OCP\L10N\IFactory;
use OCP\Util;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;

if (class_exists(\OC::class) === false) {
	require_once __DIR__ . '/WidgetLoadRuntimeStub.php';
}

/**
 * Every widget loads the shared chunks, then its own entry, then the widget styles.
 *
 * @covers \OCA\Pipelinq\Dashboard\AbstractPipelinqWidget
 * @covers \OCA\Pipelinq\Dashboard\CreateLeadWidget
 * @covers \OCA\Pipelinq\Dashboard\DealsOverviewWidget
 * @covers \OCA\Pipelinq\Dashboard\FindClientWidget
 * @covers \OCA\Pipelinq\Dashboard\MyLeadsWidget
 * @covers \OCA\Pipelinq\Dashboard\RecentActivitiesWidget
 * @covers \OCA\Pipelinq\Dashboard\StartRequestWidget
 */
class DashboardWidgetLoadTest extends TestCase {

	/**
	 * The `OCP\Util` static state saved before each test, restored after it.
	 *
	 * @var array<string, mixed>
	 */
	private array $savedUtilState = [];

	/**
	 * The `OC::$server` value saved before each test.
	 *
	 * @var mixed
	 */
	private mixed $savedServer = null;

	/**
	 * The `OC_Util::$styles` value saved before each test.
	 *
	 * @var mixed
	 */
	private mixed $savedStyles = null;

	/**
	 * Start every test with empty script lists and a container that answers the
	 * language lookup `Util::addScript()` makes for an app's translations.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		foreach (['scripts', 'scriptDeps', 'scriptsInit'] as $name) {
			$property = new ReflectionProperty(Util::class, $name);
			$this->savedUtilState[$name] = $property->getValue();
			$property->setValue(null, []);
		}

		$this->savedServer = \OC::$server;
		$this->savedStyles = \OC_Util::$styles;
		\OC_Util::$styles  = [];

		if ($this->savedServer === null) {
			$factory = $this->createMock(IFactory::class);
			$factory->method('findLanguage')->with('pipelinq')->willReturn('nl');

			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->with(IFactory::class)->willReturn($factory);

			\OC::$server = $container;
		}
	}//end setUp()

	/**
	 * Put the saved runtime state back so no other test sees this one's scripts.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->savedUtilState as $name => $value) {
			(new ReflectionProperty(Util::class, $name))->setValue(null, $value);
		}

		\OC::$server      = $this->savedServer;
		\OC_Util::$styles = $this->savedStyles;
	}//end tearDown()

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
	 * The pipelinq scripts a load queued, without the translation entries.
	 *
	 * @return string[]
	 */
	private function queuedScripts(): array {
		$scripts = (new ReflectionProperty(Util::class, 'scripts'))->getValue();

		return array_values(
			array_filter(
				($scripts['pipelinq'] ?? []),
				static fn (string $path): bool => str_contains($path, '/js/')
			)
		);
	}//end queuedScripts()

	/**
	 * load() queues vendor, nc-vue and the widget's own entry in that order,
	 * with the app's translations, after core, plus the widget stylesheet.
	 *
	 * @param string $class The widget class.
	 * @param string $entry The widget's script entry.
	 *
	 * @return void
	 */
	#[DataProvider('widgets')]
	public function testLoadQueuesSharedChunksThenTheWidgetEntry(string $class, string $entry): void {
		$widget = new $class($this->createMock(IL10N::class));

		$widget->load();

		$this->assertSame(
			[
				'pipelinq/js/pipelinq-shared-vendor',
				'pipelinq/js/pipelinq-shared-nc-vue',
				'pipelinq/js/pipelinq-' . $entry,
			],
			$this->queuedScripts()
		);

		$scripts = (new ReflectionProperty(Util::class, 'scripts'))->getValue();
		$this->assertCount(3, array_filter($scripts['pipelinq'], static fn (string $path): bool => str_contains($path, '/l10n/')));

		$deps = (new ReflectionProperty(Util::class, 'scriptDeps'))->getValue();
		$this->assertArrayHasKey('pipelinq', $deps);
		$this->assertSame(['core'], array_values($deps['pipelinq']->getDeps()));

		$this->assertSame(['pipelinq/css/dashboardWidgets'], \OC_Util::$styles);
	}//end testLoadQueuesSharedChunksThenTheWidgetEntry()

	/**
	 * Two widgets on one page share the chunk entries and keep their own.
	 *
	 * @return void
	 */
	public function testTwoWidgetsQueueTheSharedChunksForEachAndBothEntries(): void {
		(new MyLeadsWidget($this->createMock(IL10N::class)))->load();
		(new DealsOverviewWidget($this->createMock(IL10N::class)))->load();

		$this->assertSame(
			[
				'pipelinq/js/pipelinq-shared-vendor',
				'pipelinq/js/pipelinq-shared-nc-vue',
				'pipelinq/js/pipelinq-myLeadsWidget',
				'pipelinq/js/pipelinq-shared-vendor',
				'pipelinq/js/pipelinq-shared-nc-vue',
				'pipelinq/js/pipelinq-dealsOverviewWidget',
			],
			$this->queuedScripts()
		);
		$this->assertSame(
			['pipelinq/css/dashboardWidgets', 'pipelinq/css/dashboardWidgets'],
			\OC_Util::$styles
		);
	}//end testTwoWidgetsQueueTheSharedChunksForEachAndBothEntries()
}//end class
