<?php

/**
 * Golden test for the Shillinq AP and WIP CloudEvent dispatch.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\IntegrationDetector;
use OCA\Pipelinq\Service\ShillinqApService;
use OCA\Pipelinq\Service\ShillinqWipService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins both services' CloudEvents (every field and fallback), what they dispatch
 * through OpenRegister's WebhookService, and the warning logged when that fails.
 */
class ShillinqEventDispatchGoldenTest extends TestCase {
	/**
	 * The calls made, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * The AP CloudEvent for a full and a minimal expense, and the failure paths.
	 *
	 * @return void
	 */
	public function testApEvents(): void {
		$expense = ['uuid' => 'exp-1', 'id' => 'obj-1', 'amount' => '12.5', 'currency' => 'USD', 'category' => 'cat-1', 'client' => 'cl-1', 'project' => 'pr-1', 'billable' => 1];
		$this->assertTrue($this->ap(installed: true, webhook: 'ok')->dispatchApEvent($expense, 'alice', '2026-03-01T10:00:00+01:00'));
		$this->assertTrue($this->ap(installed: true, webhook: 'ok')->dispatchApEvent(['id' => 'obj-2', 'project' => ''], 'bob', ''));
		$this->assertTrue($this->ap(installed: true, webhook: 'ok')->dispatchApEvent([], '', 'T'));
		$this->assertFalse($this->ap(installed: true, webhook: 'throws')->dispatchApEvent($expense, 'alice', 'T'));
		$this->assertFalse($this->ap(installed: true, webhook: 'missing')->dispatchApEvent($expense, 'alice', 'T'));
		$this->assertFalse($this->ap(installed: false, webhook: 'ok')->dispatchApEvent($expense, 'alice', 'T'));

		$this->assertSame(
			[
				['dispatchEvent', 'nl.conduction.pipelinq.expense.approved', [
					'specversion' => '1.0', 'type' => 'nl.conduction.pipelinq.expense.approved', 'source' => '/apps/pipelinq/expenses', 'id' => 'exp-1', 'time' => '2026-03-01T10:00:00+01:00', 'datacontenttype' => 'application/json',
					'data' => ['expenseId' => 'exp-1', 'amount' => 12.5, 'currency' => 'USD', 'categoryId' => 'cat-1', 'clientId' => 'cl-1', 'projectId' => 'pr-1', 'billable' => true, 'approvedBy' => 'alice', 'approvedAt' => '2026-03-01T10:00:00+01:00'],
				]],
				['dispatchEvent', 'nl.conduction.pipelinq.expense.approved', [
					'specversion' => '1.0', 'type' => 'nl.conduction.pipelinq.expense.approved', 'source' => '/apps/pipelinq/expenses', 'id' => 'obj-2', 'time' => 'NOW', 'datacontenttype' => 'application/json',
					'data' => ['expenseId' => 'obj-2', 'amount' => 0.0, 'currency' => 'EUR', 'categoryId' => '', 'clientId' => '', 'projectId' => null, 'billable' => false, 'approvedBy' => 'bob', 'approvedAt' => ''],
				]],
				['dispatchEvent', 'nl.conduction.pipelinq.expense.approved', [
					'specversion' => '1.0', 'type' => 'nl.conduction.pipelinq.expense.approved', 'source' => '/apps/pipelinq/expenses', 'id' => '', 'time' => 'T', 'datacontenttype' => 'application/json',
					'data' => ['expenseId' => '', 'amount' => 0.0, 'currency' => 'EUR', 'categoryId' => '', 'clientId' => '', 'projectId' => null, 'billable' => false, 'approvedBy' => '', 'approvedAt' => 'T'],
				]],
				['warning', 'Pipelinq: Shillinq AP CloudEvent not dispatched (no consumer or OpenRegister unavailable)', ['exception' => 'no consumer', 'eventName' => 'nl.conduction.pipelinq.expense.approved']],
				['warning', 'Pipelinq: Shillinq AP CloudEvent not dispatched (no consumer or OpenRegister unavailable)', ['exception' => 'no such service', 'eventName' => 'nl.conduction.pipelinq.expense.approved']],
			],
			$this->trace
		);
	}//end testApEvents()

