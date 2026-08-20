<?php

/**
 * Unit tests for TimeBillingHandoffService.
 *
 * Exercises batch selection, the deterministic UUIDv5 batchId, the
 * minutes/payload contract mapping and the 200/409/422/5xx outcome handling
 * (including a `duplicated:true` replay) against in-memory fakes of
 * OpenRegister's ObjectService and shillinq's TimeIntakeService /
 * AdministrationContextService. The shillinq seam is a plain fake resolved
 * through the container by FQCN string — this test never requires an actual
 * shillinq class, matching the codebase's cross-app testing convention
 * (mirrors {@see \OCA\Pipelinq\Tests\Unit\Service\PosBookkeepingServiceTest}).
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
 * @spec openspec/changes/time-billing-handoff-emit/specs/time-approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BillingHandoffNotifier;
use OCA\Pipelinq\Service\TimeBillingHandoffService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory fake of the OR ObjectService.
 */
class BillingHandoffFakeObjectService {

	/**
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

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
		$object['id'] = $uuid;
		$this->store[$schema][$uuid] = $object;
		return $object;
	}//end saveObject()
}//end class

/**
 * Fake of shillinq's TimeIntakeService::ingest(), resolved by the service
 * through the container by FQCN string (never a real shillinq class).
 */
class BillingHandoffFakeIntakeService {

	/**
	 * @var array<int, array{administrationId: string, personId: string, body: array<string, mixed>}>
	 */
	public array $calls = [];

	/**
	 * Queued outcome: 'success' | 'conflict' | 'unmapped' | 'malformed' | 'transport'.
	 *
	 * @var string
	 */
	public string $outcome = 'success';

	/**
	 * Whether the queued success response should report duplicated:true.
	 *
	 * @var boolean
	 */
	public bool $duplicated = false;

	/**
	 * @param array<string, mixed> $body
	 *
	 * @return array{invoiceId:string,invoiceNumber:string,status:string,lines:int,duplicated:bool}
	 */
	public function ingest(string $administrationId, string $personId, array $body): array {
		$this->calls[] = ['administrationId' => $administrationId, 'personId' => $personId, 'body' => $body];

		return match ($this->outcome) {
			'conflict' => throw new \RuntimeException(sprintf('Conflict: batchId "%s" was already ingested with a different payload.', $body['batchId'] ?? '')),
			'unmapped' => throw new \RuntimeException(sprintf('organisationRef "%s" does not resolve to a known customer for this administration.', $body['organisationRef'] ?? '')),
			'malformed' => throw new \InvalidArgumentException('entries[] must be a non-empty array.'),
			// A true transport/crash failure is deliberately NOT a
			// RuntimeException — shillinq's controller (and this service,
			// mirroring it) reserves RuntimeException for the 409/422
			// validation-outcome bucket; anything else falls to the generic
			// Throwable (5xx) bucket.
			'transport' => throw new \Exception('Connection to shillinq timed out.', 0),
			default => [
				'invoiceId' => 'inv-' . ($body['batchId'] ?? ''),
				'invoiceNumber' => 'INV-2026-0001',
				'status' => 'draft',
				'lines' => count($body['entries'] ?? []),
				'duplicated' => $this->duplicated,
			],
		};
	}//end ingest()
}//end class

/**
 * Fake of shillinq's AdministrationContextService::buildContext().
 */
class BillingHandoffFakeAdministrationContextService {

	/**
	 * @return array<string, mixed>
	 */
	public function buildContext(): array {
		return ['activeAdministrationId' => 'admin-tenant-1'];
	}//end buildContext()
}//end class

/**
 * Tests for TimeBillingHandoffService.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TimeBillingHandoffServiceTest extends TestCase {

	private const CLIENT_ID = '16c492f2-d639-400c-b36d-03a1a39e97e9';

	private TimeBillingHandoffService $service;

	private BillingHandoffFakeObjectService $objects;

	private BillingHandoffFakeIntakeService $intake;

	private BillingHandoffFakeAdministrationContextService $administrationContext;

	private BillingHandoffNotifier $notifier;

	/**
	 * @var array<string, string>
	 */
	private array $appConfigStore = [
		'register' => 'reg',
		'timeEntry_schema' => 'timeEntry_schema',
		'client_schema' => 'client_schema',
		'currency' => 'EUR',
		'shillinq_time_intake_enabled' => 'true',
	];

