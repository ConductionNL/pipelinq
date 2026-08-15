<?php

/**
 * Unit tests for PosBookkeepingService.
 *
 * Exercises the operational Z-report aggregation and the registry-mediated
 * journal raise (shillinq.JournalEntry.raise) against in-memory fakes of
 * OpenRegister's ObjectService + WebhookService. The real PosAccessPolicy is
 * used so the manager-gate is exercised against a mocked group manager. The
 * GL chart + the journal entry itself live in shillinq — this service only
 * sends business facts and projects the outcome onto the Z-report.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosBookkeepingService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory fake of the OR ObjectService.
 */
class BookkeepingFakeObjectService {

	/**
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * @var integer
	 */
	private int $seq = 0;

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, string $register, string $schema): ?array {
		return $this->store[$schema][$id] ?? null;
	}//end find()

	/**
	 * @param array<string, mixed> $config
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config): array {
		$filters = $config['filters'] ?? [];
		$schema = (string)($filters['schema'] ?? '');
		return array_values($this->store[$schema] ?? []);
	}//end findAll()

	/**
	 * @param array<string, mixed> $object
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array {
		if ($uuid === '') {
			$this->seq++;
			$uuid = $schema . '-' . $this->seq;
		}

		$object['id'] = $uuid;
		$this->store[$schema][$uuid] = $object;
		return $object;
	}//end saveObject()
}//end class

/**
 * Fake WebhookService capturing dispatched CloudEvents (optionally failing).
 */
class BookkeepingFakeWebhookService {

	/**
	 * @var array<int, array{eventName: string, payload: array<string, mixed>}>
	 */
	public array $events = [];

	/**
	 * @var boolean
	 */
	public bool $fail = false;

	/**
	 * @param array<string, mixed> $payload
	 */
	public function dispatchEvent(object $_event, string $eventName, array $payload): void {
		if ($this->fail === true) {
			throw new \RuntimeException('webhook delivery failed');
		}

		$this->events[] = ['eventName' => $eventName, 'payload' => $payload];
	}//end dispatchEvent()
}//end class

