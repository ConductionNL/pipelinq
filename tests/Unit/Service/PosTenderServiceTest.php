<?php

/**
 * Unit tests for PosTenderService.
 *
 * Exercises the tender-domain business rules (REQ-PST-001..006) against an
 * in-memory ObjectService fake: tender-type CRUD with code-uniqueness +
 * active-reference guards, per-transaction tender add / remove / list with
 * the settled-state invariant, server-authoritative tender-sum validation
 * with the change-tender overpayment rule, the cash-change calculation
 * helper, and the CloudEvent emission path which must persist the event-id
 * and increment glPostAttempts on the tender.
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
 * @spec openspec/changes/pos-split-tender/tasks.md#10.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\InvalidTenderException;
use OCA\Pipelinq\Service\PosTenderService;
use OCA\Pipelinq\Service\TenderTypeNotFoundException;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory fake of the OR ObjectService for the tender tests.
 *
 * Keyed by schema then by uuid; saveObject auto-assigns a uuid when empty.
 *
 * ⚠️ This fake models OpenRegister's REGISTER/SCHEMA CONTEXT RESOLUTION, not
 * just its method names, because that resolution is what pipelinq#793 / #799
 * are about. The previous version resolved the schema from
 * `$config['schema']` — the TOP-LEVEL key — which is precisely the key the
 * real `ObjectService::prepareFindAllConfig()` never reads. It was therefore
 * shaped to the (broken) caller rather than to the collaborator, and it kept
 * this suite green over a live outage.
 *
 * The rules mirrored here, from `openregister@a4dd9067`:
 *   - `findAll()` resolves context ONLY from `$config['filters']['register']`
 *     and `['schema']` (`ObjectService::prepareFindAllConfig()` :1011-1035);
 *     it then passes `$this->currentRegister` / `$this->currentSchema` to the
 *     handler and NEVER restores them.
 *   - With either still null, `MagicMapper::findAll()` (:8681) logs a warning
 *     and returns `[]` — no exception.
 *   - `saveObject()` sets the sticky context and does not restore it, so a
 *     preceding write makes a later mis-keyed read appear to work.
 *   - `find()` snapshots and restores the sticky context (BUG-OBJ-13 /
 *     openregister#1520), so it leaves nothing behind for a later read.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mirrors the real ObjectService signature.
 */
class TenderFakeObjectService {

	/**
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * @var integer
	 */
	private int $seq = 0;

	/**
	 * @var bool
	 */
	public bool $throwOnSave = false;

	/**
	 * Sticky register context, as left behind by the last call that set it.
	 *
	 * @var string
	 */
	public string $currentRegister = '';

	/**
	 * Sticky schema context, as left behind by the last call that set it.
	 *
	 * @var string
	 */
	public string $currentSchema = '';

	/**
	 * Every findAll() config, in call order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $queries = [];

	/**
	 * Opt-in switch that makes the fake ALSO honour a register/schema supplied
	 * at the TOP LEVEL of the config array. Real OpenRegister does not.
	 *
	 * ⚠️ It exists for exactly one reason: `PosTenderService` still has three
	 * mis-keyed `findAll()` sites that pipelinq#793 sequences behind guards
	 * this change deliberately does not build —
	 *   `listTenderTypes()`      (:155, flips the endpoint permissive→rejecting)
	 *   `listUnpostedTenders()`  (:828, unbounded whole-table read on a 5-min
	 *                             cron, one outbound GL CloudEvent per row)
	 *   `countTendersForType()`  (:921, makes any ever-used tender type
	 *                             permanently undeletable)
	 * A test that sets this flag is therefore declaring "the site under me is
	 * a KNOWN-BROKEN #793 site"; it still asserts the domain rule around it.
	 * When those three are repaired the flag comes off and the tests must
	 * still pass. Any NEW usage is a bug being pinned — do not add one.
	 *
	 * @var boolean
	 */
	public bool $acceptTopLevelContext = false;