	/**
	 * The WIP CloudEvent for a full and a minimal time entry, and the failure paths.
	 *
	 * @return void
	 */
	public function testWipEvents(): void {
		$entry = ['id' => 'te-1', 'uuid' => 'u-1', 'hours' => '1.75', 'billingCategory' => 'bc-1', 'client' => 'cl-1', 'lead' => 'ld-1', 'project' => 'pr-1'];
		$this->assertTrue($this->wip(installed: true, webhook: 'ok')->dispatchWipEvent($entry, 'alice', '2026-03-01T10:00:00+01:00'));
		$this->assertTrue($this->wip(installed: true, webhook: 'ok')->dispatchWipEvent(['uuid' => 'u-2'], 'bob', ''));
		$this->assertFalse($this->wip(installed: true, webhook: 'throws')->dispatchWipEvent($entry, 'alice', 'T'));
		$this->assertFalse($this->wip(installed: false, webhook: 'ok')->dispatchWipEvent($entry, 'alice', 'T'));

		$this->assertSame(
			[
				['dispatchEvent', 'nl.conduction.pipelinq.time.approved', [
					'specversion' => '1.0', 'type' => 'nl.conduction.pipelinq.time.approved', 'source' => '/apps/pipelinq/time-entries', 'id' => 'te-1', 'time' => '2026-03-01T10:00:00+01:00', 'datacontenttype' => 'application/json',
					'data' => ['timeEntryId' => 'te-1', 'hours' => 1.75, 'billingCategoryId' => 'bc-1', 'clientId' => 'cl-1', 'leadId' => 'ld-1', 'projectId' => 'pr-1', 'approvedBy' => 'alice', 'approvedAt' => '2026-03-01T10:00:00+01:00'],
				]],
				['dispatchEvent', 'nl.conduction.pipelinq.time.approved', [
					'specversion' => '1.0', 'type' => 'nl.conduction.pipelinq.time.approved', 'source' => '/apps/pipelinq/time-entries', 'id' => 'u-2', 'time' => 'NOW', 'datacontenttype' => 'application/json',
					'data' => ['timeEntryId' => 'u-2', 'hours' => 0.0, 'billingCategoryId' => '', 'clientId' => '', 'leadId' => '', 'projectId' => '', 'approvedBy' => 'bob', 'approvedAt' => ''],
				]],
				['warning', 'Pipelinq: Shillinq WIP CloudEvent not dispatched (no consumer or OpenRegister unavailable)', ['exception' => 'no consumer', 'eventName' => 'nl.conduction.pipelinq.time.approved']],
			],
			$this->trace
		);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $this->wip(installed: true, webhook: 'ok')->now());
	}//end testWipEvents()

	/**
	 * An AP service.
	 *
	 * @param bool   $installed Whether Shillinq is installed.
	 * @param string $webhook   ok, throws or missing.
	 *
	 * @return ShillinqApService
	 */
	private function ap(bool $installed, string $webhook): ShillinqApService {
		return new ShillinqApService(integrations: $this->integrations(installed: $installed), container: $this->container(webhook: $webhook), logger: $this->logger());
	}//end ap()

	/**
	 * A WIP service.
	 *
	 * @param bool   $installed Whether Shillinq is installed.
	 * @param string $webhook   ok, throws or missing.
	 *
	 * @return ShillinqWipService
	 */
	private function wip(bool $installed, string $webhook): ShillinqWipService {
		return new ShillinqWipService(integrations: $this->integrations(installed: $installed), container: $this->container(webhook: $webhook), logger: $this->logger());
	}//end wip()

	/**
	 * An integration detector seeing Shillinq or not.
	 *
	 * @param bool $installed Whether Shillinq is installed.
	 *
	 * @return IntegrationDetector
	 */
	private function integrations(bool $installed): IntegrationDetector {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $appId): bool => $appId === 'shillinq' && $installed === true);
		return new IntegrationDetector($apps, $this->createMock(IURLGenerator::class));
	}//end integrations()

	/**
	 * A container serving a recording WebhookService, one that throws, or none.
	 *
	 * @param string $webhook ok, throws or missing.
	 *
	 * @return ContainerInterface
	 */
	private function container(string $webhook): ContainerInterface {
		$trace = &$this->trace;
		$service = new class($trace, $webhook === 'throws') {
			/**
			 * @param array<int, array<int, mixed>> $trace  The shared trace.
			 * @param bool                          $throws Whether to throw.
			 */
			public function __construct(private array &$trace, private bool $throws) {
			}

			/**
			 * @param object               $_event    The event.
			 * @param string               $eventName The event name.
			 * @param array<string, mixed> $payload   The payload.
			 *
			 * @return void
			 */
			public function dispatchEvent(object $_event, string $eventName, array $payload): void {
				if ($this->throws === true) {
					throw new \RuntimeException('no consumer');
				}

				if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)$payload['time']) === 1) {
					$payload['time'] = 'NOW';
				}

				$this->trace[] = ['dispatchEvent', $eventName, $payload];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service, $webhook): object {
				if ($webhook === 'missing' || $id !== 'OCA\OpenRegister\Service\WebhookService') {
					throw new \RuntimeException('no such service');
				}

				return $service;
			}
		);
		return $container;
	}//end container()

	/**
	 * A logger recording warnings.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->trace[] = ['warning', $message, $context];
			}
		);
		return $logger;
	}//end logger()
}//end class
