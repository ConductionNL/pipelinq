<?php

/**
 * Pipelinq ExpenseApSyncTest.
 *
 * Integration test for the full expense-approval to Shillinq AP sync flow.
 * Wires the real ExpenseApprovalListener against a fake ObjectService and a
 * fake OpenRegister WebhookService and asserts that an approved expense
 *
 *   1. transitions through apSyncStatus pending -> synced,
 *   2. stamps apSyncedAt with the AP service clock,
 *   3. fans out a domain ExpenseApprovedEvent that downstream consumers can
 *      subscribe to without coupling to OpenRegister events,
 *   4. is idempotent on event replay (no duplicate AP voucher dispatched),
 *   5. records apSyncStatus=failed and notifies admins on webhook failure
 *      (REQ-AP-001, REQ-AP-002, REQ-AP-003).
 *
 * Closer to a unit test than a true Nextcloud integration test by necessity
 * (Nextcloud is not booted in CI) but it exercises the real listener, real
 * AP service, the real CloudEvents payload builder and the IEventDispatcher
 * fan-out -- the critical seam between pipelinq and Shillinq.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Integration
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-001
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-002
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Integration;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Event\ExpenseApprovedEvent;
use OCA\Pipelinq\Listener\ExpenseApprovalListener;
use OCA\Pipelinq\Service\ApSyncNotifier;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\ShillinqApService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fake ObjectService capturing saveObject() calls.
 */
class IntegrationFakeObjectService {
	/**
	 * Captured saved objects keyed by uuid (last-write wins).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Full audit trail of every saveObject() call in chronological order.
	 *
	 * Used to assert the pending -> synced/failed transition rather than
	 * only the final-state snapshot.
	 *
	 * @var array<int, array{uuid: string, data: array<string, mixed>}>
	 */
	public array $trail = [];

	/**
	 * Capture a saved object.
	 *
	 * @param array|object $object The object data.
	 * @param array $extend Unused.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param string|null $uuid The object uuid.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject($object, array $extend = [], string $register = '', string $schema = '', ?string $uuid = null): array {
		$data = (array)$object;
		$this->saved[(string)$uuid] = $data;
		$this->trail[] = ['uuid' => (string)$uuid, 'data' => $data];
		return $data;
	}//end saveObject()
}//end class

/**
 * Fake OpenRegister WebhookService capturing dispatchEvent() calls.
 */
class IntegrationFakeWebhookService {
	/**
	 * Whether the next call should fail (simulate non-2xx response).
	 *
	 * @var bool
	 */
	public bool $failNext = false;

	/**
	 * Captured event dispatches in chronological order.
	 *
	 * @var array<int, array{eventName: string, payload: array<string, mixed>}>
	 */
	public array $dispatched = [];

	/**
	 * Dispatch a webhook event.
	 *
	 * @param mixed $_event Unused (OR Event).
	 * @param string $eventName The event name.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the test toggled failNext.
	 */
	public function dispatchEvent(mixed $_event, string $eventName, array $payload): void {
		if ($this->failNext === true) {
			throw new \RuntimeException('Simulated AP webhook failure.');
		}
		$this->dispatched[] = ['eventName' => $eventName, 'payload' => $payload];
	}//end dispatchEvent()
}//end class

/**
 * In-memory IEventDispatcher capturing dispatchTyped() calls.
 */
class IntegrationFakeEventDispatcher implements IEventDispatcher {
	/**
	 * Captured dispatches in chronological order.
	 *
	 * @var array<int, Event>
	 */
	public array $dispatched = [];

	/**
	 * Capture a typed dispatch.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 */
	public function dispatchTyped(Event $event): void {
		$this->dispatched[] = $event;
	}//end dispatchTyped()

	/**
	 * Capture a named dispatch.
	 *
	 * @param string $eventName The event name.
	 * @param Event $event The event.
	 *
	 * @return void
	 */
	public function dispatch(string $eventName, Event $event): void {
		$this->dispatched[] = $event;
	}//end dispatch()

	/**
	 * No-op listener registration.
	 *
	 * @param string $eventName The event class name.
	 * @param callable $listener The listener callable.
	 * @param int $priority The priority.
	 *
	 * @return void
	 */
	public function addListener(string $eventName, callable $listener, int $priority = 0): void {
	}//end addListener()

	/**
	 * No-op service-listener registration.
	 *
	 * @param string $eventName The event class name.
	 * @param string $className The listener class name.
	 * @param int $priority The priority.
	 *
	 * @return void
	 */
	public function addServiceListener(string $eventName, string $className, int $priority = 0): void {
	}//end addServiceListener()

	/**
	 * No-op listener removal.
	 *
	 * @param string $eventName The event class name.
	 * @param callable $listener The listener callable.
	 *
	 * @return void
	 */
	public function removeListener(string $eventName, callable $listener): void {
	}//end removeListener()

