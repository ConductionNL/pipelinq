<?php

/**
 * Characterisation tests for GiftCardService redemption and serial validation.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\GiftCardService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every outcome of redeemGiftCard(), validateBySerial() and
 * findBySerial(): the refusals (amount, missing, blocked, inactive, expired,
 * wrong PIN), the POS replay and the debit arithmetic.
 */
class GiftCardServiceRedeemGoldenTest extends TestCase {

	/**
	 * The OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Saved payloads, in call order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->saved[] = $object;
				return $this->entity($object);
			}
		);
	}//end setUp()

	/**
	 * An OpenRegister entity serialising to a payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $payload): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($payload);

		return $entity;
	}//end entity()

	/**
	 * Build the service over a config map.
	 *
	 * @param array<string, string> $config The app-config contents.
	 *
	 * @return GiftCardService
	 */
	private function service(array $config = []): GiftCardService {
		$config = array_merge(
			['register' => 'reg', 'giftCard_schema' => 'card', 'giftCardTransaction_schema' => 'tx'],
			$config
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);

		return new GiftCardService($appConfig, $this->createMock(LoggerInterface::class), objectService: $this->objects);
	}//end service()

	/**
	 * A card payload.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function card(array $overrides = []): array {
		return array_merge(
			[
				'@self' => ['id' => 'card-1'],
				'status' => 'active',
				'currentBalance' => 50.0,
				'expiresOn' => '2999-01-01T00:00:00+00:00',
				'pin' => password_hash('1234', PASSWORD_BCRYPT, ['cost' => 4]),
			],
			$overrides
		);
	}//end card()

	/**
	 * Assert that a redemption throws the given message.
	 *
	 * @param array<string, mixed>|null $card    The stored card, or null.
	 * @param string                    $message The expected message.
	 *
	 * @return void
	 */
	private function assertRedeemRefused(?array $card, string $message): void {
		$this->objects->method('find')->willReturn($card === null ? null : $this->entity($card));
		$this->objects->expects($this->never())->method('saveObject');
		try {
			$this->service()->redeemGiftCard('card-1', '1234', 10.0, 'pos-1');
			$this->fail('Expected a refusal: ' . $message);
		} catch (RuntimeException $e) {
			$this->assertSame($message, $e->getMessage());
		}
	}//end assertRedeemRefused()

	/**
	 * Zero and negative amounts are refused before the card is read.
	 *
	 * @return void
	 */
	public function testNonPositiveAmountIsRefusedFirst(): void {
		$this->objects->expects($this->never())->method('find');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Redemption amount must be positive.');
		$this->service()->redeemGiftCard('card-1', '1234', 0.0);
	}//end testNonPositiveAmountIsRefusedFirst()

	/**
	 * A missing card is refused.
	 *
	 * @return void
	 */
	public function testMissingCardIsRefused(): void {
		$this->assertRedeemRefused(null, 'Gift card not found.');
	}//end testMissingCardIsRefused()

	/**
	 * A blocked card is refused, even with the right PIN.
	 *
	 * @return void
	 */
	public function testBlockedCardIsRefused(): void {
		$this->assertRedeemRefused($this->card(['status' => 'blocked']), 'Gift card is blocked.');
	}//end testBlockedCardIsRefused()

	/**
	 * An issued (not yet active) card is refused.
	 *
	 * @return void
	 */
	public function testInactiveCardIsRefused(): void {
		$this->assertRedeemRefused($this->card(['status' => 'issued']), 'Gift card is not active.');
	}//end testInactiveCardIsRefused()

	/**
	 * An expired card is refused before the PIN is checked.
	 *
	 * @return void
	 */
	public function testExpiredCardIsRefused(): void {
		$this->assertRedeemRefused(
			$this->card(['expiresOn' => '2000-01-01T00:00:00+00:00', 'pin' => '']),
			'Gift card has expired.'
		);
	}//end testExpiredCardIsRefused()

	/**
	 * A wrong PIN is refused.
	 *
	 * @return void
	 */
	public function testWrongPinIsRefused(): void {
		$this->assertRedeemRefused($this->card(['pin' => password_hash('9999', PASSWORD_BCRYPT, ['cost' => 4])]), 'Invalid PIN.');
	}//end testWrongPinIsRefused()

	/**
	 * A card without a stored PIN hash is refused.
	 *
	 * @return void
	 */
	public function testMissingPinHashIsRefused(): void {
		$this->assertRedeemRefused($this->card(['pin' => '']), 'Invalid PIN.');
	}//end testMissingPinHashIsRefused()