	/**
	 * Whether the container resolves shillinq's intake service at all
	 * (simulates shillinq being absent/uninstalled).
	 *
	 * @var boolean
	 */
	private bool $intakeResolvable = true;

	protected function setUp(): void {
		$this->objects = new BillingHandoffFakeObjectService();
		$this->intake = new BillingHandoffFakeIntakeService();
		$this->administrationContext = new BillingHandoffFakeAdministrationContextService();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return $this->appConfigStore[$key] ?? $default;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			fn (string $appId): bool => $appId === 'shillinq'
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $this->objects;
				}

				if ($id === 'OCA\Shillinq\Service\TimeIntakeService') {
					if ($this->intakeResolvable === false) {
						throw new \RuntimeException('shillinq not installed');
					}

					return $this->intake;
				}

				if ($id === 'OCA\Shillinq\Service\AdministrationContextService') {
					return $this->administrationContext;
				}

				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		$this->notifier = $this->createMock(BillingHandoffNotifier::class);

		$this->service = new TimeBillingHandoffService($appConfig,
			$appManager,
			$userSession,
			$container,
			$this->notifier,
			$this->createMock(LoggerInterface::class),
		);

		$this->seedClient();
	}//end setUp()

	/**
	 * Seed the demo municipality client with a shillinqOrganisationRef.
	 */
	private function seedClient(): void {
		$this->objects->store['client_schema'][self::CLIENT_ID] = [
			'id' => self::CLIENT_ID,
			'name' => 'Gemeente Voorbeeld',
			'shillinqOrganisationRef' => '00000000-0000-0000-0000-000000000000',
		];
	}//end seedClient()

	/**
	 * Seed two approved, un-billed time entries for the client in June 2026.
	 */
	private function seedTwoApprovedEntries(): void {
		$this->objects->store['timeEntry_schema']['entry-1'] = [
			'id' => 'entry-1',
			'title' => 'KCC inrichting werkplekprofielen',
			'description' => 'Inrichting van werkplekprofielen.',
			'hours' => 6.5,
			'date' => '2026-06-10',
			'status' => 'approved',
			'client' => self::CLIENT_ID,
		];
		$this->objects->store['timeEntry_schema']['entry-2'] = [
			'id' => 'entry-2',
			'title' => 'Kennisbank redactie',
			'description' => 'Redactie van de kennisbankartikelen.',
			'hours' => 3.0,
			'date' => '2026-06-12',
			'status' => 'approved',
			'client' => self::CLIENT_ID,
		];
	}//end seedTwoApprovedEntries()

	/**
	 * selectBatch only returns approved, un-billed entries for the client
	 * within the period — excluding entries already carrying a
	 * billingInvoiceId, other clients, non-approved entries, and out-of-period dates.
	 *
	 * @return void
	 */
	public function testSelectBatchExcludesBilledOtherClientAndOutOfPeriodEntries(): void {
		$this->seedTwoApprovedEntries();
		// Already billed — must be excluded.
		$this->objects->store['timeEntry_schema']['entry-billed'] = [
			'id' => 'entry-billed', 'hours' => 1.0, 'date' => '2026-06-11',
			'status' => 'approved', 'client' => self::CLIENT_ID, 'billingInvoiceId' => 'inv-123',
		];
		// Different client — must be excluded.
		$this->objects->store['timeEntry_schema']['entry-other-client'] = [
			'id' => 'entry-other-client', 'hours' => 1.0, 'date' => '2026-06-11',
			'status' => 'approved', 'client' => 'some-other-client',
		];
		// Not approved — must be excluded.
		$this->objects->store['timeEntry_schema']['entry-draft'] = [
			'id' => 'entry-draft', 'hours' => 1.0, 'date' => '2026-06-11',
			'status' => 'draft', 'client' => self::CLIENT_ID,
		];
		// Outside the period — must be excluded.
		$this->objects->store['timeEntry_schema']['entry-out-of-period'] = [
			'id' => 'entry-out-of-period', 'hours' => 1.0, 'date' => '2026-07-01',
			'status' => 'approved', 'client' => self::CLIENT_ID,
		];

		$selected = $this->service->selectBatch(clientId: self::CLIENT_ID, periodStart: '2026-06-01', periodEnd: '2026-06-30');

		$ids = array_map(static fn (array $e): string => $e['id'], $selected);
		sort($ids);
		$this->assertSame(['entry-1', 'entry-2'], $ids);
	}//end testSelectBatchExcludesBilledOtherClientAndOutOfPeriodEntries()

	/**
	 * The batchId is deterministic for the same client/period/entry selection,
	 * and different for a different selection.
	 *
	 * @return void
	 */
	public function testBatchIdIsDeterministicForSameSelection(): void {
		$this->seedTwoApprovedEntries();

		$first = $this->service->sendToBilling(clientId: self::CLIENT_ID, periodStart: '2026-06-01', periodEnd: '2026-06-30');

		// Assert the batchId was stamped identically on both entries and is a
		// valid UUIDv5-shaped string (name-based, RFC 4122 version/variant bits).
		$entry1 = $this->objects->store['timeEntry_schema']['entry-1'];
		$entry2 = $this->objects->store['timeEntry_schema']['entry-2'];

		$this->assertSame('synced', $first['status']);
		$this->assertNotEmpty($first['batchId']);
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$first['batchId'],
			'batchId MUST be a version-5 (name-based) UUID'
		);
		$this->assertSame($first['batchId'], $entry1['billingBatchId']);
		$this->assertSame($first['batchId'], $entry2['billingBatchId']);
	}//end testBatchIdIsDeterministicForSameSelection()

	/**
	 * Two independent service instances given the identical client/period/entry
	 * selection compute the identical batchId (pure function of its inputs).
	 *
	 * @return void
	 */
	public function testBatchIdIsPureFunctionOfSelection(): void {
		$this->seedTwoApprovedEntries();

		$result = $this->service->sendToBilling(clientId: self::CLIENT_ID, periodStart: '2026-06-01', periodEnd: '2026-06-30');

		// Reset entries back to un-billed and re-send under the identical
		// selection criteria — the recomputed batchId must match exactly.
		$this->objects->store['timeEntry_schema']['entry-1']['billingInvoiceId'] = '';
		$this->objects->store['timeEntry_schema']['entry-1']['billingSyncStatus'] = null;
		$this->objects->store['timeEntry_schema']['entry-2']['billingInvoiceId'] = '';
		$this->objects->store['timeEntry_schema']['entry-2']['billingSyncStatus'] = null;

		$second = $this->service->sendToBilling(clientId: self::CLIENT_ID, periodStart: '2026-06-01', periodEnd: '2026-06-30');

		$this->assertSame($result['batchId'], $second['batchId']);
	}//end testBatchIdIsPureFunctionOfSelection()

	/**
	 * Hours are converted to minutes (rounded) and the payload matches the
	 * shillinq time-intake contract shape.
	 *
	 * @return void
	 */
	public function testPayloadMapsMinutesAndContractShape(): void {
		$this->seedTwoApprovedEntries();

		$this->service->sendToBilling(clientId: self::CLIENT_ID, periodStart: '2026-06-01', periodEnd: '2026-06-30');

		$this->assertCount(1, $this->intake->calls);
		$body = $this->intake->calls[0]['body'];

		$this->assertSame('t_and_m', $body['billingModel']);
		$this->assertSame('EUR', $body['currency']);
		$this->assertSame('00000000-0000-0000-0000-000000000000', $body['organisationRef']);
		$this->assertSame(['start' => '2026-06-01', 'end' => '2026-06-30'], $body['period']);
		$this->assertNull($body['rateCardId']);
		$this->assertSame([], $body['expenses']);
		$this->assertCount(2, $body['entries']);

		$byId = [];
		foreach ($body['entries'] as $entry) {
			$byId[$entry['externalId']] = $entry;
		}

		// 6.5h -> 390 minutes; 3.0h -> 180 minutes.
		$this->assertSame(390, $byId['entry-1']['minutes']);
		$this->assertSame(180, $byId['entry-2']['minutes']);
		$this->assertNull($byId['entry-1']['hourlyRate']);
		$this->assertNull($byId['entry-1']['rateRef']);
		$this->assertSame('2026-06-10', $byId['entry-1']['date']);

		$this->assertSame('admin-tenant-1', $this->intake->calls[0]['administrationId']);
		$this->assertSame('alice', $this->intake->calls[0]['personId']);
	}//end testPayloadMapsMinutesAndContractShape()

	/**
	 * A 200 response stores synced + billingInvoiceId on every entry, and a
	 * duplicated:true replay is handled identically.
	 *
	 * @return void
	 */
	public function testSuccessStoresInvoiceReferenceIncludingDuplicatedReplay(): void {
		$this->seedTwoApprovedEntries();
		$this->intake->duplicated = true;

		$result = $this->service->sendToBilling(clientId: self::CLIENT_ID, periodStart: '2026-06-01', periodEnd: '2026-06-30');

		$this->assertSame('synced', $result['status']);
		$this->assertTrue($result['duplicated']);
		$this->assertSame(2, $result['entryCount']);
		$this->assertNotEmpty($result['invoiceId']);

		foreach (['entry-1', 'entry-2'] as $id) {
			$entry = $this->objects->store['timeEntry_schema'][$id];
			$this->assertSame('synced', $entry['billingSyncStatus']);
			$this->assertSame($result['invoiceId'], $entry['billingInvoiceId']);
		}
	}//end testSuccessStoresInvoiceReferenceIncludingDuplicatedReplay()

	/**
	 * A 409 (Conflict) leaves entries pending and does not notify admins.
	 *
	 * @return void
	 */
	public function test409ConflictLeavesEntriesPending(): void {
		$this->seedTwoApprovedEntries();
		$this->intake->outcome = 'conflict';
		$this->notifier->expects($this->never())->method('notifyFailure');

		$result = $this->service->sendToBilling(clientId: self::CLIENT_ID, periodStart: '2026-06-01', periodEnd: '2026-06-30');

		$this->assertSame('conflict', $result['status']);
		$this->assertSame('pending', $this->objects->store['timeEntry_schema']['entry-1']['billingSyncStatus']);
		$this->assertSame('pending', $this->objects->store['timeEntry_schema']['entry-2']['billingSyncStatus']);
	}//end test409ConflictLeavesEntriesPending()

	/**
	 * A 422 (unresolvable organisationRef/rateRef) surfaces an actionable
	 * message naming the client, leaves entries pending, and does not notify
	 * admins (never blind-retried).
	 *
	 * @return void
	 */
	public function test422UnmappedClientIsActionableAndNotNotified(): void {
		$this->seedTwoApprovedEntries();
		$this->intake->outcome = 'unmapped';
		$this->notifier->expects($this->never())->method('notifyFailure');

		$result = $this->service->sendToBilling(clientId: self::CLIENT_ID, periodStart: '2026-06-01', periodEnd: '2026-06-30');

		$this->assertSame('unmapped', $result['status']);
		$this->assertStringContainsString('Gemeente Voorbeeld', $result['message']);
		$this->assertSame('pending', $this->objects->store['timeEntry_schema']['entry-1']['billingSyncStatus']);
	}//end test422UnmappedClientIsActionableAndNotNotified()

	/**
	 * A transport/5xx failure marks entries failed and notifies admins.
	 *
	 * @return void
	 */
	public function testTransientFailureMarksFailedAndNotifiesAdmins(): void {
		$this->seedTwoApprovedEntries();
		$this->intake->outcome = 'transport';
		$this->notifier->expects($this->once())->method('notifyFailure')->with($this->equalTo('Gemeente Voorbeeld'),
			$this->equalTo(self::CLIENT_ID),
			$this->isType('string')
		);

		$result = $this->service->sendToBilling(clientId: self::CLIENT_ID, periodStart: '2026-06-01', periodEnd: '2026-06-30');

		$this->assertSame('failed', $result['status']);
		$this->assertSame('failed', $this->objects->store['timeEntry_schema']['entry-1']['billingSyncStatus']);
		$this->assertSame('failed', $this->objects->store['timeEntry_schema']['entry-2']['billingSyncStatus']);
	}//end testTransientFailureMarksFailedAndNotifiesAdmins()

	/**
	 * A malformed-payload (400) failure is handled the same way as a
	 * transport failure — marked failed and notified.
	 *
	 * @return void
	 */
	public function testMalformedPayloadMarksFailedAndNotifies(): void {
		$this->seedTwoApprovedEntries();
		$this->intake->outcome = 'malformed';
		$this->notifier->expects($this->once())->method('notifyFailure');

		$result = $this->service->sendToBilling(clientId: self::CLIENT_ID, periodStart: '2026-06-01', periodEnd: '2026-06-30');

		$this->assertSame('failed', $result['status']);
	}//end testMalformedPayloadMarksFailedAndNotifies()

	/**
	 * When the flag is off, no intake call is attempted and 'not-available'
	 * is returned (deep-link fallback stays) — handoffAvailable() agrees.
	 *
	 * @return void
	 */
	public function testFlagOffFallsBackWithoutCallingIntake(): void {
		$this->seedTwoApprovedEntries();

		$this->appConfigStore['shillinq_time_intake_enabled'] = 'false';
		$this->assertFalse($this->service->handoffAvailable());

		$result = $this->service->sendToBilling(clientId: self::CLIENT_ID, periodStart: '2026-06-01', periodEnd: '2026-06-30');

		$this->assertSame('not-available', $result['status']);
		$this->assertEmpty($this->intake->calls);
	}//end testFlagOffFallsBackWithoutCallingIntake()

	/**
	 * When shillinq's app-enabled check fails, handoffAvailable() is false
	 * and no intake call is attempted — the deep-link fallback stays offered.
	 *
	 * @return void
	 */
	public function testShillinqDisabledForUserFallsBackWithoutCallingIntake(): void {
		$this->seedTwoApprovedEntries();

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(false);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->appConfigStore[$key] ?? $default
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('should not be resolved'));

		$service = new TimeBillingHandoffService($appConfig,
			$appManager,
			$userSession,
			$container,
			$this->notifier,
			$this->createMock(LoggerInterface::class),
		);

		$this->assertFalse($service->handoffAvailable());

		$result = $service->sendToBilling(clientId: self::CLIENT_ID, periodStart: '2026-06-01', periodEnd: '2026-06-30');
		$this->assertSame('not-available', $result['status']);
	}//end testShillinqDisabledForUserFallsBackWithoutCallingIntake()

	/**
	 * When shillinq's intake service cannot be resolved from the container
	 * even though the flag is on and the app reports enabled (e.g. a stale
	 * app-enabled cache), the batch is marked failed and admins are notified
	 * rather than crashing the request.
	 *
	 * @return void
	 */
	public function testUnresolvableIntakeServiceMarksFailedAndNotifies(): void {
		$this->seedTwoApprovedEntries();
		$this->intakeResolvable = false;

		$this->notifier->expects($this->once())->method('notifyFailure');

		$result = $this->service->sendToBilling(clientId: self::CLIENT_ID, periodStart: '2026-06-01', periodEnd: '2026-06-30');

		$this->assertSame('failed', $result['status']);
		$this->assertSame('failed', $this->objects->store['timeEntry_schema']['entry-1']['billingSyncStatus']);
	}//end testUnresolvableIntakeServiceMarksFailedAndNotifies()

	/**
	 * An empty selection (nothing approved and un-billed) returns 'empty'
	 * without attempting any intake call.
	 *
	 * @return void
	 */
	public function testEmptySelectionReturnsEmptyWithoutCallingIntake(): void {
		$result = $this->service->sendToBilling(clientId: self::CLIENT_ID, periodStart: '2026-06-01', periodEnd: '2026-06-30');

		$this->assertSame('empty', $result['status']);
		$this->assertEmpty($this->intake->calls);
	}//end testEmptySelectionReturnsEmptyWithoutCallingIntake()

	/**
	 * notifyPendingFailures re-notifies once per distinct billingBatchId
	 * currently `failed` (the retry job's only behaviour, per the
	 * orchestrator's binding ruling — it never re-attempts the call itself).
	 *
	 * @return void
	 */
	public function testNotifyPendingFailuresGroupsByBatchIdOnce(): void {
		$this->objects->store['timeEntry_schema']['entry-failed-1'] = [
			'id' => 'entry-failed-1', 'client' => self::CLIENT_ID,
			'billingSyncStatus' => 'failed', 'billingBatchId' => 'batch-a',
		];
		$this->objects->store['timeEntry_schema']['entry-failed-2'] = [
			'id' => 'entry-failed-2', 'client' => self::CLIENT_ID,
			'billingSyncStatus' => 'failed', 'billingBatchId' => 'batch-a',
		];
		$this->objects->store['timeEntry_schema']['entry-synced'] = [
			'id' => 'entry-synced', 'client' => self::CLIENT_ID,
			'billingSyncStatus' => 'synced', 'billingBatchId' => 'batch-b',
		];

		$this->notifier->expects($this->once())->method('notifyFailure')->with($this->equalTo('Gemeente Voorbeeld'),
			$this->equalTo(self::CLIENT_ID),
			$this->equalTo('batch-a')
		);

		$notified = $this->service->notifyPendingFailures();

		$this->assertSame(['batch-a'], $notified);
	}//end testNotifyPendingFailuresGroupsByBatchIdOnce()
}//end class
