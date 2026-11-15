<?php

/**
 * Edge cases of BudgetService: failing reads and writes, cost caps, alerts and period roll-over.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\NotificationService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * The budget gate fails open on a missing budget and closed on a spent hard cap.
 *
 * @covers \OCA\Pipelinq\Service\BudgetService
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 */
class BudgetServiceEdgesTest extends TestCase {

	/**
	 * The OpenRegister double.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The notification mock.
	 *
	 * @var NotificationService&MockObject
	 */
	private NotificationService $notifications;

	/**
	 * Whether the container fails to give the ObjectService.
	 *
	 * @var bool
	 */
	private bool $noObjectService = false;

	/**
	 * App config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<int, array<string, mixed>> */
			public array $rows = [];

			/** @var array<int, array<string, mixed>> */
			public array $findAllCalls = [];

			/** @var array<int, array<string, mixed>> */
			public array $saves = [];

			/** @var bool */
			public bool $failFindAll = false;

			/** @var bool */
			public bool $failSave = false;

			/**
			 * Every row; the filters are recorded, not applied.
			 *
			 * @param array<string, mixed> $config The config.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$this->findAllCalls[] = $config;
				if ($this->failFindAll === true) {
					throw new RuntimeException('down');
				}

				return $this->rows;
			}

			/**
			 * Save a row.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				if ($this->failSave === true) {
					throw new RuntimeException('refused');
				}

				return $object;
			}
		};

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->notifications = $this->createMock(NotificationService::class);

	}//end setUp()

	/**
	 * The service over the doubles.
	 *
	 * @return BudgetService
	 */
	private function service(): BudgetService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($this->noObjectService === true) {
					throw new RuntimeException('no OpenRegister');
				}