	/**
	 * A partial debit keeps the card active and logs a partial_redeem.
	 *
	 * @return void
	 */
	public function testPartialRedemption(): void {
		$this->objects->method('find')->willReturn($this->entity($this->card()));
		$this->objects->method('findAll')->willReturn([]);

		$result = $this->service()->redeemGiftCard('card-1', '1234', 20.0, 'pos-1');

		$this->assertSame(['amountApplied' => 20.0, 'balanceAfter' => 30.0, 'changeAmount' => 0.0, 'status' => 'active'], $result);
		$this->assertSame(30.0, $this->saved[0]['currentBalance']);
		$this->assertSame('active', $this->saved[0]['status']);
		$this->assertSame('partial_redeem', $this->saved[1]['type']);
		$this->assertSame(20.0, $this->saved[1]['amount']);
		$this->assertSame(30.0, $this->saved[1]['balanceAfter']);
		$this->assertSame('pos-1', $this->saved[1]['posTransactionId']);
	}//end testPartialRedemption()

	/**
	 * Spending exactly the balance depletes the card and logs a redeem.
	 *
	 * @return void
	 */
	public function testExactRedemptionDepletes(): void {
		$this->objects->method('find')->willReturn($this->entity($this->card()));

		$result = $this->service()->redeemGiftCard('card-1', '1234', 50.0);

		$this->assertSame(['amountApplied' => 50.0, 'balanceAfter' => 0.0, 'changeAmount' => 0.0, 'status' => 'depleted'], $result);
		$this->assertSame(0.0, $this->saved[0]['currentBalance']);
		$this->assertSame('depleted', $this->saved[0]['status']);
		$this->assertSame('redeem', $this->saved[1]['type']);
	}//end testExactRedemptionDepletes()

	/**
	 * Asking more than the balance applies the balance and answers change.
	 *
	 * @return void
	 */
	public function testOverRedemptionAnswersChange(): void {
		$this->objects->method('find')->willReturn($this->entity($this->card(['currentBalance' => 12.5])));

		$result = $this->service()->redeemGiftCard('card-1', '1234', 20.0, '');

		$this->assertSame(['amountApplied' => 12.5, 'balanceAfter' => 0.0, 'changeAmount' => 7.5, 'status' => 'depleted'], $result);
		$this->assertSame('partial_redeem', $this->saved[1]['type']);
	}//end testOverRedemptionAnswersChange()

	/**
	 * A POS retry replays the recorded redemption without a second debit.
	 *
	 * @return void
	 */
	public function testPosRetryReplaysWithoutDebit(): void {
		$this->objects->method('find')->willReturn($this->entity($this->card()));
		$this->objects->expects($this->once())->method('findAll')
			->with(
				[
					'filters' => ['giftCardId' => 'card-1', 'posTransactionId' => 'pos-1', 'register' => 'reg', 'schema' => 'tx'],
					'limit' => 1,
				]
			)
			->willReturn(
				[
					['type' => 'block', 'amount' => 1.0, 'balanceAfter' => 1.0],
					['type' => 'partial_redeem', 'amount' => 20.0, 'balanceAfter' => 30.0],
				]
			);
		$this->objects->expects($this->never())->method('saveObject');

		$result = $this->service()->redeemGiftCard('card-1', '1234', 20.0, 'pos-1');

		$this->assertSame(['amountApplied' => 20.0, 'balanceAfter' => 30.0, 'changeAmount' => 0.0, 'status' => 'active'], $result);
	}//end testPosRetryReplaysWithoutDebit()

