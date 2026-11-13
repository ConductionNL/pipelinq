<?php

/**
 * Unit tests for PointsLedgerService.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * PointsLedgerService appends loyalty movements under an account lock, once per operation.
 */
class PointsLedgerServiceTest extends TestCase {

	/**
	 * Ledger rows written, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $ledger = [];

	/**
	 * Balance updates made, as [accountId, balance, lifetimeDelta].
	 *
	 * @var list<array{0: string, 1: int, 2: int}>
	 */
	private array $balanceUpdates = [];

	/**
	 * Locks acquired and released, in order.
	 *
	 * @var list<string>
	 */
	private array $locks = [];

	/**
	 * The account's current balance, or null for no account.
	 *
	 * @var int|null
	 */
	private ?int $balance = 100;

	/**
	 * Whether the lock is held elsewhere.
	 *
	 * @var bool
	 */
	private bool $locked = false;

	/**
	 * Whether the register is configured.
	 *
	 * @var bool
	 */
	private bool $configured = true;

	/**
	 * The ledger over the in-memory doubles.
	 *
	 * @param AggregationRunner|null $runner The aggregation runner.
	 *
	 * @return PointsLedgerService
	 */
	private function ledger(?AggregationRunner $runner=null): PointsLedgerService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key): string => ($this->configured === true ? $key . '-id' : ''));

		$accounts = $this->createMock(LoyaltyAccountService::class);
		$accounts->method('getAccount')->willReturnCallback(
			fn (string $accountId): ?array => ($this->balance === null ? null : ['customerId' => 'klant-1', 'currentBalance' => $this->balance])
		);
		$accounts->method('updateBalances')->willReturnCallback(
			function (string $accountId, int $newCurrentBalance, int $lifetimeDelta, string $lastActivityDate): ?array {
				$this->balanceUpdates[] = [$accountId, $newCurrentBalance, $lifetimeDelta];
				$this->balance = $newCurrentBalance;
				return [];
			}
		);
		$accounts->method('listAccountsForProgramme')->willReturn([['accountId' => 'acc-1'], ['uuid' => 'acc-2'], ['name' => 'no id']]);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->ledger[] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('entry-' . count($this->ledger));
				$entity->setObject($object);
				return $entity;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config=[]): array {
				$filters = $config['filters'];
				return array_values(array_filter(
					$this->ledger,
					static fn (array $row): bool => $row['accountId'] === $filters['accountId']
						&& (isset($filters['operationId']) === false || ($row['operationId'] ?? null) === $filters['operationId'])
				));
			}
		);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')->willReturnCallback(function (string $path, int $type): void {
			if ($this->locked === true) {
				throw new LockedException($path);
			}

			$this->locks[] = 'acquire ' . $path;
		});
		$locking->method('releaseLock')->willReturnCallback(function (string $path, int $type): void {
			$this->locks[] = 'release ' . $path;
		});

		return new PointsLedgerService(
			appConfig: $config,
			accountService: $accounts,
			logger: $this->createMock(LoggerInterface::class),
			objectService: $objects,
			aggregationRunner: ($runner ?? new AggregationRunner()),
			locking: $locking
		);
	}//end ledger()

	/**
	 * A credit writes a ledger row and raises balance and lifetime points, under the account lock.
	 *
	 * @return void
	 */
	public function testACredit(): void {
		$entry = $this->ledger()->creditPoints(accountId: 'acc-1', amount: 25, ruleId: 'rule-9', sourceDocument: ['receipt' => 'r-1'], processedBy: 'pos');

		self::assertSame('credit', $entry['type']);
		self::assertSame(25, $entry['count']);
		self::assertSame(125, $entry['balanceAfter']);
		self::assertSame('klant-1', $entry['customerId']);
		self::assertSame('rule-9', $entry['ruleId']);
		self::assertSame(['receipt' => 'r-1'], $entry['sourceDocument']);
		self::assertArrayNotHasKey('operationId', $entry);
		self::assertSame([['acc-1', 125, 25]], $this->balanceUpdates);
		self::assertSame(['acquire pipelinq/loyalty-account/acc-1', 'release pipelinq/loyalty-account/acc-1'], $this->locks);
	}//end testACredit()

	/**
	 * A debit lowers the balance, keeps lifetime points and records the redemption.
	 *
	 * @return void
	 */
	public function testADebit(): void {
		$entry = $this->ledger()->debitPoints(accountId: 'acc-1', amount: 40, redemptionId: 'red-1', sourceDocument: ['optionId' => 'o'], processedBy: 'redemption-service');

		self::assertSame('debit', $entry['type']);
		self::assertSame(-40, $entry['count']);
		self::assertSame(60, $entry['balanceAfter']);
		self::assertSame(['optionId' => 'o', 'redemptionId' => 'red-1'], $entry['sourceDocument']);
		self::assertSame([['acc-1', 60, 0]], $this->balanceUpdates);
	}//end testADebit()

	/**
	 * A debit beyond the balance, or for an unknown account, writes nothing and still releases the lock.
	 *
	 * @return void
	 */
	public function testADebitIsRefused(): void {
		$ledger = $this->ledger();
		try {
			$ledger->debitPoints(accountId: 'acc-1', amount: 101, redemptionId: 'r', sourceDocument: [], processedBy: 'p');
			self::fail('an overdraft went through');
		} catch (RuntimeException $e) {
			self::assertSame('Insufficient balance.', $e->getMessage());
		}

		$this->balance = null;
		try {
			$ledger->debitPoints(accountId: 'acc-1', amount: 1, redemptionId: 'r', sourceDocument: [], processedBy: 'p');
			self::fail('a debit on no account went through');
		} catch (RuntimeException $e) {
			self::assertSame('Account not found.', $e->getMessage());
		}

		self::assertSame([], $this->ledger);
		self::assertSame('release pipelinq/loyalty-account/acc-1', end($this->locks));
		self::assertCount(4, $this->locks);
	}//end testADebitIsRefused()

	/**
	 * Expiry, adjustment and refund write their own movement types.
	 *
	 * @return void
	 */
	public function testExpiryAdjustmentAndRefund(): void {
		$ledger = $this->ledger();

		$expiry = $ledger->expirePoints(accountId: 'acc-1', amount: 10, reason: 'policy-12m');
		self::assertSame(['expiry', -10, 90, ['expiryPolicyRef' => 'policy-12m'], 'system:expiry-batch'], [$expiry['type'], $expiry['count'], $expiry['balanceAfter'], $expiry['sourceDocument'], $expiry['processedBy']]);

		$minus = $ledger->adjustPoints(accountId: 'acc-1', delta: -5, reason: 'correction', processedBy: 'anna');
		self::assertSame(['adjustment', -5, 85], [$minus['type'], $minus['count'], $minus['balanceAfter']]);

		$plus = $ledger->adjustPoints(accountId: 'acc-1', delta: 15, reason: 'goodwill', processedBy: 'anna');
		self::assertSame(100, $plus['balanceAfter']);

		$refund = $ledger->refundPoints(accountId: 'acc-1', amount: 40, redemptionId: 'red-1', processedBy: 'pos');
		self::assertSame(['refund', 40, 140, ['redemptionId' => 'red-1', 'reason' => 'redemption cancelled']], [$refund['type'], $refund['count'], $refund['balanceAfter'], $refund['sourceDocument']]);

		self::assertSame([0, 0, 15, 0], array_column($this->balanceUpdates, 2), 'only credits and positive adjustments add lifetime points');
	}//end testExpiryAdjustmentAndRefund()

	/**
	 * Every movement needs a positive amount (an adjustment a non-zero delta).
	 *
	 * @return void
	 */
	public function testAmountsAreChecked(): void {
		$ledger = $this->ledger();
		$refusals = [
			'Credit amount must be positive.' => static fn () => $ledger->creditPoints(accountId: 'a', amount: 0, ruleId: null, sourceDocument: [], processedBy: 'p'),
			'Debit amount must be positive.' => static fn () => $ledger->debitPoints(accountId: 'a', amount: -1, redemptionId: 'r', sourceDocument: [], processedBy: 'p'),
			'Expiry amount must be positive.' => static fn () => $ledger->expirePoints(accountId: 'a', amount: 0, reason: 'x'),
			'Adjustment delta cannot be zero.' => static fn () => $ledger->adjustPoints(accountId: 'a', delta: 0, reason: 'x', processedBy: 'p'),
			'Refund amount must be positive.' => static fn () => $ledger->refundPoints(accountId: 'a', amount: 0, redemptionId: 'r', processedBy: 'p'),
		];
		foreach ($refusals as $message => $call) {
			try {
				$call();
				self::fail($message . ' was not raised');
			} catch (RuntimeException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}

		self::assertSame([], $this->ledger);
		self::assertSame([], $this->locks);
	}//end testAmountsAreChecked()

	/**
	 * A movement on a locked account is refused with a retry message.
	 *
	 * @return void
	 */
	public function testALockedAccountIsRefused(): void {
		$this->locked = true;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Another points movement on this account is in progress; try again.');
		$this->ledger()->creditPoints(accountId: 'acc-1', amount: 5, ruleId: null, sourceDocument: [], processedBy: 'p');
	}//end testALockedAccountIsRefused()

	/**
	 * An operation id makes a retry return the first entry instead of moving points twice.
	 *
	 * @return void
	 */
	public function testAnOperationMovesPointsOnce(): void {
		$ledger = $this->ledger()->forOperation(operationId: 'order:o-7');

		$first = $ledger->creditPoints(accountId: 'acc-1', amount: 10, ruleId: null, sourceDocument: [], processedBy: 'p');
		$retry = $ledger->creditPoints(accountId: 'acc-1', amount: 10, ruleId: null, sourceDocument: [], processedBy: 'p');

		self::assertSame('order:o-7', $first['operationId']);
		self::assertSame($first['balanceAfter'], $retry['balanceAfter']);
		self::assertCount(1, $this->ledger);
		self::assertSame(110, $this->balance);
	}//end testAnOperationMovesPointsOnce()

	/**
	 * The same operation id for a different movement is refused.
	 *
	 * @return void
	 */
	public function testAnOperationCannotBeReusedForAnotherMovement(): void {
		$ledger = $this->ledger()->forOperation(operationId: 'op-1');
		$ledger->creditPoints(accountId: 'acc-1', amount: 10, ruleId: null, sourceDocument: [], processedBy: 'p');

		$this->expectExceptionMessage('Operation op-1 was already used for another movement.');
		$ledger->creditPoints(accountId: 'acc-1', amount: 11, ruleId: null, sourceDocument: [], processedBy: 'p');
	}//end testAnOperationCannotBeReusedForAnotherMovement()

	/**
	 * forOperation returns a copy; the original ledger keeps no operation id.
	 *
	 * @return void
	 */
	public function testForOperationLeavesTheOriginalAlone(): void {
		$ledger = $this->ledger();
		$ledger->forOperation(operationId: 'op-2');

		self::assertArrayNotHasKey('operationId', $ledger->creditPoints(accountId: 'acc-1', amount: 1, ruleId: null, sourceDocument: [], processedBy: 'p'));
	}//end testForOperationLeavesTheOriginalAlone()

	/**
	 * Without a configured schema nothing is written.
	 *
	 * @return void
	 */
	public function testWithoutConfigurationNothingIsWritten(): void {
		$this->configured = false;

		$this->expectExceptionMessage('PointsLedgerEntry schema is not configured.');
		$this->ledger()->creditPoints(accountId: 'acc-1', amount: 1, ruleId: null, sourceDocument: [], processedBy: 'p');
	}//end testWithoutConfigurationNothingIsWritten()

	/**
	 * The balance is the aggregated sum of counts, rounded; no account or no config is zero.
	 *
	 * @return void
	 */
	public function testTheAggregatedBalance(): void {
		$runner = $this->createMock(AggregationRunner::class);
		$runner->method('runAdhocByRef')->willReturn(['value' => 41.6]);

		self::assertSame(42, $this->ledger(runner: $runner)->getAccountBalance(accountId: 'acc-1'));
		self::assertSame(0, $this->ledger(runner: $runner)->getAccountBalance(accountId: ''));
		self::assertSame(0, $this->ledger()->getAccountBalance(accountId: 'acc-1'));

		$failing = $this->createMock(AggregationRunner::class);
		$failing->method('runAdhocByRef')->willThrowException(new RuntimeException('no aggregation'));
		self::assertSame(0, $this->ledger(runner: $failing)->getAccountBalance(accountId: 'acc-1'));
	}//end testTheAggregatedBalance()

	/**
	 * History is filtered to the window and sorted by moment; programme entries filter by type.
	 *
	 * @return void
	 */
	public function testHistoryAndProgrammeEntries(): void {
		$ledger = $this->ledger();
		$this->ledger = [
			['accountId' => 'acc-1', 'type' => 'credit', 'timestamp' => '2026-11-03T00:00:00Z'],
			['accountId' => 'acc-1', 'type' => 'debit', 'timestamp' => '2026-11-01T00:00:00Z'],
			['accountId' => 'acc-1', 'type' => 'credit', 'timestamp' => '2026-10-01T00:00:00Z'],
			['accountId' => 'acc-2', 'type' => 'credit', 'timestamp' => '2026-11-02T00:00:00Z'],
		];

		self::assertSame(
			['2026-10-01T00:00:00Z', '2026-11-01T00:00:00Z', '2026-11-03T00:00:00Z'],
			array_column($ledger->getLedgerHistory(accountId: 'acc-1'), 'timestamp')
		);
		self::assertSame(
			['2026-11-01T00:00:00Z'],
			array_column($ledger->getLedgerHistory(accountId: 'acc-1', from: '2026-10-15', to: '2026-11-02'), 'timestamp')
		);
		self::assertSame([], $ledger->getLedgerHistory(accountId: ''));

		$credits = $ledger->getLedgerEntriesForProgramme(programmeId: 'prog-1', type: 'credit', from: '2026-11-01');
		self::assertSame(['2026-11-03T00:00:00Z', '2026-11-02T00:00:00Z'], array_column($credits, 'timestamp'));
	}//end testHistoryAndProgrammeEntries()
}//end class