/**
 * Tests for PosBookkeepingService.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PosBookkeepingServiceTest extends TestCase {

	private PosBookkeepingService $service;

	private BookkeepingFakeObjectService $objects;

	private BookkeepingFakeWebhookService $webhooks;

	private IGroupManager $groupManager;

	private IAppConfig $appConfig;

	/**
	 * @var array<int, string>
	 */
	private array $admins = [];

	/**
	 * @var array<int, array{to: string, subject: string, body: string}>
	 */
	private array $emailsSent = [];

	/**
	 * @var array<string, string>
	 */
	private array $appConfigStore = [
		'register' => 'reg',
		'shillinq_journal_webhook_url' => 'https://shillinq.example.org/webhook',
		'pos_eod.alert_email' => 'accounting@example.org',
		'pos_eod.max_retry_attempts' => '5',
	];

	protected function setUp(): void {
		$this->objects = new BookkeepingFakeObjectService();
		$this->webhooks = new BookkeepingFakeWebhookService();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') {
				if (isset($this->appConfigStore[$key])) {
					return $this->appConfigStore[$key];
				}

				if ($key === PosAccessPolicy::POS_GROUP_KEY) {
					return $default !== '' ? $default : PosAccessPolicy::POS_GROUP_DEFAULT;
				}

				if ($key === PosAccessPolicy::MANAGER_GROUP_KEY) {
					return $default;
				}

				// Every *_schema key resolves to the key itself as a stable id.
				return $key;
			}
		);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturnCallback(
			fn (string $uid): bool => in_array($uid, $this->admins, true)
		);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$policy = new PosAccessPolicy(
			appConfig: $this->appConfig,
			groupManager: $this->groupManager,
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $this->objects;
				}

				if ($id === 'OCA\OpenRegister\Service\WebhookService') {
					return $this->webhooks;
				}

				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		$mailer = $this->createMock(IMailer::class);
		$message = $this->createMock(IMessage::class);
		$captured = ['to' => '', 'subject' => '', 'body' => ''];
		$message->method('setTo')->willReturnCallback(
			function (array $to) use (&$captured, $message) {
				$captured['to'] = $to[0] ?? '';
				return $message;
			}
		);
		$message->method('setSubject')->willReturnCallback(
			function (string $subject) use (&$captured, $message) {
				$captured['subject'] = $subject;
				return $message;
			}
		);
		$message->method('setPlainBody')->willReturnCallback(
			function (string $body) use (&$captured, $message) {
				$captured['body'] = $body;
				return $message;
			}
		);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturnCallback(
			function () use (&$captured): array {
				$this->emailsSent[] = $captured;
				return [];
			}
		);

		$this->service = new PosBookkeepingService(
			$container,
			$this->appConfig,
			$mailer,
			$policy,
			$this->createMock(LoggerInterface::class),
			objectService: $key,
		);
	}//end setUp()

	/**
	 * Make a UID a Nextcloud admin (so the manager predicate passes).
	 */
	private function asAdmin(string $uid = 'boss'): void {
		$this->admins[] = $uid;
	}//end asAdmin()

	/**
	 * Seed two confirmed transactions on the same date.
	 */
	private function seedTransactions(): void {
		$this->objects->store['posTransaction_schema']['txn-1'] = [
			'id' => 'txn-1',
			'status' => 'confirmed',
			'terminalId' => 'kassa-01',
			'subtotal' => 100.00,
			'discountTotal' => 0.0,
			'totalTax' => 9.00,
			'total' => 109.00,
			'taxBreakdown' => [['rate' => 9, 'base' => 100.00, 'tax' => 9.00]],
			'paymentMethod' => 'cash',
			'settledAt' => '2026-05-20T12:00:00+00:00',
		];
		$this->objects->store['posTransaction_schema']['txn-2'] = [
			'id' => 'txn-2',
			'status' => 'settled',
			'terminalId' => 'kassa-01',
			'subtotal' => 200.00,
			'discountTotal' => 0.0,
			'totalTax' => 42.00,
			'total' => 242.00,
			'taxBreakdown' => [['rate' => 21, 'base' => 200.00, 'tax' => 42.00]],
			'paymentMethod' => 'card',
			'settledAt' => '2026-05-20T15:00:00+00:00',
		];
	}//end seedTransactions()

	/**
	 * Seed a ready Z-report (pending bookkeeping) for the journal raise.
	 *
	 * @return string The Z-report id.
	 */
	private function seedZReport(): string {
		$this->objects->store['posZReport_schema']['zr-1'] = [
			'id' => 'zr-1',
			'reference' => 'Z-2026-05-20-KAS01',
			'reportDate' => '2026-05-20',
			'terminalId' => 'kassa-01',
			'transactionCount' => 2,
			'subtotal' => 300.00,
			'totalTax' => 51.00,
			'total' => 351.00,
			'taxBreakdown' => [
				['rate' => 9,  'base' => 100.00, 'tax' => 9.00],
				['rate' => 21, 'base' => 200.00, 'tax' => 42.00],
			],
			'status' => 'ready',
			'bookkeepingStatus' => 'pending',
		];
		return 'zr-1';
	}//end seedZReport()

	/**
	 * generateZReport aggregates the day's confirmed/settled transactions and
	 * opens the bookkeeping projection as pending.
	 *
	 * @return void
	 */
	public function testGenerateZReportAggregatesConfirmedAndSettled(): void {
		$this->seedTransactions();

		$report = $this->service->generateZReport(reportDate: '2026-05-20', terminalId: 'kassa-01');

		$this->assertSame(2, $report['transactionCount']);
		$this->assertSame(300.00, $report['subtotal']);
		$this->assertSame(51.00, $report['totalTax']);
		$this->assertSame(351.00, $report['total']);
		$this->assertSame('ready', $report['status']);
		$this->assertSame('pending', $report['bookkeepingStatus']);
		$this->assertSame('Z-2026-05-20-KASSA-01', $report['reference']);

		// PosZReport.submitted CloudEvent emitted.
		$this->assertNotEmpty($this->webhooks->events);
		$this->assertSame(PosBookkeepingService::EVENT_ZREPORT_SUBMITTED, $this->webhooks->events[0]['eventName']);
	}//end testGenerateZReportAggregatesConfirmedAndSettled()

	/**
	 * generateZReport produces a draft zero-value report when no transactions exist.
	 *
	 * @return void
	 */
	public function testGenerateZReportEmptyDayProducesDraft(): void {
		$report = $this->service->generateZReport(reportDate: '2026-05-23');

		$this->assertSame(0, $report['transactionCount']);
		$this->assertSame(0.0, $report['total']);
		$this->assertSame('draft', $report['status']);
	}//end testGenerateZReportEmptyDayProducesDraft()

	/**
	 * computeIdempotencyKey is deterministic for the same Z-report and date.
	 *
	 * @return void
	 */
	public function testComputeIdempotencyKeyIsDeterministicAndUnique(): void {
		$a = ['id' => 'zr-1', 'reportDate' => '2026-05-20'];
		$b = ['id' => 'zr-1', 'reportDate' => '2026-05-20'];
		$c = ['id' => 'zr-2', 'reportDate' => '2026-05-20'];

		$keyA = $this->service->computeIdempotencyKey($a);
		$keyB = $this->service->computeIdempotencyKey($b);
		$keyC = $this->service->computeIdempotencyKey($c);

		$this->assertSame($keyA, $keyB, 'idempotency key MUST be deterministic for same input');
		$this->assertNotSame($keyA, $keyC, 'different Z-reports MUST produce different keys');
		$this->assertStringStartsWith('sha256:', $keyA);
	}//end testComputeIdempotencyKeyIsDeterministicAndUnique()

	/**
	 * raiseJournalEntry fails closed for a non-manager.
	 *
	 * @return void
	 */
	public function testRaiseJournalEntryRequiresManager(): void {
		$this->seedZReport();

		$this->expectException(OCSForbiddenException::class);
		$this->service->raiseJournalEntry('zr-1', 'clerk');
	}//end testRaiseJournalEntryRequiresManager()

	/**
	 * A ready raise dispatches the registry message and projects raised.
	 *
	 * @return void
	 */
	public function testRaiseDispatchesRegistryMessageAndProjectsRaised(): void {
		$this->asAdmin();
		$this->seedZReport();

		$result = $this->service->raiseJournalEntry('zr-1', 'boss');

		$this->assertSame('raised', $result['bookkeepingStatus']);
		$this->assertNotEmpty($result['shillinqJournalEntryId']);

		// Registry message dispatched with business facts (no GL lines).
		$eventNames = array_column($this->webhooks->events, 'eventName');
		$this->assertContains(PosBookkeepingService::EVENT_JOURNAL_RAISE, $eventNames);
		$raise = $this->webhooks->events[count($this->webhooks->events) - 1];
		$this->assertSame(PosBookkeepingService::EVENT_JOURNAL_RAISE, $raise['eventName']);
		$this->assertArrayNotHasKey('ledgerLineItems', $raise['payload']['data']);
		$this->assertArrayHasKey('taxBreakdown', $raise['payload']['data']);

		// Idempotency key is the deterministic SHA256(zReport.uuid + reportDate).
		$expectedKey = $this->service->computeIdempotencyKey(['id' => 'zr-1', 'reportDate' => '2026-05-20']);
		$this->assertSame($expectedKey, $raise['payload']['data']['idempotencyKey']);
		$this->assertSame($expectedKey, $result['shillinqJournalEntryId']);
	}//end testRaiseDispatchesRegistryMessageAndProjectsRaised()

	/**
	 * A re-raise reuses the identical idempotency key (no double booking).
	 *
	 * @return void
	 */
	public function testReRaisePreservesIdempotencyKey(): void {
		$this->asAdmin();
		$this->seedZReport();

		$first = $this->service->raiseJournalEntry('zr-1', 'boss');
		$second = $this->service->raiseJournalEntry('zr-1', 'boss');

		$this->assertSame($first['shillinqJournalEntryId'], $second['shillinqJournalEntryId']);

		$keys = array_map(
			fn (array $e): string => (string)($e['payload']['data']['idempotencyKey'] ?? ''),
			array_filter(
				$this->webhooks->events,
				fn (array $e): bool => $e['eventName'] === PosBookkeepingService::EVENT_JOURNAL_RAISE
			)
		);
		$this->assertCount(2, $keys);
		$this->assertSame($keys[0], $keys[array_key_last($keys)]);
	}//end testReRaisePreservesIdempotencyKey()

	/**
	 * An unconfigured integration leaves the projection pending (POS day closes).
	 *
	 * @return void
	 */
	public function testUnconfiguredIntegrationLeavesPending(): void {
		$this->asAdmin();
		$this->appConfigStore['shillinq_journal_webhook_url'] = '';
		$this->seedZReport();

		$result = $this->service->raiseJournalEntry('zr-1', 'boss');

		$this->assertSame('pending', $result['bookkeepingStatus']);
		$this->assertEmpty(
			array_filter(
				$this->webhooks->events,
				fn (array $e): bool => $e['eventName'] === PosBookkeepingService::EVENT_JOURNAL_RAISE
			)
		);
	}//end testUnconfiguredIntegrationLeavesPending()

	/**
	 * A failed dispatch below the cap stays pending for retry.
	 *
	 * @return void
	 */
	public function testTransientDispatchFailureStaysPending(): void {
		$this->asAdmin();
		$this->webhooks->fail = true;
		$this->seedZReport();

		$result = $this->service->raiseJournalEntry('zr-1', 'boss');

		$this->assertSame('pending', $result['bookkeepingStatus']);
		$this->assertSame(1, $result['bookkeepingAttempts']);
		$this->assertEmpty($this->emailsSent);
	}//end testTransientDispatchFailureStaysPending()

	/**
	 * Reaching the max attempts flips the projection to failed and alerts.
	 *
	 * @return void
	 */
	public function testMaxAttemptsBecomesFailedWithAlert(): void {
		$this->asAdmin();
		$this->webhooks->fail = true;
		$this->seedZReport();
		// Pre-stamp 4 prior attempts so this 5th attempt hits the cap.
		$this->objects->store['posZReport_schema']['zr-1']['bookkeepingAttempts'] = 4;

		$result = $this->service->raiseJournalEntry('zr-1', 'boss');

		$this->assertSame('failed', $result['bookkeepingStatus']);
		$this->assertSame(5, $result['bookkeepingAttempts']);
		$this->assertNotEmpty($this->emailsSent, 'alert sent on max-attempts');
		$this->assertSame('accounting@example.org', $this->emailsSent[0]['to']);
	}//end testMaxAttemptsBecomesFailedWithAlert()

	/**
	 * shouldDispatch only enables on a valid HTTPS webhook URL.
	 *
	 * @return void
	 */
	public function testShouldDispatchRequiresHttpsUrl(): void {
		$this->assertTrue($this->service->shouldDispatch());

		$this->appConfigStore['shillinq_journal_webhook_url'] = 'http://insecure.example.org';
		$this->assertFalse($this->service->shouldDispatch());

		$this->appConfigStore['shillinq_journal_webhook_url'] = '';
		$this->assertFalse($this->service->shouldDispatch());
	}//end testShouldDispatchRequiresHttpsUrl()
}//end class
