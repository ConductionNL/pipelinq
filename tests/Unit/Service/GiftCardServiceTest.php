<?php

/**
 * Unit tests for GiftCardService.
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
use OCA\Pipelinq\Service\GiftCardRedemption;
use OCA\Pipelinq\Service\GiftCardService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * GiftCardService over an in-memory OpenRegister: issue, activate, redeem,
 * refund, block and validate a card, and the ledger rows each step writes.
 */
class GiftCardServiceTest extends TestCase {

	/**
	 * Gift cards by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $cards = [];

	/**
	 * Every gift-card transaction row written, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $ledger = [];

	/**
	 * Whether the register and schemas are configured.
	 *
	 * @var bool
	 */
	private bool $configured = true;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * A service over the in-memory store.
	 *
	 * @return GiftCardService
	 */
	private function service(): GiftCardService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default): string => ($this->configured === true ? $key . '-id' : '')
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntity {
				if (isset($this->cards[(string)$id]) === false) {
					return null;
				}

				return self::entity(uuid: (string)$id, payload: $this->cards[(string)$id]);
			}
		);
		$objects->method('findAll')->willReturnCallback(fn (array $config=[]): array => $this->search(filters: $config['filters']));
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): ObjectEntity {
				if ($schema === 'giftCardTransaction_schema-id') {
					$this->ledger[] = $object;
					return self::entity(uuid: 'tx-' . count($this->ledger), payload: $object);
				}

				$uuid = ($uuid ?? 'card-' . (count($this->cards) + 1));
				unset($object['@self'], $object['id']);
				$this->cards[$uuid] = $object;
				return self::entity(uuid: $uuid, payload: $object);
			}
		);

		$this->logger = $this->createMock(LoggerInterface::class);

		return new GiftCardService(appConfig: $config, logger: $this->logger, objectService: $objects);
	}//end service()

	/**
	 * An OpenRegister entity holding a payload.
	 *
	 * @param string               $uuid    The object uuid.
	 * @param array<string, mixed> $payload The object data.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);

		return $entity;
	}//end entity()

	/**
	 * Cards or ledger rows matching the filters.
	 *
	 * @param array<string, mixed> $filters The findAll filters.
	 *
	 * @return list<ObjectEntity>
	 */
	private function search(array $filters): array {
		$found = [];
		if ($filters['schema'] === 'giftCardTransaction_schema-id') {
			foreach ($this->ledger as $index => $row) {
				if ($row['giftCardId'] === $filters['giftCardId'] && $row['posTransactionId'] === $filters['posTransactionId']) {
					$found[] = self::entity(uuid: 'tx-' . ($index + 1), payload: $row);
				}
			}

			return $found;
		}

		foreach ($this->cards as $uuid => $card) {
			if (($card['serial'] ?? null) === ($filters['serial'] ?? null) || ($card['customerId'] ?? null) === ($filters['customerId'] ?? false)) {
				$found[] = self::entity(uuid: $uuid, payload: $card);
			}
		}

		return $found;
	}//end search()

	/**
	 * An active card with a balance and a known PIN, stored under card-1.
	 *
	 * @param float               $balance The current balance.
	 * @param array<string,mixed> $extra   Fields to override.
	 *
	 * @return string The card uuid.
	 */
	private function storedCard(float $balance, array $extra=[]): string {
		$this->cards['card-1'] = array_merge(
			[
				'serial' => 'GC-00000001',
				'pin' => password_hash('123456', PASSWORD_BCRYPT, ['cost' => 4]),
				'initialBalance' => $balance,
				'currentBalance' => $balance,
				'status' => 'active',
				'expiresOn' => '2099-01-01T00:00:00+00:00',
			],
			$extra
		);

		return 'card-1';
	}//end storedCard()

	/**
	 * Issuing stores an issued card with a serial and a hashed six-digit PIN, and ledgers the issue.
	 *
	 * @return void
	 */
	public function testIssuingStoresTheCardAndLedgersTheIssue(): void {
		$issued = $this->service()->issueGiftCard(programmeId: 'prog-1', initialBalance: 50.0, expiryDays: 30, channel: 'promotion', issuedIn: 'store-1');

		self::assertMatchesRegularExpression('/^\d{6}$/', $issued['pin']);
		$card = $issued['card'];
		self::assertMatchesRegularExpression('/^GC-\d{8}$/', $card['serial']);
		self::assertSame('issued', $card['status']);
		self::assertSame(50.0, $card['currentBalance']);
		self::assertSame('EUR', $card['currency']);
		self::assertSame('promotion', $card['channel']);
		self::assertSame('store-1', $card['issuedIn']);
		self::assertNotSame($issued['pin'], $card['pin']);
		self::assertTrue(password_verify($issued['pin'], $card['pin']));
		self::assertCount(1, $this->ledger);
		self::assertSame('issue', $this->ledger[0]['type']);
		self::assertSame(50.0, $this->ledger[0]['balanceAfter']);
		self::assertSame('card-1', $this->ledger[0]['giftCardId']);
	}//end testIssuingStoresTheCardAndLedgersTheIssue()

	/**
	 * A card must start with a positive balance.
	 *
	 * @return void
	 */
	public function testIssuingWithoutABalanceIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Initial balance must be positive.');

		$this->service()->issueGiftCard(programmeId: null, initialBalance: 0.0);
	}//end testIssuingWithoutABalanceIsRefused()

	/**
	 * Without a configured schema a card cannot be stored.
	 *
	 * @return void
	 */
	public function testIssuingWithoutConfigurationIsRefused(): void {
		$this->configured = false;
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('GiftCard schema is not configured.');

		$this->service()->issueGiftCard(programmeId: null, initialBalance: 10.0);
	}//end testIssuingWithoutConfigurationIsRefused()

	/**
	 * Activating an issued card makes it active; an active card stays as it is; an unknown card is null.
	 *
	 * @return void
	 */
	public function testActivation(): void {
		$service = $this->service();
		$this->storedCard(balance: 20.0, extra: ['status' => 'issued']);

		self::assertSame('active', $service->activateGiftCard(giftCardId: 'card-1')['status']);
		self::assertSame('active', $this->cards['card-1']['status']);
		self::assertSame('active', $service->activateGiftCard(giftCardId: 'card-1')['status']);
		self::assertNull($service->activateGiftCard(giftCardId: 'missing'));
		self::assertSame([], $this->ledger);
	}//end testActivation()

	/**
	 * A blocked card is not activated silently.
	 *
	 * @return void
	 */
	public function testABlockedCardIsNotActivated(): void {
		$service = $this->service();
		$this->storedCard(balance: 20.0, extra: ['status' => 'blocked']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Gift card cannot be activated from status "blocked".');
		$service->activateGiftCard(giftCardId: 'card-1');
	}//end testABlockedCardIsNotActivated()

	/**
	 * A partial redemption lowers the balance and ledgers the debit against the POS transaction.
	 *
	 * @return void
	 */
	public function testAPartialRedemption(): void {
		$service = $this->service();
		$this->storedCard(balance: 40.0);

		$result = $service->redeemGiftCard(giftCardId: 'card-1', pin: '123456', amount: 15.0, posTransactionId: 'pos-1');

		self::assertSame(['amountApplied' => 15.0, 'balanceAfter' => 25.0, 'changeAmount' => 0.0, 'status' => 'active'], $result);
		self::assertSame(25.0, $this->cards['card-1']['currentBalance']);
		self::assertSame('partial_redeem', $this->ledger[0]['type']);
		self::assertSame('pos-1', $this->ledger[0]['posTransactionId']);
	}//end testAPartialRedemption()

	/**
	 * Redeeming more than the balance empties the card and returns the rest as change.
	 *
	 * @return void
	 */
	public function testRedeemingMoreThanTheBalanceDepletesTheCard(): void {
		$service = $this->service();
		$this->storedCard(balance: 10.0);

		$result = $service->redeemGiftCard(giftCardId: 'card-1', pin: '123456', amount: 12.5);

		self::assertSame(['amountApplied' => 10.0, 'balanceAfter' => 0.0, 'changeAmount' => 2.5, 'status' => 'depleted'], $result);
		self::assertSame('depleted', $this->cards['card-1']['status']);
	}//end testRedeemingMoreThanTheBalanceDepletesTheCard()

	/**
	 * A POS retry of the same transaction replays the first result and does not debit twice.
	 *
	 * @return void
	 */
	public function testAPosRetryDoesNotDebitTwice(): void {
		$service = $this->service();
		$this->storedCard(balance: 30.0);

		$first = $service->redeem(redemption: new GiftCardRedemption(giftCardId: 'card-1', pin: '123456', amount: 10.0, posTransactionId: 'pos-9'));
		$retry = $service->redeem(redemption: new GiftCardRedemption(giftCardId: 'card-1', pin: '123456', amount: 10.0, posTransactionId: 'pos-9'));

		self::assertSame(20.0, $this->cards['card-1']['currentBalance']);
		self::assertCount(1, $this->ledger);
		self::assertSame($first['balanceAfter'], $retry['balanceAfter']);
		self::assertSame(10.0, $retry['amountApplied']);
		self::assertSame('active', $retry['status']);
	}//end testAPosRetryDoesNotDebitTwice()

	/**
	 * Redemptions without a POS transaction id are never replayed.
	 *
	 * @return void
	 */
	public function testRedemptionsWithoutAPosIdBothDebit(): void {
		$service = $this->service();
		$this->storedCard(balance: 30.0);

		$service->redeemGiftCard(giftCardId: 'card-1', pin: '123456', amount: 5.0);
		$service->redeemGiftCard(giftCardId: 'card-1', pin: '123456', amount: 5.0);

		self::assertSame(20.0, $this->cards['card-1']['currentBalance']);
		self::assertCount(2, $this->ledger);
	}//end testRedemptionsWithoutAPosIdBothDebit()

	/**
	 * Every refusal before a debit: no amount, unknown card, wrong PIN, blocked, inactive, expired.
	 *
	 * @return array<string, array{0: float, 1: string, 2: array<string, mixed>|null, 3: string}>
	 */
	public static function refusals(): array {
		return [
			'no amount' => [0.0, '123456', [], 'Redemption amount must be positive.'],
			'unknown card' => [5.0, '123456', null, 'Gift card not found.'],
			'wrong pin' => [5.0, '000000', [], 'Invalid PIN.'],
			'blocked' => [5.0, '123456', ['status' => 'blocked'], 'Gift card is blocked.'],
			'issued, not active' => [5.0, '123456', ['status' => 'issued'], 'Gift card is not active.'],
			'expired' => [5.0, '123456', ['expiresOn' => '2001-01-01T00:00:00+00:00'], 'Gift card has expired.'],
		];
	}//end refusals()

	/**
	 * A refused redemption changes nothing.
	 *
	 * @param float                     $amount  The amount.
	 * @param string                    $pin     The PIN given.
	 * @param array<string, mixed>|null $card    Card overrides, or null for no card.
	 * @param string                    $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testARefusedRedemptionChangesNothing(float $amount, string $pin, ?array $card, string $message): void {
		$service = $this->service();
		if ($card !== null) {
			$this->storedCard(balance: 10.0, extra: $card);
		}

		try {
			$service->redeemGiftCard(giftCardId: 'card-1', pin: $pin, amount: $amount);
			self::fail('the redemption went through');
		} catch (RuntimeException $e) {
			self::assertSame($message, $e->getMessage());
		}

		self::assertSame([], $this->ledger);
		if ($card !== null) {
			self::assertSame(10.0, $this->cards['card-1']['currentBalance']);
		}
	}//end testARefusedRedemptionChangesNothing()

	/**
	 * A refund raises the balance, revives a depleted card and ledgers the refund.
	 *
	 * @return void
	 */
	public function testARefundRevivesADepletedCard(): void {
		$service = $this->service();
		$this->storedCard(balance: 0.0, extra: ['status' => 'depleted']);

		$saved = $service->refundGiftCard(giftCardId: 'card-1', amount: 7.25, posTransactionId: 'pos-2');

		self::assertSame(7.25, $saved['currentBalance']);
		self::assertSame('active', $saved['status']);
		self::assertSame('refund', $this->ledger[0]['type']);
		self::assertSame(7.25, $this->ledger[0]['balanceAfter']);
		self::assertSame('pos-2', $this->ledger[0]['posTransactionId']);
	}//end testARefundRevivesADepletedCard()

	/**
	 * A refund needs a positive amount and an existing card.
	 *
	 * @return void
	 */
	public function testARefundIsRefusedWithoutAmountOrCard(): void {
		$service = $this->service();

		try {
			$service->refundGiftCard(giftCardId: 'card-1', amount: -1.0);
			self::fail('a negative refund went through');
		} catch (RuntimeException $e) {
			self::assertSame('Refund amount must be positive.', $e->getMessage());
		}

		$this->expectExceptionMessage('Gift card not found.');
		$service->refundGiftCard(giftCardId: 'missing', amount: 1.0);
	}//end testARefundIsRefusedWithoutAmountOrCard()

	/**
	 * Blocking stores the status, ledgers a zero-amount block and logs the reason.
	 *
	 * @return void
	 */
	public function testBlockingACard(): void {
		$service = $this->service();
		$this->storedCard(balance: 12.0);
		$this->logger->expects(self::once())->method('info')->with('Pipelinq: gift card blocked', ['giftCardId' => 'card-1', 'reason' => 'stolen']);

		self::assertSame('blocked', $service->blockGiftCard(giftCardId: 'card-1', reason: 'stolen')['status']);
		self::assertSame('block', $this->ledger[0]['type']);
		self::assertSame(0.0, $this->ledger[0]['amount']);
		self::assertSame(12.0, $this->ledger[0]['balanceAfter']);
		self::assertNull($service->blockGiftCard(giftCardId: 'missing', reason: 'x'));
	}//end testBlockingACard()

	/**
	 * Card details need the right PIN.
	 *
	 * @return void
	 */
	public function testCardDetailsNeedThePin(): void {
		$service = $this->service();
		$this->storedCard(balance: 9.5);

		self::assertSame(
			['balance' => 9.5, 'status' => 'active', 'expiryDate' => '2099-01-01T00:00:00+00:00'],
			$service->getCardDetails(giftCardId: 'card-1', pin: '123456')
		);

		$this->expectExceptionMessage('Invalid PIN.');
		$service->getCardDetails(giftCardId: 'card-1', pin: '654321');
	}//end testCardDetailsNeedThePin()

	/**
	 * Validation by serial: valid, wrong PIN, blocked, expired and unknown.
	 *
	 * @return void
	 */
	public function testValidationBySerial(): void {
		$service = $this->service();
		$this->storedCard(balance: 5.0);

		$valid = $service->validateBySerial(serial: 'GC-00000001', pin: '123456');
		self::assertTrue($valid['valid']);
		self::assertSame(5.0, $valid['balance']);
		self::assertSame('card-1', $valid['giftCardId']);
		self::assertNull($valid['reason']);

		self::assertSame(
			['valid' => false, 'balance' => 0.0, 'expiryDate' => null, 'giftCardId' => null, 'reason' => 'Invalid PIN'],
			$service->validateBySerial(serial: 'GC-00000001', pin: '999999')
		);
		self::assertSame('Not found', $service->validateBySerial(serial: 'GC-99999999', pin: '123456')['reason']);

		$this->cards['card-1']['status'] = 'blocked';
		$blocked = $service->validateBySerial(serial: 'GC-00000001', pin: '999999');
		self::assertFalse($blocked['valid']);
		self::assertSame('Blocked', $blocked['reason']);
		self::assertSame('2099-01-01T00:00:00+00:00', $blocked['expiryDate']);

		$this->cards['card-1']['status'] = 'active';
		$this->cards['card-1']['expiresOn'] = '2001-01-01T00:00:00+00:00';
		self::assertSame('Expired', $service->validateBySerial(serial: 'GC-00000001', pin: '123456')['reason']);
	}//end testValidationBySerial()

	/**
	 * Without configuration nothing is read: no card, no serial match, no list.
	 *
	 * @return void
	 */
	public function testNothingIsReadUnscoped(): void {
		$service = $this->service();
		$this->storedCard(balance: 5.0, extra: ['customerId' => 'klant-1']);
		$this->configured = false;

		self::assertNull($service->getCard(giftCardId: 'card-1'));
		self::assertNull($service->findBySerial(serial: 'GC-00000001'));
		self::assertSame([], $service->listForKlant(customerId: 'klant-1'));
	}//end testNothingIsReadUnscoped()

	/**
	 * A customer's cards are listed as arrays.
	 *
	 * @return void
	 */
	public function testACustomersCardsAreListed(): void {
		$service = $this->service();
		$this->storedCard(balance: 5.0, extra: ['customerId' => 'klant-1']);

		$list = $service->listForKlant(customerId: 'klant-1');

		self::assertCount(1, $list);
		self::assertSame('GC-00000001', $list[0]['serial']);
		self::assertSame([], $service->listForKlant(customerId: 'klant-2'));
	}//end testACustomersCardsAreListed()
}//end class
