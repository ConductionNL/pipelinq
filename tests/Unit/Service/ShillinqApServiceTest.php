<?php

/**
 * Unit tests for ShillinqApService.
 *
 * Asserts the should-dispatch gate (the detected Shillinq app), the CloudEvents 1.0 payload
 * shape for an approved expense (REQ-AP-003 Scenario 7), the billable +
 * project routing in Scenario 8, and the success / failure return contract
 * against a capturing WebhookService double.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\IntegrationDetector;
use OCA\Pipelinq\Service\ShillinqApService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A fake WebhookService capturing dispatched CloudEvents.
 */
class FakeApWebhookService {
	/**
	 * Captured events.
	 *
	 * @var array<int, array{eventName: string, payload: array<string, mixed>}>
	 */
	public array $events = [];

	/**
	 * Whether dispatchEvent should throw to simulate an unavailable consumer.
	 *
	 * @var bool
	 */
	public bool $throw = false;

	/**
	 * Capture (or throw on) a dispatched event.
	 *
	 * @param object $_event The originating event.
	 * @param string $eventName The webhook event name.
	 * @param array<string, mixed> $payload The CloudEvent payload.
	 *
	 * @return void
	 */
	public function dispatchEvent(object $_event, string $eventName, array $payload): void {
		if ($this->throw === true) {
			throw new \RuntimeException('no consumer');
		}

		$this->events[] = ['eventName' => $eventName, 'payload' => $payload];
	}//end dispatchEvent()
}//end class

/**
 * Tests for ShillinqApService.
 */
class ShillinqApServiceTest extends TestCase {
	/**
	 * The capturing webhook double.
	 *
	 * @var FakeApWebhookService
	 */
	private FakeApWebhookService $webhooks;

	/**
	 * Build a service on a server with or without the Shillinq app.
	 *
	 * @param bool $shillinqInstalled Whether the Shillinq app is installed.
	 *
	 * @return ShillinqApService The service under test.
	 */
	private function makeService(bool $shillinqInstalled): ShillinqApService {
		$this->webhooks = new FakeApWebhookService();

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			fn (string $appId): bool => $appId === 'shillinq' && $shillinqInstalled === true
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\OpenRegister\Service\WebhookService') {
					return $this->webhooks;
				}

				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		return new ShillinqApService(
			integrations: new IntegrationDetector($appManager, $this->createMock(IURLGenerator::class)),
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end makeService()

	/**
	 * An installed Shillinq enables dispatch without any URL (pipelinq-audit-admin-forms-pos).
	 *
	 * @return void
	 */
	public function testShouldDispatchTrueWhenShillinqIsInstalled(): void {
		$this->assertTrue($this->makeService(true)->shouldDispatch());
	}//end testShouldDispatchTrueWhenShillinqIsInstalled()

	/**
	 * Without Shillinq there is no dispatch.
	 *
	 * @return void
	 */
	public function testShouldDispatchFalseWhenShillinqIsMissing(): void {
		$this->assertFalse($this->makeService(false)->shouldDispatch());
	}//end testShouldDispatchFalseWhenShillinqIsMissing()

	/**
	 * An approved expense builds the documented CloudEvents payload (REQ-AP-003 Scenario 7).
	 *
	 * @return void
	 */
	public function testDispatchApEventPayload(): void {
		$service = $this->makeService(true);
		$expense = [
			'uuid' => 'abc123',
			'amount' => 125.50,
			'currency' => 'EUR',
			'category' => 'accommodation',
			'client' => 'client-xyz',
			'project' => null,
			'billable' => false,
		];

		$result = $service->dispatchApEvent($expense, 'alice', '2026-05-15T14:30:00Z');

		$this->assertTrue($result);
		$this->assertCount(1, $this->webhooks->events);

		$event = $this->webhooks->events[0];
		$this->assertSame('nl.conduction.pipelinq.expense.approved', $event['eventName']);

		$payload = $event['payload'];
		$this->assertSame('1.0', $payload['specversion']);
		$this->assertSame('nl.conduction.pipelinq.expense.approved', $payload['type']);
		$this->assertSame('/apps/pipelinq/expenses', $payload['source']);
		$this->assertSame('abc123', $payload['id']);
		$this->assertSame('2026-05-15T14:30:00Z', $payload['time']);
		$this->assertSame('application/json', $payload['datacontenttype']);

		$data = $payload['data'];
		$this->assertSame('abc123', $data['expenseId']);
		$this->assertSame(125.50, $data['amount']);
		$this->assertSame('accommodation', $data['categoryId']);
		$this->assertSame('client-xyz', $data['clientId']);
		$this->assertNull($data['projectId']);
		$this->assertFalse($data['billable']);
		$this->assertSame('alice', $data['approvedBy']);
		$this->assertSame('2026-05-15T14:30:00Z', $data['approvedAt']);
	}//end testDispatchApEventPayload()

	/**
	 * A billable expense carries the project reference (REQ-AP-003 Scenario 8).
	 *
	 * @return void
	 */
	public function testDispatchApEventIncludesBillableProjectReference(): void {
		$service = $this->makeService(true);
		$expense = [
			'uuid' => 'def456',
			'amount' => 595.00,
			'category' => 'software',
			'client' => 'client-xyz',
			'project' => 'proj-456',
			'billable' => true,
		];

		$result = $service->dispatchApEvent($expense, 'bob', '2026-05-01T10:00:00Z');

		$this->assertTrue($result);
		$data = $this->webhooks->events[0]['payload']['data'];
		$this->assertSame('proj-456', $data['projectId']);
		$this->assertTrue($data['billable']);
		$this->assertSame('client-xyz', $data['clientId']);
	}//end testDispatchApEventIncludesBillableProjectReference()

	/**
	 * Without Shillinq there is no dispatch and a false return.
	 *
	 * @return void
	 */
	public function testDispatchNoopWhenUnconfigured(): void {
		$service = $this->makeService(false);
		$this->assertFalse($service->dispatchApEvent(['uuid' => 'x'], 'alice', '2026-01-01T00:00:00Z'));
		$this->assertCount(0, $this->webhooks->events);
	}//end testDispatchNoopWhenUnconfigured()

	/**
	 * A WebhookService failure surfaces as a false return (REQ-AP-003 Scenario 10).
	 *
	 * @return void
	 */
	public function testDispatchReturnsFalseOnWebhookFailure(): void {
		$service = $this->makeService(true);
		$this->webhooks->throw = true;
		$this->assertFalse($service->dispatchApEvent(['uuid' => 'x', 'amount' => 1.0], 'alice', '2026-01-01T00:00:00Z')
		);
	}//end testDispatchReturnsFalseOnWebhookFailure()

	/**
	 * The now() helper produces a Zulu ISO 8601 timestamp.
	 *
	 * @return void
	 */
	public function testNowFormat(): void {
		$service = $this->makeService(true);
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$service->now()
		);
	}//end testNowFormat()
}//end class