	/**
	 * Read one object. Snapshots and restores the sticky context, exactly as
	 * `ObjectService::find()` does — so it leaves nothing behind for a later
	 * mis-keyed `findAll()` to inherit.
	 *
	 * @param integer|string $id Object id.
	 * @param array|null $_extend Extend list.
	 * @param boolean $files Include files.
	 * @param string|int|null $register Register context.
	 * @param string|int|null $schema Schema context.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(
		int|string $id,
		?array $_extend = [],
		bool $files = false,
		string|int|null $register = null,
		string|int|null $schema = null,
	): ?array {
		$snapshotRegister = $this->currentRegister;
		$snapshotSchema = $this->currentSchema;

		$row = ($this->store[(string)$schema][(string)$id] ?? null);

		$this->currentRegister = $snapshotRegister;
		$this->currentSchema = $snapshotSchema;

		return $row;
	}//end find()

	/**
	 * Query objects. Context comes from `filters` only; anything left in the
	 * sticky properties by a previous call is inherited.
	 *
	 * @param array<string, mixed> $config Query config.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config): array {
		$this->queries[] = $config;

		$filters = (array)($config['filters'] ?? []);

		// prepareFindAllConfig(): filters ONLY, and only when non-empty.
		if (empty($filters['register']) === false && is_array($filters['register']) === false) {
			$this->currentRegister = (string)$filters['register'];
		}

		if (empty($filters['schema']) === false && is_array($filters['schema']) === false) {
			$this->currentSchema = (string)$filters['schema'];
		}

		if ($this->acceptTopLevelContext === true) {
			if (empty($config['register']) === false) {
				$this->currentRegister = (string)$config['register'];
			}

			if (empty($config['schema']) === false) {
				$this->currentSchema = (string)$config['schema'];
			}
		}

		if ($this->currentRegister === '' || $this->currentSchema === '') {
			// MagicMapper::findAll(): warning + empty list, never an exception.
			return [];
		}

		$rows = array_values($this->store[$this->currentSchema] ?? []);

		// register/schema are context, not property filters.
		unset($filters['register'], $filters['schema']);

		if ($filters === []) {
			return $rows;
		}

		return array_values(
			array_filter($rows,
				function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end findAll()

	/**
	 * Write an object. Sets the sticky context and does NOT restore it.
	 *
	 * @param array<string, mixed> $object The payload.
	 * @param array|null $extend Extend list.
	 * @param string|int|null $register Register context.
	 * @param string|int|null $schema Schema context.
	 * @param string|null $uuid Uuid for an update.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
	): array {
		if ($this->throwOnSave === true) {
			throw new \RuntimeException('fake save error');
		}

		$register = (string)$register;
		$schema = (string)$schema;

		$this->currentRegister = $register;
		$this->currentSchema = $schema;

		if ((string)$uuid === '') {
			$this->seq++;
			$uuid = $schema . '-' . $this->seq;
		}

		$object['id'] = $uuid;
		$this->store[$schema][$uuid] = $object;
		return $object;
	}//end saveObject()

	/**
	 * Delete an object. The real parameter is `$uuid`; a caller spelling it
	 * `id:` gets `Error: Unknown named parameter $id`, which is what
	 * `deleteTenderType()` and `removeTender()` shipped with.
	 *
	 * @param string $uuid The object uuid.
	 * @param string|int|null $register Register context.
	 * @param string|int|null $schema Schema context.
	 *
	 * @return void
	 */
	public function deleteObject(string $uuid, string|int|null $register = null, string|int|null $schema = null): void {
		$this->currentRegister = (string)$register;
		$this->currentSchema = (string)$schema;

		unset($this->store[(string)$schema][$uuid]);
	}//end deleteObject()
}//end class

/**
 * In-memory fake of OR WebhookService capturing dispatched events.
 */
class TenderFakeWebhookService {

	/**
	 * @var array<int, array{eventName: string, payload: array<string, mixed>}>
	 */
	public array $events = [];

	/**
	 * @param array<string, mixed> $payload
	 */
	public function dispatchEvent(object $_event, string $eventName, array $payload): void {
		$this->events[] = ['eventName' => $eventName, 'payload' => $payload];
	}//end dispatchEvent()
}//end class

/**
 * Tests for PosTenderService.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/pos-split-tender/tasks.md#10.1
 */
class PosTenderServiceTest extends TestCase {

	private PosTenderService $service;

	private TenderFakeObjectService $objects;

	private TenderFakeWebhookService $webhooks;

	/**
	 * @var array<string, string>
	 */
	private array $appConfigStore = [
		'register' => 'reg',
		'posTender_schema' => 'posTender',
		'posTenderType_schema' => 'posTenderType',
		'posTransaction_schema' => 'posTransaction',
	];

	protected function setUp(): void {
		$this->objects = new TenderFakeObjectService();
		$this->webhooks = new TenderFakeWebhookService();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') {
				return $this->appConfigStore[$key] ?? $default;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objects;
				}