	/**
	 * No-op named listener check.
	 *
	 * @param string $eventName The event class name.
	 *
	 * @return bool Always false in the fake.
	 */
	public function hasListeners(string $eventName): bool {
		return false;
	}//end hasListeners()
}//end class

/**
 * Integration tests for the expense-approval -> Shillinq AP flow.
 */
class ExpenseApSyncTest extends TestCase {
	/**
	 * Build an ObjectEntity double for the expense schema with the given data.
	 *
	 * @param array<string, mixed> $data The expense data.
	 *
	 * @return ObjectEntity The entity double.
	 */
	private function expenseEntity(array $data): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchema', 'getUuid', 'getObject', 'jsonSerialize'])
			->getMock();
		$entity->method('getSchema')->willReturn('schema-expense');
		$entity->method('getUuid')->willReturn((string)($data['uuid'] ?? 'exp-1'));
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end expenseEntity()

	/**
	 * Build the listener wired to real collaborators (and to the fakes for the
	 * boundaries we cannot reach in CI).
	 *
	 * @param IntegrationFakeObjectService $objects The fake ObjectService.
	 * @param IntegrationFakeWebhookService $webhooks The fake WebhookService.
	 * @param IntegrationFakeEventDispatcher $dispatcher The fake event dispatcher.
	 * @param ApSyncNotifier|null $notifier Optional notifier override.
	 * @param string $webhookUrl The configured webhook URL ('' disables).
	 *
	 * @return ExpenseApprovalListener The listener under test.
	 */
	private function buildListener(
		IntegrationFakeObjectService $objects,
		IntegrationFakeWebhookService $webhooks,
		IntegrationFakeEventDispatcher $dispatcher,
		?ApSyncNotifier $notifier = null,
		string $webhookUrl = 'https://shillinq.example.com/ap/webhook',
	): ExpenseApprovalListener {
		$logger = $this->createMock(LoggerInterface::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($webhookUrl): string {
				if ($key === 'register') {
					return 'reg-pipelinq';
				}
				if ($key === 'expense_schema') {
					return 'schema-expense';
				}
				if ($key === 'shillinq_ap_webhook_url') {
					return $webhookUrl;
				}
				return $default;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objects, $webhooks) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objects;
				}
				if ($id === 'OCA\OpenRegister\Service\WebhookService') {
					return $webhooks;
				}
				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		$apService = new ShillinqApService(
			appConfig: $appConfig,
			container: $container,
			logger: $logger,
		);

		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('expense');

		return new ExpenseApprovalListener(
			schemaMapService: $schemaMap,
			apService: $apService,
			notifier: ($notifier ?? $this->createMock(ApSyncNotifier::class)),
			eventDispatcher: $dispatcher,
			container: $container,
			appConfig: $appConfig,
			logger: $logger,
		);
	}//end buildListener()

	/**
	 * Successful flow: approval -> domain event -> pending -> CloudEvent dispatch -> synced.
	 *
	 * @return void
	 */
	public function testApprovalFlowSyncsExpenseEndToEnd(): void {
		$objects = new IntegrationFakeObjectService();
		$webhooks = new IntegrationFakeWebhookService();
		$dispatcher = new IntegrationFakeEventDispatcher();

		$listener = $this->buildListener($objects, $webhooks, $dispatcher);

		$listener->handle(new ObjectCreatedEvent($this->expenseEntity([
			'uuid' => 'exp-int-1',
			'title' => 'Hotel Den Haag',
			'amount' => 185.50,
			'currency' => 'EUR',
			'category' => 'accommodation',
			'client' => 'client-abc',
			'project' => 'project-xyz',
			'billable' => true,
			'status' => 'approved',
			'approvedBy' => 'alice',
			'approvedAt' => '2026-05-15T14:30:00Z',
		])));

		// 1. Domain ExpenseApprovedEvent was fanned out (REQ-AP-002 consumer contract).
		$this->assertCount(1, $dispatcher->dispatched, 'ExpenseApprovedEvent MUST fan out exactly once.');
		$this->assertInstanceOf(ExpenseApprovedEvent::class, $dispatcher->dispatched[0]);
		$this->assertSame('exp-int-1', $dispatcher->dispatched[0]->getExpenseUuid());
		$this->assertSame('alice', $dispatcher->dispatched[0]->getApprovedBy());
		$this->assertSame('2026-05-15T14:30:00Z', $dispatcher->dispatched[0]->getApprovedAt());

		// 2. The persistence trail recorded pending first, then synced (REQ-AP-001).
		$this->assertGreaterThanOrEqual(2, count($objects->trail), 'Listener MUST write pending then synced.');
		$this->assertSame('pending', $objects->trail[0]['data']['apSyncStatus']);
		$this->assertSame('synced', $objects->trail[count($objects->trail) - 1]['data']['apSyncStatus']);

		// 3. Final state on the saved object is synced with an ISO 8601 timestamp.
		$final = $objects->saved['exp-int-1'];
		$this->assertSame('synced', $final['apSyncStatus']);
		$this->assertNotEmpty($final['apSyncedAt'] ?? '');
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			(string)$final['apSyncedAt'],
			'apSyncedAt MUST be an ISO 8601 UTC timestamp.'
		);

