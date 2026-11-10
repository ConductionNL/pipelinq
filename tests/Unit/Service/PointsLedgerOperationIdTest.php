<?php

/**
 * Tests that a ledger movement with an operation id is written once.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/loyalty-ledger-operation-id/specs/loyalty-program/spec.md#requirement-a-retried-movement-is-written-once-req-loy-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-LOY-011: a retried movement is written once.
 *
 * @spec openspec/changes/loyalty-ledger-operation-id/specs/loyalty-program/spec.md#requirement-a-retried-movement-is-written-once-req-loy-011
 */
class PointsLedgerOperationIdTest extends TestCase {

	/**
	 * The stored ledger entries, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $entries = [];

	/**
	 * The accounts, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $accounts = [];

	/**
	 * Every balance update, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $balanceUpdates = [];

	/**
	 * Every ledger query, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Whether a ledger query throws.
	 *
	 * @var bool
	 */
	private bool $queryThrows = false;

	/**
	 * The service over an in-memory ledger.
	 *
	 * The object service answers as OpenRegister does: saveObject returns an
	 * ObjectEntity, findAll returns the ObjectEntities whose fields equal
	 * every filter but register and schema.
	 *
	 * @return PointsLedgerService
	 */
	private function service(): PointsLedgerService {
		$this->accounts += [
			'anna' => ['uuid' => 'anna', 'customerId' => 'c-anna', 'currentBalance' => 100],
			'bram' => ['uuid' => 'bram', 'customerId' => 'c-bram', 'currentBalance' => 100],
		];
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'reg-1',
				'pointsLedgerEntry_schema' => 'schema-ledger',
				default => $default,
			}
		);

		$accounts = $this->createMock(originalClassName: LoyaltyAccountService::class);
		$accounts->method('getAccount')->willReturnCallback(fn (string $accountId): ?array => ($this->accounts[$accountId] ?? null));
		$accounts->method('updateBalances')->willReturnCallback(
			function (string $accountId, int $newCurrentBalance, int $lifetimeDelta, string $lastActivityDate): ?array {
				$this->balanceUpdates[] = ['accountId' => $accountId, 'balance' => $newCurrentBalance, 'lifetimeDelta' => $lifetimeDelta];
				$this->accounts[$accountId]['currentBalance'] = $newCurrentBalance;
				return $this->accounts[$accountId];
			}
		);

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (
				array $object,
				ObjectSaveOptions $options = new ObjectSaveOptions(),
				bool $_rbac = true,
				bool $_multitenancy = true,
			): ObjectEntity {
				$id = 'entry-' . (count($this->entries) + 1);
				$this->entries[$id] = $object;
				return $this->entity(uuid: $id);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->queries[] = $config;
				if ($this->queryThrows === true) {
					throw new RuntimeException('query failed');
				}

				$filters = $config['filters'];
				unset($filters['register'], $filters['schema']);
				$found = [];
				foreach ($this->entries as $id => $entry) {
					$matches = true;
					foreach ($filters as $field => $value) {
						if (array_key_exists($field, $entry) === false || $entry[$field] !== $value) {
							$matches = false;
						}
					}

					if ($matches === true) {
						$found[] = $this->entity(uuid: $id);
					}
				}

				return $found;
			}
		);

		return new PointsLedgerService(
			appConfig: $appConfig,
			accountService: $accounts,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			objectService: $objects,
			aggregationRunner: $this->createMock(originalClassName: AggregationRunner::class),
		);
	}//end service()

	/**
	 * A stored entry as OpenRegister returns it.
	 *
	 * @param string $uuid The entry uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('reg-1');
		$entity->setSchema('schema-ledger');
		$entity->setObject($this->entries[$uuid]);
		return $entity;
	}//end entity()

	/**
	 * A credit of 45 points for Anna, with an operation id.
	 *
	 * @param PointsLedgerService $ledger The service.
	 * @param string              $id     The operation id.
	 * @param int                 $amount The points.
	 *
	 * @return array<string, mixed>
	 */
	private function credit(PointsLedgerService $ledger, string $id, int $amount = 45): array {
		return $ledger->forOperation(operationId: $id)->creditPoints(
			accountId: 'anna',
			amount: $amount,
			ruleId: 'rule-1',
			sourceDocument: ['transactionId' => 'TX-1001'],
			processedBy: 'pos-1'
		);
	}//end credit()

	/**
	 * REQ-LOY-011-01: a retried POS credit is credited once.
	 *
	 * @return void
	 */
	public function testARetriedCreditIsCreditedOnce(): void {
		$ledger = $this->service();

		$first = $this->credit(ledger: $ledger, id: 'pos:TX-1001');
		$again = $this->credit(ledger: $ledger, id: 'pos:TX-1001');

		$this->assertSame(expected: $first, actual: $again);
		$this->assertCount(expectedCount: 1, haystack: $this->entries);
		$this->assertSame(expected: 'pos:TX-1001', actual: $this->entries['entry-1']['operationId']);
		$this->assertSame(expected: 145, actual: $this->accounts['anna']['currentBalance']);
		$this->assertCount(expectedCount: 1, haystack: $this->balanceUpdates);
		$this->assertSame(
			expected: ['filters' => ['accountId' => 'anna', 'operationId' => 'pos:TX-1001', 'register' => 'reg-1', 'schema' => 'schema-ledger'], 'limit' => 1],
			actual: $this->queries[1]
		);
	}//end testARetriedCreditIsCreditedOnce()

	/**
	 * REQ-LOY-011-02: a retried debit after the points were spent returns the
	 * first entry and does not check the balance again.
	 *
	 * @return void
	 */
	public function testARetriedDebitAfterSpendingReturnsTheFirstEntry(): void {
		$this->accounts['anna'] = ['uuid' => 'anna', 'customerId' => 'c-anna', 'currentBalance' => 300];
		$ledger = $this->service()->forOperation(operationId: 'redemption:R-77');

		$first = $ledger->debitPoints(accountId: 'anna', amount: 250, redemptionId: 'R-77', sourceDocument: [], processedBy: 'redemption-service');
		$this->accounts['anna']['currentBalance'] = 0;
		$again = $ledger->debitPoints(accountId: 'anna', amount: 250, redemptionId: 'R-77', sourceDocument: [], processedBy: 'redemption-service');

		$this->assertSame(expected: $first, actual: $again);
		$this->assertCount(expectedCount: 1, haystack: $this->entries);
		$this->assertSame(expected: -250, actual: $this->entries['entry-1']['count']);
		$this->assertSame(expected: 0, actual: $this->accounts['anna']['currentBalance']);
	}//end testARetriedDebitAfterSpendingReturnsTheFirstEntry()

	/**
	 * REQ-LOY-011-03: a reused operation id with another amount is refused.
	 *
	 * @return void
	 */
	public function testAReusedIdWithAnotherAmountIsRefused(): void {
		$ledger = $this->service();
		$this->credit(ledger: $ledger, id: 'pos:TX-1001');

		try {
			$this->credit(ledger: $ledger, id: 'pos:TX-1001', amount: 60);
			$this->fail(message: 'A reused operation id with another amount must be refused.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString(needle: 'pos:TX-1001', haystack: $e->getMessage());
		}

		$this->assertCount(expectedCount: 1, haystack: $this->entries);
		$this->assertSame(expected: 145, actual: $this->accounts['anna']['currentBalance']);
		$this->assertCount(expectedCount: 1, haystack: $this->balanceUpdates);
	}//end testAReusedIdWithAnotherAmountIsRefused()

	/**
	 * A reused operation id with another type is refused, even with the same
	 * signed count.
	 *
	 * @return void
	 */
	public function testAReusedIdWithAnotherTypeIsRefused(): void {
		$ledger = $this->service()->forOperation(operationId: 'op-1');
		$ledger->expirePoints(accountId: 'anna', amount: 10, reason: 'inactivity-expiry');

		$this->expectException(exception: RuntimeException::class);
		try {
			$ledger->adjustPoints(accountId: 'anna', delta: -10, reason: 'correction', processedBy: 'carol');
		} finally {
			$this->assertCount(expectedCount: 1, haystack: $this->entries);
			$this->assertCount(expectedCount: 1, haystack: $this->balanceUpdates);
		}
	}//end testAReusedIdWithAnotherTypeIsRefused()

	/**
	 * REQ-LOY-011-04: the same operation id on another account is a new movement.
	 *
	 * @return void
	 */
	public function testTheSameIdOnAnotherAccountIsANewMovement(): void {
		$ledger = $this->service()->forOperation(operationId: 'pos:TX-1001');

		$ledger->creditPoints(accountId: 'anna', amount: 45, ruleId: null, sourceDocument: [], processedBy: 'pos-1');
		$ledger->creditPoints(accountId: 'bram', amount: 45, ruleId: null, sourceDocument: [], processedBy: 'pos-1');

		$this->assertCount(expectedCount: 2, haystack: $this->entries);
		$this->assertSame(expected: 'bram', actual: $this->entries['entry-2']['accountId']);
		$this->assertSame(expected: 145, actual: $this->accounts['bram']['currentBalance']);
	}//end testTheSameIdOnAnotherAccountIsANewMovement()

	/**
	 * REQ-LOY-011-05: a movement without an operation id is always written,
	 * and asks nothing first.
	 *
	 * @return void
	 */
	public function testAMovementWithoutAnIdIsAlwaysWritten(): void {
		$ledger = $this->service();

		$ledger->creditPoints(accountId: 'anna', amount: 45, ruleId: null, sourceDocument: [], processedBy: 'pos-1');
		$ledger->forOperation(operationId: '')->creditPoints(accountId: 'anna', amount: 45, ruleId: null, sourceDocument: [], processedBy: 'pos-1');

		$this->assertCount(expectedCount: 2, haystack: $this->entries);
		$this->assertArrayNotHasKey(key: 'operationId', array: $this->entries['entry-1']);
		$this->assertArrayNotHasKey(key: 'operationId', array: $this->entries['entry-2']);
		$this->assertSame(expected: [], actual: $this->queries);
	}//end testAMovementWithoutAnIdIsAlwaysWritten()

	/**
	 * An id given to one movement stays with that movement: the shared
	 * service writes the next movement without it.
	 *
	 * @return void
	 */
	public function testTheIdDoesNotStickToTheSharedService(): void {
		$ledger = $this->service();
		$this->credit(ledger: $ledger, id: 'pos:TX-1001');

		$ledger->creditPoints(accountId: 'anna', amount: 45, ruleId: 'rule-1', sourceDocument: ['transactionId' => 'TX-1001'], processedBy: 'pos-1');

		$this->assertCount(expectedCount: 2, haystack: $this->entries);
		$this->assertArrayNotHasKey(key: 'operationId', array: $this->entries['entry-2']);
	}//end testTheIdDoesNotStickToTheSharedService()

	/**
	 * Every movement stores its operation id, and a repeat of each one
	 * returns the first entry.
	 *
	 * @return void
	 */
	public function testEveryMovementTakesAnId(): void {
		$ledger = $this->service();
		$calls = [
			'credit' => fn () => $ledger->forOperation(operationId: 'op-credit')
				->creditPoints(accountId: 'anna', amount: 5, ruleId: null, sourceDocument: [], processedBy: 'p'),
			'debit' => fn () => $ledger->forOperation(operationId: 'op-debit')
				->debitPoints(accountId: 'anna', amount: 5, redemptionId: 'r', sourceDocument: [], processedBy: 'p'),
			'expiry' => fn () => $ledger->forOperation(operationId: 'op-expiry')
				->expirePoints(accountId: 'anna', amount: 5, reason: 'x'),
			'adjustment' => fn () => $ledger->forOperation(operationId: 'op-adjustment')
				->adjustPoints(accountId: 'anna', delta: 5, reason: 'x', processedBy: 'p'),
			'refund' => fn () => $ledger->forOperation(operationId: 'op-refund')
				->refundPoints(accountId: 'anna', amount: 5, redemptionId: 'r', processedBy: 'p'),
		];

		foreach ($calls as $type => $call) {
			$first = $call();
			$again = $call();
			$this->assertSame(expected: $first, actual: $again, message: $type);
		}

		$this->assertSame(
			expected: ['op-credit', 'op-debit', 'op-expiry', 'op-adjustment', 'op-refund'],
			actual: array_values(array_column($this->entries, 'operationId'))
		);
		$this->assertSame(
			expected: ['credit', 'debit', 'expiry', 'adjustment', 'refund'],
			actual: array_values(array_column($this->entries, 'type'))
		);
		$this->assertCount(expectedCount: 5, haystack: $this->balanceUpdates);
	}//end testEveryMovementTakesAnId()

	/**
	 * When the ledger cannot be asked, a movement with an id writes nothing.
	 *
	 * @return void
	 */
	public function testAnIdThatCannotBeCheckedWritesNothing(): void {
		$ledger = $this->service();
		$this->queryThrows = true;

		try {
			$this->credit(ledger: $ledger, id: 'pos:TX-1001');
			$this->fail(message: 'A movement whose id cannot be checked must not be written.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString(needle: 'pos:TX-1001', haystack: $e->getMessage());
		}

		$this->assertSame(expected: [], actual: $this->entries);
		$this->assertSame(expected: [], actual: $this->balanceUpdates);
	}//end testAnIdThatCannotBeCheckedWritesNothing()
}//end class