	/**
	 * A replayed redemption from an entity row reports a depleted card.
	 *
	 * @return void
	 */
	public function testReplayFromEntityRowReportsDepleted(): void {
		$row = new class implements \JsonSerializable {
			/**
			 * Serialise.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['type' => 'redeem', 'amount' => 50, 'balanceAfter' => 0];
			}
		};
		$this->objects->method('find')->willReturn($this->entity($this->card()));
		$this->objects->method('findAll')->willReturn(['x' => 'not-a-row', 'y' => $row]);
		$this->objects->expects($this->never())->method('saveObject');

		$result = $this->service()->redeemGiftCard('card-1', '1234', 50.0, 'pos-1');

		$this->assertSame(['amountApplied' => 50.0, 'balanceAfter' => 0.0, 'changeAmount' => 0.0, 'status' => 'depleted'], $result);
	}//end testReplayFromEntityRowReportsDepleted()

	/**
	 * A failing idempotency lookup falls through to a normal debit.
	 *
	 * @return void
	 */
	public function testFailedReplayLookupDebits(): void {
		$this->objects->method('find')->willReturn($this->entity($this->card()));
		$this->objects->method('findAll')->willThrowException(new RuntimeException('down'));

		$result = $this->service()->redeemGiftCard('card-1', '1234', 5.0, 'pos-1');

		$this->assertSame(45.0, $result['balanceAfter']);
	}//end testFailedReplayLookupDebits()

	/**
	 * An unconfigured transaction schema skips the lookup and debits.
	 *
	 * @return void
	 */
	public function testUnconfiguredTransactionSchemaSkipsLookup(): void {
		$this->objects->method('find')->willReturn($this->entity($this->card()));
		$this->objects->expects($this->never())->method('findAll');

		$result = $this->service(['giftCardTransaction_schema' => ''])->redeemGiftCard('card-1', '1234', 5.0, 'pos-1');

		$this->assertSame(45.0, $result['balanceAfter']);
		$this->assertCount(1, $this->saved);
	}//end testUnconfiguredTransactionSchemaSkipsLookup()

	/**
	 * Validate by serial: every refusal and the valid answer.
	 *
	 * @return void
	 */
	public function testValidateBySerialOutcomes(): void {
		$cards = [
			'blocked' => $this->card(['status' => 'blocked', 'expiresOn' => '', 'currentBalance' => 3]),
			'expired' => $this->card(['expiresOn' => '2000-01-01T00:00:00+00:00', 'currentBalance' => 4]),
			'wrongpin' => $this->card(['pin' => password_hash('9999', PASSWORD_BCRYPT, ['cost' => 4])]),
			'ok' => $this->card(),
			'noexpiry' => $this->card(['expiresOn' => '']),
		];
		$this->objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($cards): array {
				$serial = $config['filters']['serial'];
				return isset($cards[$serial]) ? ['k' => $cards[$serial]] : [];
			}
		);
		$service = $this->service();

		$this->assertSame(
			['valid' => false, 'balance' => 0.0, 'expiryDate' => null, 'giftCardId' => null, 'reason' => 'Not found'],
			$service->validateBySerial('missing', '1234')
		);
		$this->assertSame(
			['valid' => false, 'balance' => 3.0, 'expiryDate' => '', 'giftCardId' => 'card-1', 'reason' => 'Blocked'],
			$service->validateBySerial('blocked', '1234')
		);
		$this->assertSame(
			['valid' => false, 'balance' => 4.0, 'expiryDate' => '2000-01-01T00:00:00+00:00', 'giftCardId' => 'card-1', 'reason' => 'Expired'],
			$service->validateBySerial('expired', '1234')
		);
		$this->assertSame(
			['valid' => false, 'balance' => 0.0, 'expiryDate' => null, 'giftCardId' => null, 'reason' => 'Invalid PIN'],
			$service->validateBySerial('wrongpin', '1234')
		);
		$this->assertSame(
			['valid' => true, 'balance' => 50.0, 'expiryDate' => '2999-01-01T00:00:00+00:00', 'giftCardId' => 'card-1', 'reason' => null],
			$service->validateBySerial('ok', '1234')
		);
		$this->assertSame(
			['valid' => true, 'balance' => 50.0, 'expiryDate' => null, 'giftCardId' => 'card-1', 'reason' => null],
			$service->validateBySerial('noexpiry', '1234')
		);
	}//end testValidateBySerialOutcomes()

	/**
	 * Find by serial: the query, an empty serial, a failing read and a hit.
	 *
	 * @return void
	 */
	public function testFindBySerial(): void {
		$this->objects->expects($this->exactly(2))->method('findAll')
			->with(['filters' => ['serial' => 'S-1', 'register' => 'reg', 'schema' => 'card'], 'limit' => 1])
			->willReturnOnConsecutiveCalls(['a' => ['serial' => 'S-1']], []);
		$service = $this->service();

		$this->assertNull($service->findBySerial(''));
		$this->assertSame(['serial' => 'S-1'], $service->findBySerial('S-1'));
		$this->assertNull($service->findBySerial('S-1'));
	}//end testFindBySerial()

	/**
	 * A failing serial read answers null.
	 *
	 * @return void
	 */
	public function testFindBySerialSwallowsReadFailure(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('down'));

		$this->assertNull($this->service()->findBySerial('S-1'));
		$this->assertNull($this->service(['giftCard_schema' => ''])->findBySerial('S-1'));
	}//end testFindBySerialSwallowsReadFailure()
	/**
	 * Activation: missing, already active, issued and refused statuses.
	 *
	 * @return void
	 */
	public function testActivationOutcomes(): void {
		$cards = [
			'missing' => null,
			'active' => $this->card(),
			'issued' => $this->card(['status' => 'issued']),
			'blocked' => $this->card(['status' => 'blocked']),
		];
		$this->objects->method('find')->willReturnCallback(
			function (string $id) use ($cards): ?ObjectEntityInterface {
				return $cards[$id] === null ? null : $this->entity($cards[$id]);
			}
		);
		$service = $this->service();

		$this->assertNull($service->activateGiftCard('missing'));
		$this->assertSame($cards['active'], $service->activateGiftCard('active', 'pos-1'));
		$this->assertSame([], $this->saved);
		$this->assertSame('active', $service->activateGiftCard('issued')['status']);
		$this->assertCount(1, $this->saved);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Gift card cannot be activated from status "blocked".');
		$service->activateGiftCard('blocked');
	}//end testActivationOutcomes()
}//end class