				if ($id === 'OCA\\OpenRegister\\Service\\WebhookService') {
					return $this->webhooks;
				}

				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);

		$this->service = new PosTenderService(
			container: $container,
			appConfig: $appConfig,
			eventDispatcher: $dispatcher,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Seed a tender type into the store.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function seedTenderType(array $overrides = []): array {
		$id = (string)($overrides['id'] ?? 'type-' . bin2hex(random_bytes(4)));
		$type = array_merge(
			[
				'id' => $id,
				'name' => 'Contant',
				'code' => 'CASH',
				'description' => '',
				'glAccount' => '1100',
				'requiresReference' => false,
				'requiresPin' => false,
				'allowsChange' => true,
				'isActive' => true,
				'sortOrder' => 1,
			],
			$overrides,
		);

		$this->objects->store['posTenderType'][$id] = $type;
		return $type;
	}//end seedTenderType()

	/**
	 * Seed a transaction.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function seedTransaction(array $overrides = []): array {
		$id = (string)($overrides['id'] ?? 'txn-' . bin2hex(random_bytes(4)));
		$transaction = array_merge(
			[
				'id' => $id,
				'reference' => 'TXN-0001',
				'total' => 27.59,
				'status' => 'confirmed',
			],
			$overrides,
		);

		$this->objects->store['posTransaction'][$id] = $transaction;
		return $transaction;
	}//end seedTransaction()

	/**
	 * Seed a tender directly.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function seedTender(array $overrides = []): array {
		$id = (string)($overrides['id'] ?? 'tender-' . bin2hex(random_bytes(4)));
		$tender = array_merge(
			[
				'id' => $id,
				'transaction' => 'txn-1',
				'tenderType' => 'type-1',
				'amount' => 10.00,
				'glAccount' => '1100',
				'change' => 0.0,
				'sortOrder' => 1,
				'glPosted' => false,
				'glPostAttempts' => 0,
			],
			$overrides,
		);

		$this->objects->store['posTender'][$id] = $tender;
		return $tender;
	}//end seedTender()

	// -----------------------------------------------------------------
	// calculateChange (REQ-PST-005)
	// -----------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testCalculateChangeOverpay(): void {
		$this->assertSame(22.80, $this->service->calculateChange(50.00, 27.20));
	}//end testCalculateChangeOverpay()

	/**
	 * @return void
	 */
	public function testCalculateChangeExact(): void {
		$this->assertSame(0.0, $this->service->calculateChange(27.20, 27.20));
	}//end testCalculateChangeExact()

	/**
	 * @return void
	 */
	public function testCalculateChangeUnderpay(): void {
		$this->assertSame(0.0, $this->service->calculateChange(10.00, 27.20));
	}//end testCalculateChangeUnderpay()

	// -----------------------------------------------------------------
	// listTenderTypes / getTenderTypeByCode (REQ-PST-001)
	// -----------------------------------------------------------------

	/**
	 * ⚠️ `listTenderTypes()` (:155) is still a mis-keyed #793 site — pipelinq#793
	 * sequences it behind a product decision (it flips the endpoint from
	 * permissive to rejecting), so this change does not repair it. The flag
	 * restores the store's reachability so the sort rule is still asserted.
	 *
	 * @return void
	 */
	public function testListTenderTypesReturnsAllSortedBySortOrder(): void {
		$this->objects->acceptTopLevelContext = true;

		$this->seedTenderType(['id' => 't1', 'code' => 'CASH', 'sortOrder' => 2]);
		$this->seedTenderType(['id' => 't2', 'code' => 'CARD', 'sortOrder' => 1]);
		$this->seedTenderType(['id' => 't3', 'code' => 'OFF',  'sortOrder' => 3, 'isActive' => false]);

		$types = $this->service->listTenderTypes(activeOnly: false);

		$this->assertCount(3, $types);
		$this->assertSame('CARD', $types[0]['code']);
		$this->assertSame('CASH', $types[1]['code']);
		$this->assertSame('OFF', $types[2]['code']);
	}//end testListTenderTypesReturnsAllSortedBySortOrder()

	/**
	 * ⚠️ Same known-broken site as above: `listTenderTypes()` (:155), #793.
	 *
	 * @return void
	 */
	public function testListTenderTypesActiveOnlyFiltersDeactivated(): void {
		$this->objects->acceptTopLevelContext = true;

		$this->seedTenderType(['id' => 't1', 'code' => 'CASH']);
		$this->seedTenderType(['id' => 't2', 'code' => 'OFF', 'isActive' => false]);

		$types = $this->service->listTenderTypes(activeOnly: true);

		$this->assertCount(1, $types);
		$this->assertSame('CASH', $types[0]['code']);
	}//end testListTenderTypesActiveOnlyFiltersDeactivated()

	/**
	 * @return void
	 */
	public function testGetTenderTypeByCodeThrowsOnMissing(): void {
		$this->expectException(TenderTypeNotFoundException::class);
		$this->service->getTenderTypeByCode(code: 'NOSUCH');
	}//end testGetTenderTypeByCodeThrowsOnMissing()

	/**
	 * @return void
	 */
	public function testGetTenderTypeByIdReturnsType(): void {
		$this->seedTenderType(['id' => 'cash-1', 'code' => 'CASH']);

		$type = $this->service->getTenderTypeById(id: 'cash-1');

		$this->assertSame('CASH', $type['code']);
	}//end testGetTenderTypeByIdReturnsType()

	// -----------------------------------------------------------------
	// createTenderType / updateTenderType / deleteTenderType (REQ-PST-001)
	// -----------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testCreateTenderTypeRequiresName(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->service->createTenderType(data: ['code' => 'CASH', 'glAccount' => '1100']);
	}//end testCreateTenderTypeRequiresName()

	/**
	 * @return void
	 */
	public function testCreateTenderTypeRequiresGlAccount(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->service->createTenderType(data: ['name' => 'Contant', 'code' => 'CASH']);
	}//end testCreateTenderTypeRequiresGlAccount()

	/**
	 * ⚠️ The uniqueness check reads through `listTenderTypes()` (:155), a
	 * known-broken #793 site this change does not repair.
	 *
	 * @return void
	 */
	public function testCreateTenderTypeRejectsDuplicateCode(): void {
		$this->objects->acceptTopLevelContext = true;

		$this->seedTenderType(['id' => 't1', 'code' => 'CASH']);

		$this->expectException(OCSBadRequestException::class);
		$this->service->createTenderType(data: [
			'name' => 'Contant 2',
			'code' => 'CASH',
			'glAccount' => '1100',
		]);
	}//end testCreateTenderTypeRejectsDuplicateCode()

	/**
	 * @return void
	 */
	public function testCreateTenderTypePersists(): void {
		$saved = $this->service->createTenderType(data: [
			'name' => 'Cadeaubon',
			'code' => 'VOUCHER',
			'glAccount' => '2100',
			'requiresReference' => true,
		]);

		$this->assertSame('VOUCHER', $saved['code']);
		$this->assertSame('2100', $saved['glAccount']);
		$this->assertTrue($saved['requiresReference']);
		$this->assertTrue($saved['isActive']);
	}//end testCreateTenderTypePersists()

	/**
	 * @return void
	 */
	public function testUpdateTenderTypePreservesCode(): void {
		$this->seedTenderType(['id' => 't1', 'code' => 'CASH', 'name' => 'Contant']);

		$updated = $this->service->updateTenderType(
			id: 't1',
			data: ['name' => 'Kas', 'code' => 'CHANGED', 'glAccount' => '1100'],
		);

		$this->assertSame('CASH', $updated['code']);
		$this->assertSame('Kas', $updated['name']);
	}//end testUpdateTenderTypePreservesCode()

	/**
	 * ⚠️ The active-reference guard reads through `countTendersForType()`
	 * (:921), a known-broken #793 site this change does not repair (fixing it
	 * would make any ever-used tender type permanently undeletable).
	 *
	 * @return void
	 */
	public function testDeleteTenderTypeWithActiveReferencesRejects(): void {
		$this->objects->acceptTopLevelContext = true;

		$this->seedTenderType(['id' => 't1', 'code' => 'CASH']);
		$this->seedTender(['tenderType' => 't1']);

		$this->expectException(OCSBadRequestException::class);
		$this->service->deleteTenderType(id: 't1');
	}//end testDeleteTenderTypeWithActiveReferencesRejects()

	/**
	 * @return void
	 */
	public function testDeleteTenderTypeNoReferencesSucceeds(): void {
		$this->seedTenderType(['id' => 't1', 'code' => 'CASH']);

		$this->service->deleteTenderType(id: 't1');

		$this->assertArrayNotHasKey('t1', $this->objects->store['posTenderType']);
	}//end testDeleteTenderTypeNoReferencesSucceeds()

	/**
	 * @return void
	 */
	public function testDeleteTenderTypeMissingThrowsNotFound(): void {
		$this->expectException(TenderTypeNotFoundException::class);
		$this->service->deleteTenderType(id: 'nonexistent');
	}//end testDeleteTenderTypeMissingThrowsNotFound()

	// -----------------------------------------------------------------
	// addTender / removeTender (REQ-PST-002 / REQ-PST-003)
	// -----------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testAddTenderPersistsValidPayload(): void {
		$type = $this->seedTenderType(['id' => 't1', 'code' => 'CASH']);
		$txn = $this->seedTransaction(['id' => 'tx1', 'total' => 27.59]);

		$saved = $this->service->addTender(
			transactionId: 'tx1',
			payload: ['tenderType' => 't1', 'amount' => 27.59],
		);

		$this->assertSame('tx1', $saved['transaction']);
		$this->assertSame('t1', $saved['tenderType']);
		$this->assertSame(27.59, $saved['amount']);
		$this->assertSame('1100', $saved['glAccount']);
		$this->assertSame(0.0, $saved['change']);
	}//end testAddTenderPersistsValidPayload()

	/**
	 * @return void
	 */
	public function testAddTenderComputesChangeForCashOverpay(): void {
		$this->seedTenderType(['id' => 't1', 'code' => 'CASH', 'allowsChange' => true]);
		$this->seedTransaction(['id' => 'tx1', 'total' => 27.20]);

		$saved = $this->service->addTender(
			transactionId: 'tx1',
			payload: ['tenderType' => 't1', 'amount' => 50.00],
		);

		$this->assertSame(50.00, $saved['amount']);
		$this->assertSame(22.80, $saved['change']);
	}//end testAddTenderComputesChangeForCashOverpay()

	/**
	 * @return void
	 */
	public function testAddTenderRejectsOnSettledTransaction(): void {
		$this->seedTenderType(['id' => 't1', 'code' => 'CASH']);
		$this->seedTransaction(['id' => 'tx1', 'status' => 'settled']);

		$this->expectException(InvalidTenderException::class);
		$this->expectExceptionMessage('Cannot add tenders to a settled transaction');

		$this->service->addTender(
			transactionId: 'tx1',
			payload: ['tenderType' => 't1', 'amount' => 10.00],
		);
	}//end testAddTenderRejectsOnSettledTransaction()

	/**
	 * @return void
	 */
	public function testAddTenderRejectsAmountBelowMinimum(): void {
		$this->seedTenderType(['id' => 't1', 'code' => 'CASH']);
		$this->seedTransaction(['id' => 'tx1']);

		$this->expectException(InvalidTenderException::class);
		$this->expectExceptionMessage('Tender amount must be greater than');

		$this->service->addTender(
			transactionId: 'tx1',
			payload: ['tenderType' => 't1', 'amount' => 0.005],
		);
	}//end testAddTenderRejectsAmountBelowMinimum()

	/**
	 * @return void
	 */
	public function testAddTenderRejectsMissingReferenceWhenRequired(): void {
		$this->seedTenderType(['id' => 't1', 'code' => 'CARD', 'requiresReference' => true]);
		$this->seedTransaction(['id' => 'tx1']);

		$this->expectException(InvalidTenderException::class);
		$this->expectExceptionMessage('Reference is required');

		$this->service->addTender(
			transactionId: 'tx1',
			payload: ['tenderType' => 't1', 'amount' => 10.00],
		);
	}//end testAddTenderRejectsMissingReferenceWhenRequired()

	/**
	 * @return void
	 */
	public function testAddTenderAcceptsReferenceWhenRequired(): void {
		$this->seedTenderType(['id' => 't1', 'code' => 'CARD', 'requiresReference' => true]);
		$this->seedTransaction(['id' => 'tx1']);

		$saved = $this->service->addTender(
			transactionId: 'tx1',
			payload: ['tenderType' => 't1', 'amount' => 10.00, 'reference' => 'AUTH-1'],
		);

		$this->assertSame('AUTH-1', $saved['reference']);
	}//end testAddTenderAcceptsReferenceWhenRequired()

	/**
	 * @return void
	 */
	public function testAddTenderRejectsInactiveType(): void {
		$this->seedTenderType(['id' => 't1', 'code' => 'OFF', 'isActive' => false]);
		$this->seedTransaction(['id' => 'tx1']);

		$this->expectException(InvalidTenderException::class);
		$this->expectExceptionMessage('is not active');

		$this->service->addTender(
			transactionId: 'tx1',
			payload: ['tenderType' => 't1', 'amount' => 10.00],
		);
	}//end testAddTenderRejectsInactiveType()

	/**
	 * @return void
	 */
	public function testAddTenderRejectsMissingTenderType(): void {
		$this->seedTransaction(['id' => 'tx1']);

		$this->expectException(InvalidTenderException::class);
		$this->service->addTender(
			transactionId: 'tx1',
			payload: ['amount' => 10.00],
		);
	}//end testAddTenderRejectsMissingTenderType()

	/**
	 * @return void
	 */
	public function testAddTenderRejectsMissingTransaction(): void {
		$this->seedTenderType(['id' => 't1', 'code' => 'CASH']);

		$this->expectException(OCSNotFoundException::class);
		$this->service->addTender(
			transactionId: 'missing-tx',
			payload: ['tenderType' => 't1', 'amount' => 10.00],
		);
	}//end testAddTenderRejectsMissingTransaction()

	/**
	 * @return void
	 */
	public function testRemoveTenderRejectsOnSettledTransaction(): void {
		$this->seedTenderType(['id' => 't1']);
		$this->seedTransaction(['id' => 'tx1', 'status' => 'settled']);
		$this->seedTender(['id' => 'tnd1', 'transaction' => 'tx1', 'tenderType' => 't1']);

		$this->expectException(InvalidTenderException::class);
		$this->expectExceptionMessage('Cannot remove tenders from a settled transaction');

		$this->service->removeTender(transactionId: 'tx1', tenderId: 'tnd1');
	}//end testRemoveTenderRejectsOnSettledTransaction()

	/**
	 * @return void
	 */
	public function testRemoveTenderRejectsWhenTenderBelongsToDifferentTransaction(): void {
		$this->seedTenderType(['id' => 't1']);
		$this->seedTransaction(['id' => 'tx1']);
		$this->seedTender(['id' => 'tnd1', 'transaction' => 'tx-other']);

		$this->expectException(OCSNotFoundException::class);
		$this->service->removeTender(transactionId: 'tx1', tenderId: 'tnd1');
	}//end testRemoveTenderRejectsWhenTenderBelongsToDifferentTransaction()

	/**
	 * @return void
	 */
	public function testRemoveTenderDeletesValidTender(): void {
		$this->seedTenderType(['id' => 't1']);
		$this->seedTransaction(['id' => 'tx1']);
		$this->seedTender(['id' => 'tnd1', 'transaction' => 'tx1', 'tenderType' => 't1']);

		$this->service->removeTender(transactionId: 'tx1', tenderId: 'tnd1');

		$this->assertArrayNotHasKey('tnd1', $this->objects->store['posTender']);
	}//end testRemoveTenderDeletesValidTender()

	// -----------------------------------------------------------------
	// getTendersForTransaction (REQ-PST-002)
	// -----------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testGetTendersForTransactionReturnsSortedByOrder(): void {
		$this->seedTenderType(['id' => 't1']);
		$this->seedTransaction(['id' => 'tx1']);
		$this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'sortOrder' => 3]);
		$this->seedTender(['id' => 'b', 'transaction' => 'tx1', 'sortOrder' => 1]);
		$this->seedTender(['id' => 'c', 'transaction' => 'tx1', 'sortOrder' => 2]);
		$this->seedTender(['id' => 'd', 'transaction' => 'tx-other', 'sortOrder' => 0]);

		$tenders = $this->service->getTendersForTransaction(transactionId: 'tx1');

		$this->assertCount(3, $tenders);
		$this->assertSame('b', $tenders[0]['id']);
		$this->assertSame('c', $tenders[1]['id']);
		$this->assertSame('a', $tenders[2]['id']);
	}//end testGetTendersForTransactionReturnsSortedByOrder()

	/**
	 * @return void
	 */
	public function testGetTendersForEmptyIdReturnsEmpty(): void {
		$this->assertSame([], $this->service->getTendersForTransaction(transactionId: ''));
	}//end testGetTendersForEmptyIdReturnsEmpty()

	// -----------------------------------------------------------------
	// pipelinq#799 — the settle path, with NO preceding write
	// -----------------------------------------------------------------

	/**
	 * THE #799 REGRESSION GUARD.
	 *
	 * `POST /api/pos-transactions/{id}/settle` reaches
	 * `getTendersForTransaction()` with nothing having written first:
	 * `fetchTransaction()` is an `ObjectService::find()`, and `find()`
	 * snapshots and restores the sticky register/schema context, so it leaves
	 * none behind. With `register`/`schema` keyed at the top level of the
	 * `findAll()` config — where OpenRegister never reads them — the query
	 * resolved no context at all, returned `[]`, and `assertBalancedForSettle()`
	 * saw `tenderSum = 0` and rejected every non-zero transaction with a 409
	 * "Underpayment" that was arithmetically true and factually wrong.
	 *
	 * The assertion is on the SEEDED AMOUNTS, not on "more than zero rows":
	 * a scoped read and an unscoped one are indistinguishable to a count.
	 *
	 * @return void
	 */
	public function testSettleReadsTheSeededTendersWithNoPrecedingWrite(): void {
		$this->seedTenderType(['id' => 't1']);
		$this->seedTransaction(['id' => 'tx-settle', 'total' => 25.00]);
		$this->seedTender(['id' => 'a', 'transaction' => 'tx-settle', 'amount' => 10.00, 'sortOrder' => 1]);
		$this->seedTender(['id' => 'b', 'transaction' => 'tx-settle', 'amount' => 15.00, 'sortOrder' => 2]);
		$this->seedTender(['id' => 'other', 'transaction' => 'tx-elsewhere', 'amount' => 99.00]);

		// The control that makes this the SETTLE path and not the addTender
		// path: no write has run, so there is no sticky context to inherit.
		$this->assertSame('', $this->objects->currentRegister);
		$this->assertSame('', $this->objects->currentSchema);

		$validation = $this->service->validateTenderSum(transactionId: 'tx-settle');

		$this->assertSame(25.00, $validation['tenderSum']);
		$this->assertSame(25.00, $validation['transactionTotal']);
		$this->assertSame(0.00, $validation['variance']);
		$this->assertTrue($validation['balanced']);

		// And the settle guard itself lets the transaction through.
		$this->service->assertBalancedForSettle(transactionId: 'tx-settle');
		$this->addToAssertionCount(1);
	}//end testSettleReadsTheSeededTendersWithNoPrecedingWrite()

	/**
	 * The context keys must travel INSIDE `filters`.
	 *
	 * `ObjectService::prepareFindAllConfig()` reads
	 * `$config['filters']['register']` / `['schema']` and nothing else, while
	 * `findAll()`'s own docblock advertises them as top-level keys — which is
	 * how eleven sites in this app came to be written the wrong way (#793).
	 * Asserting the emitted query shape pins the contract directly, so a
	 * future edit that moves them back out fails here by name.
	 *
	 * @return void
	 */
	public function testGetTendersQueriesWithContextInsideFiltersOnly(): void {
		$this->seedTransaction(['id' => 'tx1', 'total' => 10.00]);
		$this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'amount' => 10.00]);

		$this->service->getTendersForTransaction(transactionId: 'tx1');

		$this->assertCount(1, $this->objects->queries);
		$config = $this->objects->queries[0];

		$this->assertSame('reg', $config['filters']['register']);
		$this->assertSame('posTender', $config['filters']['schema']);
		$this->assertArrayNotHasKey('register', $config);
		$this->assertArrayNotHasKey('schema', $config);
	}//end testGetTendersQueriesWithContextInsideFiltersOnly()

	/**
	 * One line, two call orders, one answer.
	 *
	 * The defect's signature was that `getTendersForTransaction()` WORKED when
	 * reached through `addTender()` — whose preceding `saveObject()` had
	 * already pinned the posTender context that the mis-keyed query then
	 * inherited — and FAILED when reached through settle. Whoever tested
	 * addTender saw it work. This asserts the two orders now agree.
	 *
	 * @return void
	 */
	public function testAddTenderAndSettleOrdersAgreeOnTheSameTenderSum(): void {
		$this->seedTenderType(['id' => 't1', 'code' => 'CASH', 'allowsChange' => false]);
		$this->seedTransaction(['id' => 'tx1', 'total' => 12.50]);

		// Order A — through addTender(), which writes first.
		$this->service->addTender(
			transactionId: 'tx1',
			payload: ['tenderType' => 't1', 'amount' => 12.50],
		);
		$afterWrite = $this->service->validateTenderSum(transactionId: 'tx1');

		// Order B — settle, on a service instance with no sticky context.
		$this->objects->currentRegister = '';
		$this->objects->currentSchema = '';
		$afterRestore = $this->service->validateTenderSum(transactionId: 'tx1');

		$this->assertSame(12.50, $afterWrite['tenderSum']);
		$this->assertSame(12.50, $afterRestore['tenderSum']);
		$this->assertSame($afterWrite['tenderSum'], $afterRestore['tenderSum']);
	}//end testAddTenderAndSettleOrdersAgreeOnTheSameTenderSum()

	/**
	 * `deleteObject()`'s first parameter is `$uuid`. `removeTender()` shipped
	 * calling it `id:`, which is `Error: Unknown named parameter $id` at
	 * runtime — swallowed by the surrounding `catch (Throwable)` and reported
	 * as an ordinary "Failed to remove tender". The previous fake DECLARED the
	 * parameter as `$id`, mirroring the broken caller instead of the real
	 * collaborator, so the suite was green over a guaranteed fatal.
	 *
	 * @return void
	 */
	public function testRemoveTenderActuallyDeletesTheRow(): void {
		$this->seedTenderType(['id' => 't1']);
		$this->seedTransaction(['id' => 'tx1', 'total' => 10.00]);
		$this->seedTender(['id' => 'tnd1', 'transaction' => 'tx1', 'amount' => 10.00]);

		$this->service->removeTender(transactionId: 'tx1', tenderId: 'tnd1');

		$this->assertArrayNotHasKey('tnd1', $this->objects->store['posTender']);
	}//end testRemoveTenderActuallyDeletesTheRow()

	/**
	 * Same defect on the tender-type delete path (`deleteTenderType()`).
	 *
	 * @return void
	 */
	public function testDeleteTenderTypeActuallyDeletesTheRow(): void {
		$this->seedTenderType(['id' => 't1', 'code' => 'CASH']);

		$this->service->deleteTenderType(id: 't1');

		$this->assertArrayNotHasKey('t1', $this->objects->store['posTenderType']);
	}//end testDeleteTenderTypeActuallyDeletesTheRow()

	// -----------------------------------------------------------------
	// validateTenderSum + assertBalancedForSettle (REQ-PST-004)
	// -----------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testValidateTenderSumReportsBalancedTotal(): void {
		$this->seedTenderType(['id' => 't1']);
		$this->seedTransaction(['id' => 'tx1', 'total' => 100.00]);
		$this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'amount' => 60.00]);
		$this->seedTender(['id' => 'b', 'transaction' => 'tx1', 'amount' => 40.00]);

		$result = $this->service->validateTenderSum(transactionId: 'tx1');

		$this->assertSame(100.00, $result['tenderSum']);
		$this->assertSame(100.00, $result['transactionTotal']);
		$this->assertSame(0.00, $result['variance']);
		$this->assertTrue($result['balanced']);
	}//end testValidateTenderSumReportsBalancedTotal()

	/**
	 * @return void
	 */
	public function testValidateTenderSumReportsUnderpayment(): void {
		$this->seedTenderType(['id' => 't1']);
		$this->seedTransaction(['id' => 'tx1', 'total' => 100.00]);
		$this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'amount' => 60.00]);

		$result = $this->service->validateTenderSum(transactionId: 'tx1');

		$this->assertSame(60.00, $result['tenderSum']);
		$this->assertSame(40.00, $result['variance']);
		$this->assertFalse($result['balanced']);
	}//end testValidateTenderSumReportsUnderpayment()

	/**
	 * @return void
	 */
	public function testAssertBalancedForSettleAcceptsExactMatch(): void {
		$this->seedTenderType(['id' => 't1']);
		$this->seedTransaction(['id' => 'tx1', 'total' => 50.00]);
		$this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'amount' => 50.00]);

		$this->service->assertBalancedForSettle(transactionId: 'tx1');
		$this->addToAssertionCount(1);
	}//end testAssertBalancedForSettleAcceptsExactMatch()

	/**
	 * @return void
	 */
	public function testAssertBalancedForSettleRejectsUnderpayment(): void {
		$this->seedTenderType(['id' => 't1']);
		$this->seedTransaction(['id' => 'tx1', 'total' => 50.00]);
		$this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'amount' => 30.00]);

		$this->expectException(InvalidTenderException::class);
		$this->expectExceptionMessage('Underpayment');
		$this->service->assertBalancedForSettle(transactionId: 'tx1');
	}//end testAssertBalancedForSettleRejectsUnderpayment()

	/**
	 * @return void
	 */
	public function testAssertBalancedForSettleAcceptsOverpaymentWithChangeTender(): void {
		$this->seedTenderType(['id' => 'cash', 'code' => 'CASH', 'allowsChange' => true]);
		$this->seedTransaction(['id' => 'tx1', 'total' => 25.00]);
		// Cash tender of 30 with 5 of change recorded covers the overpayment.
		$this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'amount' => 30.00, 'change' => 5.00]);

		$this->service->assertBalancedForSettle(transactionId: 'tx1');
		$this->addToAssertionCount(1);
	}//end testAssertBalancedForSettleAcceptsOverpaymentWithChangeTender()

	/**
	 * @return void
	 */
	public function testAssertBalancedForSettleRejectsOverpaymentWithoutChangeTender(): void {
		$this->seedTenderType(['id' => 't1']);
		$this->seedTransaction(['id' => 'tx1', 'total' => 25.00]);
		$this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'amount' => 30.00, 'change' => 0.0]);

		$this->expectException(InvalidTenderException::class);
		$this->expectExceptionMessage('Overpayment');
		$this->service->assertBalancedForSettle(transactionId: 'tx1');
	}//end testAssertBalancedForSettleRejectsOverpaymentWithoutChangeTender()

	// -----------------------------------------------------------------
	// emitSingleTenderPosted + markTenderGlPosted + listUnpostedTenders (REQ-PST-006)
	// -----------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testEmitSingleTenderPostedPersistsEventIdAndIncrementsAttempts(): void {
		$this->seedTenderType(['id' => 't1', 'code' => 'CASH']);
		$tender = $this->seedTender([
			'id' => 'tnd1',
			'transaction' => 'tx1',
			'tenderType' => 't1',
			'amount' => 25.00,
			'glAccount' => '1100',
			'glPostAttempts' => 0,
		]);

		$eventId = $this->service->emitSingleTenderPosted(
			transactionUuid: 'tx1',
			transactionReference: 'TXN-0001',
			tender: $tender,
		);

		$this->assertNotSame('', $eventId);
		$stored = $this->objects->store['posTender']['tnd1'];
		$this->assertSame($eventId, $stored['cloudEventId']);
		$this->assertSame(1, $stored['glPostAttempts']);
		$this->assertNotSame([], $this->webhooks->events);
		$this->assertSame(PosTenderService::EVENT_TENDER_POSTED, $this->webhooks->events[0]['eventName']);
	}//end testEmitSingleTenderPostedPersistsEventIdAndIncrementsAttempts()

	/**
	 * @return void
	 */
	public function testEmitSingleTenderPostedSoftFailsAtMaxAttempts(): void {
		$this->seedTenderType(['id' => 't1']);
		$tender = $this->seedTender([
			'id' => 'tnd1',
			'transaction' => 'tx1',
			'tenderType' => 't1',
			'glPostAttempts' => PosTenderService::MAX_GL_POST_ATTEMPTS,
		]);

		$eventId = $this->service->emitSingleTenderPosted(
			transactionUuid: 'tx1',
			transactionReference: 'TXN-0001',
			tender: $tender,
		);

		$this->assertSame('', $eventId);
		$this->assertSame([], $this->webhooks->events);
	}//end testEmitSingleTenderPostedSoftFailsAtMaxAttempts()

	/**
	 * @return void
	 */
	public function testMarkTenderGlPostedFlipsTheFlag(): void {
		$this->seedTender(['id' => 'tnd1', 'glPosted' => false]);

		$this->service->markTenderGlPosted(tenderId: 'tnd1');

		$this->assertTrue($this->objects->store['posTender']['tnd1']['glPosted']);
	}//end testMarkTenderGlPostedFlipsTheFlag()

	/**
	 * ⚠️ `listUnpostedTenders()` (:828) is still a mis-keyed #793 site — an
	 * unbounded whole-table read on a five-minute cron that emits one outbound
	 * GL CloudEvent per row, so it needs a limit and a batch cap before it may
	 * be switched on. This change does not repair it.
	 *
	 * @return void
	 */
	public function testListUnpostedTendersIncludesAttemptedNotConfirmed(): void {
		$this->objects->acceptTopLevelContext = true;

		$this->seedTender(['id' => 'a', 'glPosted' => false, 'glPostAttempts' => 1]);
		$this->seedTender(['id' => 'b', 'glPosted' => true,  'glPostAttempts' => 2]);
		$this->seedTender(['id' => 'c', 'glPosted' => false, 'glPostAttempts' => 0]);
		$this->seedTender(['id' => 'd', 'glPosted' => false, 'glPostAttempts' => PosTenderService::MAX_GL_POST_ATTEMPTS]);

		$unposted = $this->service->listUnpostedTenders();
		$ids = array_map(static fn (array $row): string => (string)$row['id'], $unposted);

		$this->assertSame(['a'], $ids);
	}//end testListUnpostedTendersIncludesAttemptedNotConfirmed()

	/**
	 * @return void
	 */
	public function testEmitTendersPostedFanOutsAcrossAllTenders(): void {
		$this->seedTenderType(['id' => 't1', 'code' => 'CASH']);
		$this->seedTransaction(['id' => 'tx1']);
		$this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'tenderType' => 't1', 'amount' => 10.00]);
		$this->seedTender(['id' => 'b', 'transaction' => 'tx1', 'tenderType' => 't1', 'amount' => 15.00]);

		$emitted = $this->service->emitTendersPosted(transactionId: 'tx1');

		$this->assertCount(2, $emitted);
		$this->assertCount(2, $this->webhooks->events);
	}//end testEmitTendersPostedFanOutsAcrossAllTenders()
}//end class
