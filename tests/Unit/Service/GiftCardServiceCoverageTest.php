<?php

/**
 * Coverage tests for GiftCardService: issuance, refund, block, holder
 * details, GDPR listing and the transaction-log failure path.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\GiftCardService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Exercises the GiftCardService lifecycle against mocked boundaries.
 *
 * @covers \OCA\Pipelinq\Service\GiftCardService
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class GiftCardServiceCoverageTest extends TestCase {

	/**
	 * The OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Saved calls, in order: [object, register, schema, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: mixed, 2: mixed, 3: ?string}>
	 */
	private array $saved = [];

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Record every save and answer the payload as an entity.
	 *
	 * @param string|null $assignedId An id to add as @self.id on card saves, or null.
	 *
	 * @return void
	 */
	private function recordSaves(?string $assignedId = null): void {
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, $register, $schema, ?string $uuid) use ($assignedId): ObjectEntityInterface {
				$this->saved[] = [$object, $register, $schema, $uuid];
				if ($assignedId !== null && $schema === 'card') {
					$object['@self'] = ['id' => $assignedId];
				}

				return $this->entity(payload: $object);
			}
		);
	}//end recordSaves()

	/**
	 * An OpenRegister entity serialising to a payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $payload): ObjectEntityInterface {
		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($payload);

		return $entity;
	}//end entity()

	/**
	 * Build the service over a config map.
	 *
	 * @param array<string, string> $config The app-config overrides.
	 *
	 * @return GiftCardService
	 */
	private function service(array $config = []): GiftCardService {
		$config = array_merge(
			['register' => 'reg', 'giftCard_schema' => 'card', 'giftCardTransaction_schema' => 'tx'],
			$config
		);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);

		return new GiftCardService(appConfig: $appConfig, logger: $this->logger, objectService: $this->objects);
	}//end service()

	/**
	 * A stored card payload.
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
				'currentBalance' => 20.0,
				'expiresOn' => '2999-01-01T00:00:00+00:00',
				'pin' => password_hash('1234', PASSWORD_BCRYPT, ['cost' => 4]),
			],
			$overrides
		);
	}//end card()

	/**
	 * An ISO timestamp offset from now (UTC).
	 *
	 * @param string $modifier The relative modifier.
	 *
	 * @return string
	 */
	private function stamp(string $modifier): string {
		return (new DateTimeImmutable($modifier, new DateTimeZone('UTC')))->format('c');
	}//end stamp()

	/**
	 * A non-positive initial balance is refused before anything is read.
	 *
	 * @return void
	 */
	public function testIssueRefusesNonPositiveBalance(): void {
		$this->objects->expects($this->never())->method('findAll');
		$this->objects->expects($this->never())->method('saveObject');
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Initial balance must be positive.');
		$this->service()->issueGiftCard(programmeId: 'prog-1', initialBalance: 0.0);
	}//end testIssueRefusesNonPositiveBalance()

	/**
	 * A negative initial balance is refused too.
	 *
	 * @return void
	 */
	public function testIssueRefusesNegativeBalance(): void {
		$this->objects->expects($this->never())->method('saveObject');
		$this->expectExceptionMessage(message: 'Initial balance must be positive.');
		$this->service()->issueGiftCard(programmeId: null, initialBalance: -5.0);
	}//end testIssueRefusesNegativeBalance()

	/**
	 * Issuance saves an issued card with a hashed PIN and logs an issue transaction.
	 *
	 * @return void
	 */
	public function testIssueSavesCardAndLogsIssueTransaction(): void {
		$this->objects->expects($this->once())->method('findAll')->with(
			$this->callback(
				callback: static fn (array $config): bool => $config['limit'] === 1
					&& $config['filters']['register'] === 'reg'
					&& $config['filters']['schema'] === 'card'
					&& preg_match('/^GC-\d{8}$/', $config['filters']['serial']) === 1
			)
		)->willReturn([]);
		$this->recordSaves(assignedId: 'card-9');

		$before = $this->stamp(modifier: 'now');
		$result = $this->service()->issueGiftCard(
			programmeId: 'prog-1',
			initialBalance: 25.5,
			expiryDays: 30,
			channel: 'gifted',
			issuedIn: 'Anna'
		);

		$this->assertCount(expectedCount: 2, haystack: $this->saved);
		[$card, $register, $schema, $uuid] = $this->saved[0];
		$this->assertSame(expected: ['reg', 'card', null], actual: [$register, $schema, $uuid]);
		$this->assertSame(expected: 'prog-1', actual: $card['programmeId']);
		$this->assertMatchesRegularExpression(pattern: '/^GC-\d{8}$/', string: $card['serial']);
		$this->assertSame(expected: 25.5, actual: $card['initialBalance']);
		$this->assertSame(expected: 25.5, actual: $card['currentBalance']);
		$this->assertSame(expected: 'EUR', actual: $card['currency']);
		$this->assertSame(expected: 'issued', actual: $card['status']);
		$this->assertSame(expected: 'Anna', actual: $card['issuedIn']);
		$this->assertSame(expected: 'gifted', actual: $card['channel']);
		$this->assertGreaterThanOrEqual(expected: $before, actual: $card['issuedOn']);
		$this->assertGreaterThan(expected: $this->stamp(modifier: '+29 days'), actual: $card['expiresOn']);
		$this->assertLessThanOrEqual(expected: $this->stamp(modifier: '+31 days'), actual: $card['expiresOn']);

		$this->assertMatchesRegularExpression(pattern: '/^\d{6}$/', string: $result['pin']);
		$this->assertTrue(condition: password_verify($result['pin'], $card['pin']));
		$this->assertNotSame(expected: $result['pin'], actual: $card['pin']);
		$this->assertSame(expected: 'card-9', actual: $result['card']['@self']['id']);

		[$tx, $txRegister, $txSchema, $txUuid] = $this->saved[1];
		$this->assertSame(expected: ['reg', 'tx', null], actual: [$txRegister, $txSchema, $txUuid]);
		$this->assertSame(expected: 'card-9', actual: $tx['giftCardId']);
		$this->assertSame(expected: 'issue', actual: $tx['type']);
		$this->assertSame(expected: 25.5, actual: $tx['amount']);
		$this->assertSame(expected: 25.5, actual: $tx['balanceAfter']);
		$this->assertNull(actual: $tx['posTransactionId']);
		$this->assertSame(expected: 'gift-card-service', actual: $tx['processedBy']);
		$this->assertGreaterThanOrEqual(expected: $before, actual: $tx['timestamp']);
	}//end testIssueSavesCardAndLogsIssueTransaction()

	/**
	 * Issuance defaults: purchased channel, no recipient, roughly a year of validity.
	 *
	 * @return void
	 */
	public function testIssueAppliesDefaults(): void {
		$this->objects->method('findAll')->willReturn([]);
		$this->recordSaves(assignedId: 'card-2');

		$this->service()->issueGiftCard(programmeId: null, initialBalance: 10.0);

		$card = $this->saved[0][0];
		$this->assertNull(actual: $card['programmeId']);
		$this->assertNull(actual: $card['issuedIn']);
		$this->assertSame(expected: 'purchased', actual: $card['channel']);
		$this->assertGreaterThan(expected: $this->stamp(modifier: '+364 days'), actual: $card['expiresOn']);
		$this->assertLessThanOrEqual(expected: $this->stamp(modifier: '+366 days'), actual: $card['expiresOn']);
	}//end testIssueAppliesDefaults()

	/**
	 * A colliding serial is retried until a free one is found.
	 *
	 * @return void
	 */
	public function testIssueRetriesOnSerialCollision(): void {
		$serials = [];
		$this->objects->expects($this->exactly(count: 3))->method('findAll')->willReturnCallback(
			function (array $config) use (&$serials): array {
				$serials[] = $config['filters']['serial'];
				if (count($serials) < 3) {
					return [$this->card()];
				}

				return [];
			}
		);
		$this->recordSaves(assignedId: 'card-3');

		$this->service()->issueGiftCard(programmeId: null, initialBalance: 5.0);

		$this->assertSame(expected: $serials[2], actual: $this->saved[0][0]['serial']);
	}//end testIssueRetriesOnSerialCollision()

	/**
	 * Ten collisions in a row give up.
	 *
	 * @return void
	 */
	public function testIssueGivesUpAfterTenCollisions(): void {
		$this->objects->expects($this->exactly(count: 10))->method('findAll')->willReturn([$this->card()]);
		$this->objects->expects($this->never())->method('saveObject');
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Failed to generate unique gift card serial after 10 retries.');
		$this->service()->issueGiftCard(programmeId: null, initialBalance: 5.0);
	}//end testIssueGivesUpAfterTenCollisions()

	/**
	 * An unconfigured card schema refuses the save.
	 *
	 * @return void
	 */
	public function testIssueRefusesWhenCardSchemaUnconfigured(): void {
		$this->objects->expects($this->never())->method('findAll');
		$this->objects->expects($this->never())->method('saveObject');
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'GiftCard schema is not configured.');
		$this->service(config: ['giftCard_schema' => ''])->issueGiftCard(programmeId: null, initialBalance: 5.0);
	}//end testIssueRefusesWhenCardSchemaUnconfigured()

	/**
	 * An unconfigured transaction schema skips the log but still issues the card.
	 *
	 * @return void
	 */
	public function testIssueWithoutTransactionSchemaSkipsLog(): void {
		$this->objects->method('findAll')->willReturn([]);
		$this->recordSaves(assignedId: 'card-4');

		$result = $this->service(config: ['giftCardTransaction_schema' => ''])->issueGiftCard(programmeId: null, initialBalance: 5.0);

		$this->assertCount(expectedCount: 1, haystack: $this->saved);
		$this->assertSame(expected: 'card', actual: $this->saved[0][2]);
		$this->assertSame(expected: 'card-4', actual: $result['card']['@self']['id']);
	}//end testIssueWithoutTransactionSchemaSkipsLog()

	/**
	 * A failing transaction log is logged as a warning and does not fail issuance.
	 *
	 * @return void
	 */
	public function testIssueSurvivesTransactionLogFailure(): void {
		$this->objects->method('findAll')->willReturn([]);
		$this->objects->expects($this->exactly(count: 2))->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, $register, $schema): ObjectEntityInterface {
				if ($schema === 'tx') {
					throw new \Exception('tx store down');
				}

				return $this->entity(payload: array_merge($object, ['giftCardId' => 'card-5']));
			}
		);
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: gift card transaction log failed',
			['giftCardId' => 'card-5', 'type' => 'issue', 'exception' => 'tx store down']
		);

		$result = $this->service()->issueGiftCard(programmeId: null, initialBalance: 5.0);
		$this->assertSame(expected: 'card-5', actual: $result['card']['giftCardId']);
	}//end testIssueSurvivesTransactionLogFailure()

	/**
	 * A non-positive refund is refused before the card is read.
	 *
	 * @return void
	 */
	public function testRefundRefusesNonPositiveAmount(): void {
		$this->objects->expects($this->never())->method('find');
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Refund amount must be positive.');
		$this->service()->refundGiftCard(giftCardId: 'card-1', amount: 0.0);
	}//end testRefundRefusesNonPositiveAmount()

	/**
	 * Refunding a missing card is refused.
	 *
	 * @return void
	 */
	public function testRefundRefusesMissingCard(): void {
		$this->objects->expects($this->once())->method('find')->with('card-1', [], false, 'reg', 'card')->willReturn(null);
		$this->objects->expects($this->never())->method('saveObject');
		$this->expectExceptionMessage(message: 'Gift card not found.');
		$this->service()->refundGiftCard(giftCardId: 'card-1', amount: 5.0);
	}//end testRefundRefusesMissingCard()

	/**
	 * A refund credits the balance (rounded) and logs a refund transaction.
	 *
	 * @return void
	 */
	public function testRefundCreditsBalanceAndLogs(): void {
		$this->objects->method('find')->willReturn($this->entity(payload: $this->card(overrides: ['currentBalance' => 10.105])));
		$this->recordSaves();

		$result = $this->service()->refundGiftCard(giftCardId: 'card-1', amount: 2.2, posTransactionId: 'pos-3');

		$this->assertSame(expected: 12.31, actual: $result['currentBalance']);
		$this->assertSame(expected: 'active', actual: $result['status']);
		$this->assertSame(expected: 'card-1', actual: $this->saved[0][3]);
		$tx = $this->saved[1][0];
		$this->assertSame(expected: 'refund', actual: $tx['type']);
		$this->assertSame(expected: 2.2, actual: $tx['amount']);
		$this->assertSame(expected: 12.31, actual: $tx['balanceAfter']);
		$this->assertSame(expected: 'pos-3', actual: $tx['posTransactionId']);
		$this->assertSame(expected: 'card-1', actual: $tx['giftCardId']);
	}//end testRefundCreditsBalanceAndLogs()

	/**
	 * A refund onto a depleted card reactivates it.
	 *
	 * @return void
	 */
	public function testRefundReactivatesDepletedCard(): void {
		$this->objects->method('find')->willReturn(
			$this->entity(payload: $this->card(overrides: ['status' => 'depleted', 'currentBalance' => 0.0]))
		);
		$this->recordSaves();

		$result = $this->service()->refundGiftCard(giftCardId: 'card-1', amount: 3.0);

		$this->assertSame(expected: 'active', actual: $result['status']);
		$this->assertSame(expected: 3.0, actual: $result['currentBalance']);
	}//end testRefundReactivatesDepletedCard()

	/**
	 * A refund onto a blocked card keeps it blocked.
	 *
	 * @return void
	 */
	public function testRefundKeepsBlockedCardBlocked(): void {
		$this->objects->method('find')->willReturn($this->entity(payload: $this->card(overrides: ['status' => 'blocked'])));
		$this->recordSaves();

		$result = $this->service()->refundGiftCard(giftCardId: 'card-1', amount: 1.0);

		$this->assertSame(expected: 'blocked', actual: $result['status']);
		$this->assertSame(expected: 21.0, actual: $result['currentBalance']);
	}//end testRefundKeepsBlockedCardBlocked()

	/**
	 * Refunding without a transaction schema still persists the card.
	 *
	 * @return void
	 */
	public function testRefundWithoutTransactionSchemaOnlySavesCard(): void {
		$this->objects->method('find')->willReturn($this->entity(payload: $this->card()));
		$this->recordSaves();

		$this->service(config: ['giftCardTransaction_schema' => ''])->refundGiftCard(giftCardId: 'card-1', amount: 1.0);

		$this->assertCount(expectedCount: 1, haystack: $this->saved);
	}//end testRefundWithoutTransactionSchemaOnlySavesCard()

	/**
	 * Blocking a missing card answers null and saves nothing.
	 *
	 * @return void
	 */
	public function testBlockMissingCardReturnsNull(): void {
		$this->objects->method('find')->willReturn(null);
		$this->objects->expects($this->never())->method('saveObject');
		$this->logger->expects($this->never())->method('info');
		$this->assertNull(actual: $this->service()->blockGiftCard(giftCardId: 'card-1', reason: 'fraud'));
	}//end testBlockMissingCardReturnsNull()

	/**
	 * Blocking a card persists the blocked status, logs a block transaction and an info line.
	 *
	 * @return void
	 */
	public function testBlockPersistsLogsAndInforms(): void {
		$this->objects->method('find')->willReturn($this->entity(payload: $this->card(overrides: ['currentBalance' => 7.5])));
		$this->recordSaves();
		$this->logger->expects($this->once())->method('info')->with(
			'Pipelinq: gift card blocked',
			['giftCardId' => 'card-1', 'reason' => 'fraud']
		);

		$result = $this->service()->blockGiftCard(giftCardId: 'card-1', reason: 'fraud');

		$this->assertSame(expected: 'blocked', actual: $result['status']);
		$this->assertSame(expected: ['reg', 'card', 'card-1'], actual: array_slice($this->saved[0], 1));
		$tx = $this->saved[1][0];
		$this->assertSame(expected: 'block', actual: $tx['type']);
		$this->assertSame(expected: 0.0, actual: $tx['amount']);
		$this->assertSame(expected: 7.5, actual: $tx['balanceAfter']);
		$this->assertNull(actual: $tx['posTransactionId']);
	}//end testBlockPersistsLogsAndInforms()

	/**
	 * Card details for a missing card are refused.
	 *
	 * @return void
	 */
	public function testDetailsRefuseMissingCard(): void {
		$this->objects->method('find')->willReturn(null);
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Gift card not found.');
		$this->service()->getCardDetails(giftCardId: 'card-1', pin: '1234');
	}//end testDetailsRefuseMissingCard()

	/**
	 * A wrong PIN is refused.
	 *
	 * @return void
	 */
	public function testDetailsRefuseWrongPin(): void {
		$this->objects->method('find')->willReturn($this->entity(payload: $this->card()));
		$this->expectExceptionMessage(message: 'Invalid PIN.');
		$this->service()->getCardDetails(giftCardId: 'card-1', pin: '9999');
	}//end testDetailsRefuseWrongPin()

	/**
	 * A card without a stored PIN hash refuses every PIN.
	 *
	 * @return void
	 */
	public function testDetailsRefuseCardWithoutPinHash(): void {
		$this->objects->method('find')->willReturn($this->entity(payload: $this->card(overrides: ['pin' => ''])));
		$this->expectExceptionMessage(message: 'Invalid PIN.');
		$this->service()->getCardDetails(giftCardId: 'card-1', pin: '');
	}//end testDetailsRefuseCardWithoutPinHash()

	/**
	 * The right PIN answers balance, status and expiry, never the hash.
	 *
	 * @return void
	 */
	public function testDetailsAnswerWithoutPin(): void {
		$this->objects->method('find')->willReturn($this->entity(payload: $this->card(overrides: ['currentBalance' => '12.5'])));
		$this->assertSame(
			expected: ['balance' => 12.5, 'status' => 'active', 'expiryDate' => '2999-01-01T00:00:00+00:00'],
			actual: $this->service()->getCardDetails(giftCardId: 'card-1', pin: '1234')
		);
	}//end testDetailsAnswerWithoutPin()

	/**
	 * Missing fields default to zero balance, issued status and no expiry.
	 *
	 * @return void
	 */
	public function testDetailsDefaults(): void {
		$hash = password_hash('0000', PASSWORD_BCRYPT, ['cost' => 4]);
		$this->objects->method('find')->willReturn($this->entity(payload: ['pin' => $hash]));
		$this->assertSame(
			expected: ['balance' => 0.0, 'status' => 'issued', 'expiryDate' => null],
			actual: $this->service()->getCardDetails(giftCardId: 'card-1', pin: '0000')
		);
	}//end testDetailsDefaults()

	/**
	 * A card read that throws answers null.
	 *
	 * @return void
	 */
	public function testGetCardThrowingReadReturnsNull(): void {
		$this->objects->expects($this->once())->method('find')->willThrowException(new \Exception('db'));
		$this->assertNull(actual: $this->service()->getCard(giftCardId: 'card-1'));
	}//end testGetCardThrowingReadReturnsNull()

	/**
	 * An empty card id is never looked up.
	 *
	 * @return void
	 */
	public function testGetCardEmptyIdReturnsNull(): void {
		$this->objects->expects($this->never())->method('find');
		$this->assertNull(actual: $this->service()->getCard(giftCardId: ''));
	}//end testGetCardEmptyIdReturnsNull()

	/**
	 * Listing for a customer queries scoped by customer and maps every row.
	 *
	 * @return void
	 */
	public function testListForKlantMapsRows(): void {
		$this->objects->expects($this->once())->method('findAll')->with(
			['filters' => ['customerId' => 'klant-1', 'register' => 'reg', 'schema' => 'card'], 'limit' => 1000]
		)->willReturn(['a' => $this->entity(payload: ['serial' => 'GC-1']), 'b' => ['serial' => 'GC-2']]);

		$this->assertSame(
			expected: [['serial' => 'GC-1'], ['serial' => 'GC-2']],
			actual: $this->service()->listForKlant(customerId: 'klant-1')
		);
	}//end testListForKlantMapsRows()

	/**
	 * An unconfigured card schema lists nothing, logs, and never queries.
	 *
	 * @return void
	 */
	public function testListForKlantUnconfiguredReturnsEmpty(): void {
		$this->objects->expects($this->never())->method('findAll');
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains(string: 'register/schema not configured'),
			['schemaKey' => 'giftCard_schema']
		);
		$this->assertSame(expected: [], actual: $this->service(config: ['register' => ''])->listForKlant(customerId: 'klant-1'));
	}//end testListForKlantUnconfiguredReturnsEmpty()

	/**
	 * A throwing listing query lists nothing.
	 *
	 * @return void
	 */
	public function testListForKlantThrowingQueryReturnsEmpty(): void {
		$this->objects->method('findAll')->willThrowException(new \Exception('x'));
		$this->assertSame(expected: [], actual: $this->service()->listForKlant(customerId: 'klant-1'));
	}//end testListForKlantThrowingQueryReturnsEmpty()

	/**
	 * Validation answers the card id from a plain id field when @self is absent.
	 *
	 * @return void
	 */
	public function testValidateBySerialFallsBackToIdField(): void {
		$card = $this->card();
		unset($card['@self']);
		$card['id'] = 'card-77';
		$this->objects->method('findAll')->willReturn([$card]);

		$result = $this->service()->validateBySerial(serial: 'GC-00000001', pin: '1234');

		$this->assertTrue(condition: $result['valid']);
		$this->assertSame(expected: 'card-77', actual: $result['giftCardId']);
	}//end testValidateBySerialFallsBackToIdField()

	/**
	 * Without any id field the validation answers a null card id.
	 *
	 * @return void
	 */
	public function testValidateBySerialWithoutIdAnswersNull(): void {
		$card = $this->card();
		unset($card['@self']);
		$this->objects->method('findAll')->willReturn([$card]);

		$this->assertNull(actual: $this->service()->validateBySerial(serial: 'GC-00000001', pin: '1234')['giftCardId']);
	}//end testValidateBySerialWithoutIdAnswersNull()
}//end class
