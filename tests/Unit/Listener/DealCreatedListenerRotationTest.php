<?php

/**
 * DealCreatedListener: assigning a new lead in turn.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Listener\DealCreatedListener;
use OCA\Pipelinq\Service\ForecastDealService;
use OCA\Pipelinq\Service\LeadRotationService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__.'/RecordingDeferralService.php';

/**
 * With rotation switched off nothing is assigned; switched on, a lead without
 * an assignee is assigned in the same save as the forecast default, and a lead
 * with an assignee keeps it.
 *
 * @covers \OCA\Pipelinq\Listener\DealCreatedListener
 */
class DealCreatedListenerRotationTest extends TestCase {

	/**
	 * The saveObject() calls.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The forecast service mock.
	 *
	 * @var ForecastDealService&MockObject
	 */
	private ForecastDealService&MockObject $deals;

	/**
	 * The rotation service mock.
	 *
	 * @var LeadRotationService&MockObject
	 */
	private LeadRotationService&MockObject $rotation;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->deals = $this->createMock(ForecastDealService::class);
		$this->rotation = $this->createMock(LeadRotationService::class);
	}//end setUp()

	/**
	 * The listener over a deal with this data.
	 *
	 * @param array<string, mixed> $data      The stored deal data.
	 * @param bool                 $rotation  Whether to wire the rotation service.
	 *
	 * @return DealCreatedListener
	 */
	private function listener(array $data, bool $rotation = true): DealCreatedListener {
		$entity = new ObjectEntity();
		$entity->setUuid('lead-1');
		$entity->setObject($data);

		$objects = new class($entity, $this) {
			/**
			 * Constructor.
			 *
			 * @param ObjectEntity                     $found The deal.
			 * @param DealCreatedListenerRotationTest $test  Where saves are recorded.
			 */
			public function __construct(private ObjectEntity $found, private DealCreatedListenerRotationTest $test) {
			}//end __construct()

			/**
			 * Answer a find.
			 *
			 * @param string $id       The uuid.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return ObjectEntity
			 */
			public function find(string $id, string $register, string $schema): ObjectEntity {
				return $this->found;
			}//end find()

			/**
			 * Record a save.
			 *
			 * @param array       $object   The data.
			 * @param array       $extend   The extend list.
			 * @param string      $register The register.
			 * @param string      $schema   The schema.
			 * @param string|null $uuid     The uuid.
			 *
			 * @return void
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, ?string $uuid): void {
				$this->test->recordSave(save: ['object' => $object, 'uuid' => $uuid]);
			}//end saveObject()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => 'reg-1', 'lead_schema' => 'lead-schema'][$key] ?? $default)
		);

		$rotationService = null;
		if ($rotation === true) {
			$rotationService = $this->rotation;
		}

		return new DealCreatedListener(
			schemaMapService: $this->createMock(SchemaMapService::class),
			dealService: $this->deals,
			container: $container,
			appConfig: $appConfig,
			deferral: new RecordingDeferralService(),
			logger: $this->createMock(LoggerInterface::class),
			rotation: $rotationService,
		);
	}//end listener()

	/**
	 * Record a save made by the object service double.
	 *
	 * @param array<string, mixed> $save The save.
	 *
	 * @return void
	 */
	public function recordSave(array $save): void {
		$this->saves[] = $save;
	}//end recordSave()

	/**
	 * Switched off, the lead is not assigned and nothing is saved when the
	 * category needs no default.
	 *
	 * @return void
	 */
	public function testSwitchedOffNothingIsAssigned(): void {
		$this->deals->method('applyDefaultCategory')->willReturn(null);
		$this->rotation->method('isEnabled')->willReturn(false);
		$this->rotation->expects($this->never())->method('assign');

		$this->listener(data: ['title' => 'Roof'])->runDeferredWork(entry: ['uuid' => 'lead-1']);

		$this->assertSame([], $this->saves);
	}//end testSwitchedOffNothingIsAssigned()

	/**
	 * Without the rotation service wired, the forecast default still saves.
	 *
	 * @return void
	 */
	public function testWithoutTheRotationServiceTheDefaultStillSaves(): void {
		$this->deals->method('applyDefaultCategory')->willReturn(['title' => 'Roof', 'forecast_category' => 'pipeline']);

		$this->listener(data: ['title' => 'Roof'], rotation: false)->runDeferredWork(entry: ['uuid' => 'lead-1']);

		$this->assertSame([['object' => ['title' => 'Roof', 'forecast_category' => 'pipeline'], 'uuid' => 'lead-1']], $this->saves);
	}//end testWithoutTheRotationServiceTheDefaultStillSaves()

	/**
	 * Switched on, the defaulted lead is assigned and saved once.
	 *
	 * @return void
	 */
	public function testSwitchedOnTheDefaultedLeadIsAssignedInOneSave(): void {
		$this->deals->method('applyDefaultCategory')->willReturn(['title' => 'Roof', 'forecast_category' => 'pipeline']);
		$this->rotation->method('isEnabled')->willReturn(true);
		$this->rotation->expects($this->once())->method('assign')
			->with(['title' => 'Roof', 'forecast_category' => 'pipeline'])
			->willReturn(['title' => 'Roof', 'forecast_category' => 'pipeline', 'assignee' => 'bram', 'assignmentReason' => 'rotation']);

		$this->listener(data: ['title' => 'Roof'])->runDeferredWork(entry: ['uuid' => 'lead-1']);

		$this->assertSame(
			[['object' => ['title' => 'Roof', 'forecast_category' => 'pipeline', 'assignee' => 'bram', 'assignmentReason' => 'rotation'], 'uuid' => 'lead-1']],
			$this->saves
		);
	}//end testSwitchedOnTheDefaultedLeadIsAssignedInOneSave()

	/**
	 * Switched on, a lead whose category needs no default is still assigned,
	 * from its stored data.
	 *
	 * @return void
	 */
	public function testSwitchedOnALeadWithItsCategoryIsAssigned(): void {
		$this->deals->method('applyDefaultCategory')->willReturn(null);
		$this->rotation->method('isEnabled')->willReturn(true);
		$this->rotation->expects($this->once())->method('assign')
			->with(['id' => 'lead-1', 'title' => 'Roof'])
			->willReturn(['id' => 'lead-1', 'title' => 'Roof', 'assignee' => 'anna']);

		$this->listener(data: ['title' => 'Roof'])->runDeferredWork(entry: ['uuid' => 'lead-1']);

		$this->assertSame([['object' => ['id' => 'lead-1', 'title' => 'Roof', 'assignee' => 'anna'], 'uuid' => 'lead-1']], $this->saves);
	}//end testSwitchedOnALeadWithItsCategoryIsAssigned()

	/**
	 * Switched on, a lead that already has an assignee keeps it: the service
	 * leaves it, so nothing is saved.
	 *
	 * @return void
	 */
	public function testALeadWithAnAssigneeKeepsIt(): void {
		$this->deals->method('applyDefaultCategory')->willReturn(null);
		$this->rotation->method('isEnabled')->willReturn(true);
		$this->rotation->expects($this->once())->method('assign')->willReturn(null);

		$this->listener(data: ['title' => 'Roof', 'assignee' => 'rep'])->runDeferredWork(entry: ['uuid' => 'lead-1']);

		$this->assertSame([], $this->saves);
	}//end testALeadWithAnAssigneeKeepsIt()
}//end class
