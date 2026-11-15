<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\PosCart;

use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosCart\PosCartAccess;
use OCA\Pipelinq\Service\PosCart\PosCartObjects;
use OCA\Pipelinq\Service\PosCart\PosCartRepricer;
use OCA\Pipelinq\Service\PosCart\PosLinePricer;
use OCA\Pipelinq\Service\PosTransactionService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Repricing a sale checks access first, rewrites only the lines whose stored
 * figures differ from the server's, and answers with the recalculated totals
 * and the lines as they now stand.
 *
 * @covers \OCA\Pipelinq\Service\PosCart\PosCartRepricer
 * @uses   \OCA\Pipelinq\Service\PosCart\PosLinePricer
 * @uses   \OCA\Pipelinq\Service\PosCart\PosCartAccess
 * @uses   \OCA\Pipelinq\Lifecycle\PosAccessPolicy
 * @uses   \OCA\Pipelinq\Lifecycle\ConfiguredGroup
 * @uses   \OCA\Pipelinq\Lifecycle\ConfiguredGroupGate
 * @uses   \OCA\Pipelinq\Service\PosTransactionService
 */
class PosCartRepricerTest extends TestCase {
	/**
	 * A line whose stored figures are already the server's (excl: 2 x 10 at 21%).
	 */
	private const PRICED = ['id' => 'l-1', 'transaction' => 'tx-1', 'quantity' => 2.0, 'unitPrice' => 10.0, 'discount' => 0.0, 'taxRate' => 21.0, 'taxAmount' => 4.2, 'lineTotal' => 24.2];

	/**
	 * The mocked sale store.
	 *
	 * @var PosCartObjects&MockObject
	 */
	private PosCartObjects $objects;

	/**
	 * The transaction service, with only the persisted totals stubbed.
	 *
	 * @var PosTransactionService&MockObject
	 */
	private PosTransactionService $pos;

	/**
	 * The lines saved, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	protected function setUp(): void {
		$this->saved   = [];
		$this->objects = $this->createMock(PosCartObjects::class);
		$this->objects->method('saveLine')->willReturnCallback(function (array $line): void {
			$this->saved[] = $line;
		});
		$this->pos = $this->createPartialMock(PosTransactionService::class, ['recalculateTotals']);
	}//end setUp()

	public function testOnlyLinesWhoseFiguresDifferAreSavedAndTheTotalsAreReturned(): void {
		$stale   = array_merge(self::PRICED, ['id' => 'l-2', 'lineTotal' => 99.0]);
		$missing = ['id' => 'l-3', 'transaction' => 'tx-1', 'quantity' => 1, 'unitPrice' => 5, 'taxRate' => 9];
		$this->sale(status: 'draft', priceMode: 'excl', lines: [self::PRICED, $stale, $missing]);
		$this->pos->expects($this->once())->method('recalculateTotals')->with('tx-1')->willReturn(['total' => 53.65]);

		$result = $this->repricer(uid: 'pieter')->reprice(transactionId: 'tx-1');

		$this->assertSame(['l-2', 'l-3'], array_column($this->saved, 'id'));
		$this->assertSame(24.2, $this->saved[0]['lineTotal']);
		$this->assertSame(['quantity' => 1.0, 'unitPrice' => 5.0, 'taxRate' => 9.0, 'discount' => 0.0, 'taxAmount' => 0.45, 'lineTotal' => 5.45], array_intersect_key($this->saved[1], array_flip(PosLinePricer::FIGURES)));
		$this->assertSame(['total' => 53.65], $result['transaction']);
		$this->assertSame([self::PRICED, $stale, $missing], $result['lines']);
	}//end testOnlyLinesWhoseFiguresDifferAreSavedAndTheTotalsAreReturned()

	public function testTheSalesPriceModeIsAppliedToEveryLine(): void {
		$this->sale(status: 'parked', priceMode: ' INCL ', lines: [self::PRICED]);
		$this->pos->method('recalculateTotals')->willReturn([]);

		$this->repricer(uid: 'pieter')->reprice(transactionId: 'tx-1');

		$this->assertCount(1, $this->saved);
		$this->assertSame(3.47, $this->saved[0]['taxAmount']);
		$this->assertSame(20.0, $this->saved[0]['lineTotal']);
	}//end testTheSalesPriceModeIsAppliedToEveryLine()

	public function testASaleWithNoPriceModeIsPricedExclusive(): void {
		$this->sale(status: 'draft', priceMode: null, lines: [self::PRICED]);
		$this->pos->method('recalculateTotals')->willReturn([]);

		$this->repricer(uid: 'pieter')->reprice(transactionId: 'tx-1');

		$this->assertSame([], $this->saved);
	}//end testASaleWithNoPriceModeIsPricedExclusive()

	public function testSomeoneWithoutAccessChangesNothing(): void {
		$this->sale(status: 'draft', priceMode: 'excl', lines: [['id' => 'l-9', 'quantity' => 1]]);
		$this->pos->expects($this->never())->method('recalculateTotals');

		try {
			$this->repricer(uid: 'mallory')->reprice(transactionId: 'tx-1');
			$this->fail('A stranger repriced the sale');
		} catch (OCSForbiddenException) {
			$this->assertSame([], $this->saved);
		}
	}//end testSomeoneWithoutAccessChangesNothing()

	public function testAClosedSaleChangesNothing(): void {
		$this->sale(status: 'confirmed', priceMode: 'excl', lines: [['id' => 'l-9', 'quantity' => 1]]);
		$this->pos->expects($this->never())->method('recalculateTotals');

		try {
			$this->repricer(uid: 'pieter')->reprice(transactionId: 'tx-1');
			$this->fail('A confirmed sale was repriced');
		} catch (OCSBadRequestException) {
			$this->assertSame([], $this->saved);
		}
	}//end testAClosedSaleChangesNothing()

	/**
	 * Stub the sale tx-1, cashier pieter.
	 *
	 * @param string                           $status    The sale status.
	 * @param string|null                      $priceMode The sale's price mode (null: unset).
	 * @param array<int, array<string, mixed>> $lines     The stored lines.
	 */
	private function sale(string $status, ?string $priceMode, array $lines): void {
		$sale = ['id' => 'tx-1', 'cashier' => 'pieter', 'status' => $status];
		if ($priceMode !== null) {
			$sale['priceMode'] = $priceMode;
		}

		$this->objects->method('transaction')->with('tx-1')->willReturn($sale);
		$this->objects->method('lines')->with('tx-1')->willReturn($lines);
	}//end sale()

	private function repricer(string $uid): PosCartRepricer {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturn(false);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$policy = new PosAccessPolicy(appConfig: $appConfig, groupManager: $groups);

		return new PosCartRepricer(
			pricer: new PosLinePricer(pos: $this->pos, objects: $this->objects),
			access: new PosCartAccess(policy: $policy, userSession: $session),
		);
	}//end repricer()
}//end class