				return $this->objects;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		return new BudgetService($container, $appConfig, $this->notifications, $this->logger);

	}//end service()

	/**
	 * Blank ids are never gated and never read.
	 *
	 * @return void
	 */
	public function testBlankIdsAreNotGated(): void {
		$service = $this->service();

		$this->assertTrue($service->canSend(tenantId: '', providerId: 'p'));
		$this->assertTrue($service->canSend(tenantId: 't', providerId: ''));
		$service->recordSend(tenantId: '', providerId: 'p', costEur: 1.0);
		$this->assertSame([], $this->objects->findAllCalls);
		$this->assertSame([], $this->objects->saves);

	}//end testBlankIdsAreNotGated()

	/**
	 * The budget lookup filters on tenant and provider, under the default register and schema.
	 *
	 * @return void
	 */
	public function testTheLookupUsesTheDefaultRegisterAndSchema(): void {
		$this->service()->canSend(tenantId: 't-1', providerId: 'p-1');

		$this->assertSame(
			[['filters' => ['tenantId' => 't-1', 'providerId' => 'p-1', 'register' => 'pipelinq', 'schema' => 'messageSendBudget']]],
			$this->objects->findAllCalls
		);

	}//end testTheLookupUsesTheDefaultRegisterAndSchema()

	/**
	 * A failing read leaves the send ungated, logged.
	 *
	 * @return void
	 */
	public function testAFailingReadDoesNotGate(): void {
		$this->objects->rows = [['hardStop' => true, 'maxMessages' => 1, 'currentPeriodMessages' => 5]];
		$this->objects->failFindAll = true;
		$this->logger->expects($this->once())->method('warning')->with(
			'BudgetService.loadBudget: findAll failed',
			['tenantId' => 't', 'providerId' => 'p', 'exception' => 'down']
		);

		$this->assertTrue($this->service()->canSend(tenantId: 't', providerId: 'p'));

	}//end testAFailingReadDoesNotGate()

	/**
	 * Without OpenRegister the send is not gated, logged.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheSendIsNotGated(): void {
		$this->noObjectService = true;
		$this->logger->expects($this->once())->method('warning')->with('BudgetService.getObjectService: OpenRegister unavailable', ['exception' => 'no OpenRegister']);

		$this->assertTrue($this->service()->canSend(tenantId: 't', providerId: 'p'));

	}//end testWithoutOpenRegisterTheSendIsNotGated()

	/**
	 * A hard cost cap refuses the send that would pass it, and allows the one that reaches it.
	 *
	 * @return void
	 */
	public function testAHardCostCapRefusesTheSendThatPassesIt(): void {
		$this->objects->rows = [['hardStop' => true, 'maxCostEur' => 10.0, 'currentPeriodCostEur' => 9.5]];
		$service = $this->service();

		$this->assertFalse($service->canSend(tenantId: 't', providerId: 'p', estimatedCostEur: 0.6));
		$this->assertTrue($service->canSend(tenantId: 't', providerId: 'p', estimatedCostEur: 0.5));

	}//end testAHardCostCapRefusesTheSendThatPassesIt()

	/**
	 * A hard stop with no caps set allows every send.
	 *
	 * @return void
	 */
	public function testAHardStopWithoutCapsAllows(): void {
		$this->objects->rows = [['hardStop' => true, 'currentPeriodMessages' => 1000, 'currentPeriodCostEur' => 1000.0]];

		$this->assertTrue($this->service()->canSend(tenantId: 't', providerId: 'p', estimatedCostEur: 5.0));

	}//end testAHardStopWithoutCapsAllows()

	/**
	 * Recording without a budget writes nothing.
	 *
	 * @return void
	 */
	public function testRecordingWithoutABudgetWritesNothing(): void {
		$this->service()->recordSend(tenantId: 't', providerId: 'p', costEur: 1.0);

		$this->assertCount(1, $this->objects->findAllCalls);
		$this->assertSame([], $this->objects->saves);

	}//end testRecordingWithoutABudgetWritesNothing()

	/**
	 * Crossing the cost threshold alerts once, with the counters, under the @self id.
	 *
	 * @return void
	 */
	public function testCrossingTheCostThresholdAlerts(): void {
		$this->objects->rows = [['@self' => ['uuid' => 'b-1'], 'period' => 'weekly', 'maxCostEur' => 10.0, 'alertThresholdPct' => 0.5, 'currentPeriodMessages' => 3, 'currentPeriodCostEur' => 4.0]];
		$this->notifications->expects($this->once())->method('sendNotification')->with(
			'admin',
			'messaging_budget_threshold',
			['tenantId' => 't', 'providerId' => 'p', 'period' => 'weekly', 'messages' => 4, 'costEur' => 5.0],
			'messageSendBudget',
			'b-1'
		);

		$this->service()->recordSend(tenantId: 't', providerId: 'p', costEur: 1.0);

		$this->assertCount(1, $this->objects->saves);
		$save = $this->objects->saves[0];
		$this->assertSame('b-1', $save['uuid']);
		$this->assertSame(4, $save['object']['currentPeriodMessages']);
		$this->assertSame(5.0, $save['object']['currentPeriodCostEur']);
		$this->assertNotSame('', $save['object']['alertedAtPeriodStart']);

	}//end testCrossingTheCostThresholdAlerts()

	/**
	 * A budget already alerted this period is not alerted again.
	 *
	 * @return void
	 */
	public function testAnAlertedBudgetIsNotAlertedAgain(): void {
		$this->objects->rows = [['id' => 'b-2', 'maxMessages' => 2, 'currentPeriodMessages' => 5, 'alertedAtPeriodStart' => '2026-11-01T00:00:00Z']];
		$this->notifications->expects($this->never())->method('sendNotification');

		$this->service()->recordSend(tenantId: 't', providerId: 'p');

		$this->assertSame('b-2', $this->objects->saves[0]['uuid']);
		$this->assertSame('2026-11-01T00:00:00Z', $this->objects->saves[0]['object']['alertedAtPeriodStart']);

	}//end testAnAlertedBudgetIsNotAlertedAgain()

	/**
	 * A failing notification and a failing save are logged, not thrown.
	 *
	 * @return void
	 */
	public function testFailuresWhileRecordingAreLogged(): void {
		$this->objects->rows = [['maxMessages' => 1, 'currentPeriodMessages' => 0]];
		$this->objects->failSave = true;
		$this->notifications->method('sendNotification')->willThrowException(new RuntimeException('no bell'));
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$this->service()->recordSend(tenantId: 't', providerId: 'p');

		$this->assertSame(
			[
				['BudgetService.fireAlert: notification failed', ['tenantId' => 't', 'exception' => 'no bell']],
				['BudgetService.saveObject: save failed', ['id' => '', 'exception' => 'refused']],
			],
			$warnings
		);
		$this->assertNull($this->objects->saves[0]['uuid']);

	}//end testFailuresWhileRecordingAreLogged()

	/**
	 * Daily and weekly budgets roll by their period; blank and future ones are left alone.
	 *
	 * @return void
	 */
	public function testResetPeriodsRollsByPeriod(): void {
		$this->config = ['register' => 'crm', 'messageSendBudget_schema' => 'budget'];
		$this->objects->rows = [
			['uuid' => 'd', 'period' => 'daily', 'periodResetAt' => '2026-01-01T00:00:00Z', 'currentPeriodMessages' => 9, 'currentPeriodCostEur' => 3.0, 'alertedAtPeriodStart' => 'x'],
			['uuid' => 'w', 'period' => 'weekly', 'periodResetAt' => '2026-01-01T00:00:00Z'],
			['uuid' => 'm', 'periodResetAt' => '2026-01-31T00:00:00Z'],
			['uuid' => 'blank', 'periodResetAt' => ''],
			['uuid' => 'future', 'periodResetAt' => '2999-01-01T00:00:00Z'],
		];

		$this->assertSame(3, $this->service()->resetPeriods());

		$this->assertSame([['filters' => ['register' => 'crm', 'schema' => 'budget']]], $this->objects->findAllCalls);
		$byId = [];
		foreach ($this->objects->saves as $save) {
			$byId[$save['uuid']] = $save['object'];
			$this->assertSame('budget', $save['schema']);
		}

		$this->assertSame(['d', 'w', 'm'], array_keys($byId));
		$this->assertSame('2026-01-02T00:00:00Z', $byId['d']['periodResetAt']);
		$this->assertSame(0, $byId['d']['currentPeriodMessages']);
		$this->assertSame(0, $byId['d']['currentPeriodCostEur']);
		$this->assertSame('', $byId['d']['alertedAtPeriodStart']);
		$this->assertSame('2026-01-08T00:00:00Z', $byId['w']['periodResetAt']);
		$this->assertSame('2026-03-03T00:00:00Z', $byId['m']['periodResetAt']);

	}//end testResetPeriodsRollsByPeriod()

	/**
	 * An unreadable reset date rolls about thirty days from now, logged.
	 *
	 * @return void
	 */
	public function testAnUnreadableResetDateRollsThirtyDays(): void {
		$this->objects->rows = [['uuid' => 'x', 'period' => 'daily', 'periodResetAt' => '0000-bogus']];
		$this->logger->expects($this->once())->method('warning')->with(
			'BudgetService.advancePeriod: parse failed',
			$this->callback(static fn (array $context): bool => $context['from'] === '0000-bogus' && $context['period'] === 'daily')
		);

		$before = time();
		$this->assertSame(1, $this->service()->resetPeriods());

		$next = strtotime($this->objects->saves[0]['object']['periodResetAt']);
		$this->assertGreaterThanOrEqual($before + (30 * 86400), $next);
		$this->assertLessThanOrEqual(time() + (30 * 86400), $next);

	}//end testAnUnreadableResetDateRollsThirtyDays()

	/**
	 * Reset reads nothing back when the read fails or OpenRegister is missing.
	 *
	 * @return void
	 */
	public function testResetPeriodsWithoutRows(): void {
		$this->objects->failFindAll = true;
		$this->logger->expects($this->exactly(2))->method('warning')->willReturnCallback(
			static function (string $message): void {
				self::assertContains($message, ['BudgetService.loadAllBudgets: findAll failed', 'BudgetService.getObjectService: OpenRegister unavailable']);
			}
		);

		$this->assertSame(0, $this->service()->resetPeriods());
		$this->noObjectService = true;
		$this->assertSame(0, $this->service()->resetPeriods());
		$this->assertSame([], $this->objects->saves);

	}//end testResetPeriodsWithoutRows()
}//end class