		// 4. WebhookService received exactly one CloudEvents 1.0 envelope with
		//    every field REQ-AP-003 Scenario 7 mandates.
		$this->assertCount(1, $webhooks->dispatched, 'AP webhook MUST be dispatched exactly once.');
		$payload = $webhooks->dispatched[0]['payload'];
		$this->assertSame('1.0', $payload['specversion']);
		$this->assertSame(ShillinqApService::EVENT_EXPENSE_APPROVED, $payload['type']);
		$this->assertSame('exp-int-1', $payload['id']);
		$this->assertSame('exp-int-1', $payload['data']['expenseId']);
		$this->assertSame(185.5, $payload['data']['amount']);
		$this->assertSame('EUR', $payload['data']['currency']);
		$this->assertSame('accommodation', $payload['data']['categoryId']);
		$this->assertSame('client-abc', $payload['data']['clientId']);
		$this->assertSame('project-xyz', $payload['data']['projectId']);
		$this->assertTrue($payload['data']['billable']);
		$this->assertSame('alice', $payload['data']['approvedBy']);
		$this->assertSame('2026-05-15T14:30:00Z', $payload['data']['approvedAt']);
	}//end testApprovalFlowSyncsExpenseEndToEnd()

	/**
	 * Replay of an already-synced expense MUST NOT dispatch a second voucher
	 * (REQ-AP-002 Scenario 5 -- idempotency).
	 *
	 * @return void
	 */
	public function testReplayOfSyncedExpenseIsIdempotent(): void {
		$objects = new IntegrationFakeObjectService();
		$webhooks = new IntegrationFakeWebhookService();
		$dispatcher = new IntegrationFakeEventDispatcher();

		$listener = $this->buildListener($objects, $webhooks, $dispatcher);

		$listener->handle(new ObjectUpdatedEvent(
			$this->expenseEntity([
				'uuid' => 'exp-int-2',
				'status' => 'approved',
				'apSyncStatus' => 'synced',
				'apSyncedAt' => '2026-05-15T14:35:00Z',
				'amount' => 100.0,
			]),
			$this->expenseEntity([
				'uuid' => 'exp-int-2',
				'status' => 'approved',
				'apSyncStatus' => 'synced',
				'apSyncedAt' => '2026-05-15T14:35:00Z',
				'amount' => 100.0,
			])
		));

		$this->assertCount(0, $webhooks->dispatched, 'Replay MUST NOT dispatch a duplicate AP voucher.');
		$this->assertCount(0, $objects->saved, 'Replay MUST NOT mutate the synced expense.');
		$this->assertCount(0, $dispatcher->dispatched, 'Replay MUST NOT re-emit the domain event.');
	}//end testReplayOfSyncedExpenseIsIdempotent()

	/**
	 * Webhook failure marks the expense failed and notifies admins
	 * (REQ-AP-003 Scenario 10).
	 *
	 * @return void
	 */
	public function testWebhookFailureMarksFailedAndNotifies(): void {
		$objects = new IntegrationFakeObjectService();
		$webhooks = new IntegrationFakeWebhookService();
		$dispatcher = new IntegrationFakeEventDispatcher();

		$webhooks->failNext = true;

		$notifier = $this->createMock(ApSyncNotifier::class);
		$notifier->expects($this->once())->method('notifyFailure');

		$listener = $this->buildListener($objects, $webhooks, $dispatcher, $notifier);

		$listener->handle(new ObjectCreatedEvent($this->expenseEntity([
			'uuid' => 'exp-int-3',
			'title' => 'Catering',
			'amount' => 78.30,
			'status' => 'approved',
			'approvedBy' => 'bob',
			'approvedAt' => '2026-05-10T11:20:00Z',
		])));

		$this->assertSame('failed', $objects->saved['exp-int-3']['apSyncStatus']);
		$this->assertArrayNotHasKey(
			'apSyncedAt',
			$objects->saved['exp-int-3'],
			'apSyncedAt MUST NOT be set on a failed dispatch.'
		);
	}//end testWebhookFailureMarksFailedAndNotifies()

	/**
	 * Unconfigured webhook silently no-ops (REQ-AP-002 Scenario 6 + REQ-AP-003).
	 *
	 * @return void
	 */
	public function testUnconfiguredWebhookNoOps(): void {
		$objects = new IntegrationFakeObjectService();
		$webhooks = new IntegrationFakeWebhookService();
		$dispatcher = new IntegrationFakeEventDispatcher();

		$listener = $this->buildListener($objects, $webhooks, $dispatcher, webhookUrl: '');

		$listener->handle(new ObjectCreatedEvent($this->expenseEntity([
			'uuid' => 'exp-int-4',
			'title' => 'Office supplies',
			'amount' => 42.00,
			'status' => 'approved',
			'approvedBy' => 'carol',
			'approvedAt' => '2026-05-11T09:00:00Z',
		])));

		$this->assertCount(0, $webhooks->dispatched);
		$this->assertCount(0, $objects->saved);
		$this->assertCount(0, $dispatcher->dispatched);
	}//end testUnconfiguredWebhookNoOps()
}//end class
