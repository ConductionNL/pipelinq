<?php

/**
 * Coverage tests for RedemptionService: initiation refusals, the debit
 * rollback, code validation, use/expiry transitions and option listing.
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
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCA\Pipelinq\Service\RedemptionService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Exercises the RedemptionService lifecycle against mocked boundaries.
 *
 * @covers \OCA\Pipelinq\Service\RedemptionService
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class RedemptionServiceCoverageTest extends TestCase {

	/**
	 * The OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The account service mock.
	 *
	 * @var LoyaltyAccountService&MockObject
	 */
	private LoyaltyAccountService&MockObject $accounts;

	/**
	 * The ledger service mock.
	 *
	 * @var PointsLedgerService&MockObject
	 */
	private PointsLedgerService&MockObject $ledger;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->accounts = $this->createMock(originalClassName: LoyaltyAccountService::class);
		$this->ledger = $this->createMock(originalClassName: PointsLedgerService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the service over a config map.
	 *
	 * @param array<string, string> $config The app-config overrides.
	 *
	 * @return RedemptionService
	 */
	private function service(array $config = []): RedemptionService {
		$config = array_merge(
			['register' => 'reg', 'redemption_schema' => 'red', 'redemptionOption_schema' => 'opt'],
			$config
		);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);

		return new RedemptionService(
			appConfig: $appConfig,
			accountService: $this->accounts,
			ledgerService: $this->ledger,
			logger: $this->logger,
			objectService: $this->objects
		);
	}//end service()

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
	 * A date offset from today, as Y-m-d (UTC).
	 *
	 * @param string $modifier The relative modifier.
	 *
	 * @return string
	 */
	private function day(string $modifier): string {
		return (new DateTimeImmutable($modifier, new DateTimeZone('UTC')))->format('Y-m-d');
	}//end day()

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
	 * An active account with a balance.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function account(array $overrides = []): array {
		return array_merge(['customerId' => 'klant-1', 'status' => 'active', 'currentBalance' => 500], $overrides);
	}//end account()

	/**
	 * Wire find() to answer the option for the option schema.
	 *
	 * @param array<string, mixed>|null $option The option payload, or null.
	 *
	 * @return void
	 */
	private function stubOption(?array $option): void {
		$this->objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($option) {
				if ($schema === 'opt' && $option !== null) {
					return $this->entity(payload: $option);
				}

				return null;
			}
		);
	}//end stubOption()

	/**
	 * Assert initiateRedemption refuses with the message and saves nothing.
	 *
	 * @param string $message The expected message.
	 *
	 * @return void
	 */
	private function assertInitiateRefused(string $message): void {
		$this->objects->expects($this->never())->method('saveObject');
		$this->ledger->expects($this->never())->method('forOperation');
		try {
			$this->service()->initiateRedemption(accountId: 'acc-1', optionId: 'opt-1');
			$this->fail(message: 'Expected refusal: ' . $message);
		} catch (RuntimeException $e) {
			$this->assertSame(expected: $message, actual: $e->getMessage());
		}
	}//end assertInitiateRefused()

	/**
	 * A missing account is refused before the option is read.
	 *
	 * @return void
	 */
	public function testInitiateRefusesMissingAccount(): void {
		$this->accounts->expects($this->once())->method('getAccount')->with('acc-1')->willReturn(null);
		$this->objects->expects($this->never())->method('find');
		$this->assertInitiateRefused(message: 'Account not found.');
	}//end testInitiateRefusesMissingAccount()

	/**
	 * A missing option is refused.
	 *
	 * @return void
	 */
	public function testInitiateRefusesMissingOption(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->stubOption(option: null);
		$this->assertInitiateRefused(message: 'Redemption option not found.');
	}//end testInitiateRefusesMissingOption()

	/**
	 * An unconfigured option schema means the option is never found.
	 *
	 * @return void
	 */
	public function testInitiateWithUnconfiguredOptionSchemaFindsNoOption(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->objects->expects($this->never())->method('find');
		$this->objects->expects($this->never())->method('saveObject');
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains(string: 'register/schema not configured'),
			['schemaKey' => 'redemptionOption_schema']
		);
		$this->expectExceptionMessage(message: 'Redemption option not found.');
		$this->service(config: ['redemptionOption_schema' => ''])->initiateRedemption(accountId: 'acc-1', optionId: 'opt-1');
	}//end testInitiateWithUnconfiguredOptionSchemaFindsNoOption()

	/**
	 * An option read that throws is treated as not found.
	 *
	 * @return void
	 */
	public function testInitiateTreatsThrowingOptionReadAsMissing(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->objects->method('find')->willThrowException(new \Exception('db down'));
		$this->assertInitiateRefused(message: 'Redemption option not found.');
	}//end testInitiateTreatsThrowingOptionReadAsMissing()

	/**
	 * An inactive account is refused.
	 *
	 * @return void
	 */
	public function testInitiateRefusesInactiveAccount(): void {
		$this->accounts->method('getAccount')->willReturn($this->account(overrides: ['status' => 'blocked']));
		$this->stubOption(option: ['costInPoints' => 10]);
		$this->assertInitiateRefused(message: 'Account is not active.');
	}//end testInitiateRefusesInactiveAccount()

	/**
	 * An option whose validity starts in the future is refused.
	 *
	 * @return void
	 */
	public function testInitiateRefusesOptionNotYetValid(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->stubOption(option: ['costInPoints' => 10, 'validFrom' => $this->day(modifier: '+5 days')]);
		$this->assertInitiateRefused(message: 'Redemption option is not currently valid.');
	}//end testInitiateRefusesOptionNotYetValid()

	/**
	 * An option whose validity has ended is refused.
	 *
	 * @return void
	 */
	public function testInitiateRefusesOptionNoLongerValid(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->stubOption(option: ['costInPoints' => 10, 'validTo' => $this->day(modifier: '-5 days')]);
		$this->assertInitiateRefused(message: 'Redemption option is not currently valid.');
	}//end testInitiateRefusesOptionNoLongerValid()

	/**
	 * A balance below the cost is refused.
	 *
	 * @return void
	 */
	public function testInitiateRefusesInsufficientBalance(): void {
		$this->accounts->method('getAccount')->willReturn($this->account(overrides: ['currentBalance' => 99]));
		$this->stubOption(option: ['costInPoints' => 100]);
		$this->assertInitiateRefused(message: 'Insufficient balance.');
	}//end testInitiateRefusesInsufficientBalance()

	/**
	 * Reaching the per-customer limit is refused, and the count query is scoped.
	 *
	 * @return void
	 */
	public function testInitiateRefusesWhenPerCustomerLimitReached(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->stubOption(option: ['costInPoints' => 10, 'perCustomerLimit' => 2]);
		$this->objects->expects($this->once())->method('findAll')->with(
			[
				'filters' => [
					'accountId' => 'acc-1',
					'optionId' => 'opt-1',
					'status' => 'used',
					'register' => 'reg',
					'schema' => 'red',
				],
				'limit' => 1000,
			]
		)->willReturn([['id' => 'a'], ['id' => 'b']]);
		$this->assertInitiateRefused(message: 'Redemption limit reached for this option.');
	}//end testInitiateRefusesWhenPerCustomerLimitReached()

	/**
	 * Below the per-customer limit the redemption goes ahead.
	 *
	 * @return void
	 */
	public function testInitiateProceedsBelowPerCustomerLimit(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->stubOption(option: ['costInPoints' => 10, 'perCustomerLimit' => 2, 'programmeId' => 'prog-1']);
		$this->objects->method('findAll')->willReturn([['id' => 'a']]);
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(
			fn (array $object): ObjectEntityInterface => $this->entity(payload: array_merge($object, ['@self' => ['id' => 'R-1']]))
		);
		$this->ledger->method('forOperation')->willReturnSelf();
		$this->ledger->expects($this->once())->method('debitPoints')->willReturn([]);

		$result = $this->service()->initiateRedemption(accountId: 'acc-1', optionId: 'opt-1');
		$this->assertSame(expected: 'reserved', actual: $result['status']);
	}//end testInitiateProceedsBelowPerCustomerLimit()

	/**
	 * A count query that throws counts as zero, so the redemption goes ahead.
	 *
	 * @return void
	 */
	public function testInitiateCountsZeroWhenCountQueryThrows(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->stubOption(option: ['costInPoints' => 10, 'perCustomerLimit' => 1]);
		$this->objects->method('findAll')->willThrowException(new \Exception('boom'));
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(
			fn (array $object): ObjectEntityInterface => $this->entity(payload: array_merge($object, ['uuid' => 'R-2']))
		);
		$this->ledger->expects($this->once())->method('forOperation')->with('redemption:R-2')->willReturnSelf();
		$this->ledger->method('debitPoints')->willReturn([]);

		$result = $this->service()->initiateRedemption(accountId: 'acc-1', optionId: 'opt-1');
		$this->assertSame(expected: 'R-2', actual: $result['uuid']);
	}//end testInitiateCountsZeroWhenCountQueryThrows()

	/**
	 * A zero per-customer limit means unlimited: no count query.
	 *
	 * @return void
	 */
	public function testZeroPerCustomerLimitSkipsCount(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->stubOption(option: ['costInPoints' => 10, 'perCustomerLimit' => 0]);
		$this->objects->expects($this->never())->method('findAll');
		$this->objects->method('saveObject')->willReturnCallback(
			fn (array $object): ObjectEntityInterface => $this->entity(payload: array_merge($object, ['id' => 'R-3']))
		);
		$this->ledger->method('forOperation')->willReturnSelf();
		$this->ledger->method('debitPoints')->willReturn([]);

		$this->assertSame(expected: 'R-3', actual: $this->service()->initiateRedemption(accountId: 'acc-1', optionId: 'opt-1')['id']);
	}//end testZeroPerCustomerLimitSkipsCount()

	/**
	 * A successful initiation saves the full reserved payload and debits the cost.
	 *
	 * @return void
	 */
	public function testInitiateSavesReservedRedemptionAndDebits(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->stubOption(option: ['costInPoints' => 150, 'programmeId' => 'prog-9']);
		$captured = null;
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, $register, $schema, ?string $uuid) use (&$captured): ObjectEntityInterface {
				$captured = [$object, $extend, $register, $schema, $uuid];
				return $this->entity(payload: array_merge($object, ['@self' => ['id' => 'R-10']]));
			}
		);
		$this->ledger->expects($this->once())->method('forOperation')->with('redemption:R-10')->willReturnSelf();
		$this->ledger->expects($this->once())->method('debitPoints')->with(
			'acc-1',
			150,
			'R-10',
			$this->callback(
				callback: static fn (array $doc): bool => $doc['optionId'] === 'opt-1'
					&& preg_match('/^RDM-[0-9A-F]{8}$/', $doc['rewardCode']) === 1
			),
			'redemption-service'
		)->willReturn([]);

		$before = $this->stamp(modifier: 'now');
		$result = $this->service()->initiateRedemption(accountId: 'acc-1', optionId: 'opt-1');

		[$payload, $extend, $register, $schema, $uuid] = $captured;
		$this->assertSame(expected: [], actual: $extend);
		$this->assertSame(expected: 'reg', actual: $register);
		$this->assertSame(expected: 'red', actual: $schema);
		$this->assertNull(actual: $uuid);
		$this->assertSame(expected: 'acc-1', actual: $payload['accountId']);
		$this->assertSame(expected: 'klant-1', actual: $payload['customerId']);
		$this->assertSame(expected: 'opt-1', actual: $payload['optionId']);
		$this->assertSame(expected: 'prog-9', actual: $payload['programmeId']);
		$this->assertSame(expected: 150, actual: $payload['costInPoints']);
		$this->assertSame(expected: 'reserved', actual: $payload['status']);
		$this->assertMatchesRegularExpression(pattern: '/^RDM-[0-9A-F]{8}$/', string: $payload['rewardCode']);
		$this->assertGreaterThanOrEqual(expected: $before, actual: $payload['initiatedOn']);
		$this->assertGreaterThan(expected: $this->stamp(modifier: '+23 hours'), actual: $payload['validTo']);
		$this->assertLessThanOrEqual(expected: $this->stamp(modifier: '+25 hours'), actual: $payload['validTo']);
		$this->assertSame(expected: 'R-10', actual: $result['@self']['id']);
	}//end testInitiateSavesReservedRedemptionAndDebits()

	/**
	 * A debit failure rolls back the redemption record and rethrows.
	 *
	 * @return void
	 */
	public function testDebitFailureRollsBackAndRethrows(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->stubOption(option: ['costInPoints' => 10]);
		$this->objects->method('saveObject')->willReturnCallback(
			fn (array $object): ObjectEntityInterface => $this->entity(payload: array_merge($object, ['@self' => ['id' => 'R-20']]))
		);
		$this->ledger->method('forOperation')->willReturnSelf();
		$this->ledger->method('debitPoints')->willThrowException(new RuntimeException('ledger locked'));
		$this->objects->expects($this->once())->method('deleteObject')->with('R-20', 'reg', 'red')->willReturn(true);
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: redemption debit failed; rolling back Redemption',
			['accountId' => 'acc-1', 'optionId' => 'opt-1', 'exception' => 'ledger locked']
		);

		try {
			$this->service()->initiateRedemption(accountId: 'acc-1', optionId: 'opt-1');
			$this->fail(message: 'Expected the debit failure to surface.');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'Debit failed: ledger locked', actual: $e->getMessage());
			$this->assertInstanceOf(expected: RuntimeException::class, actual: $e->getPrevious());
			$this->assertSame(expected: 'ledger locked', actual: $e->getPrevious()->getMessage());
		}
	}//end testDebitFailureRollsBackAndRethrows()

	/**
	 * A failing rollback delete is logged, and the debit error still surfaces.
	 *
	 * @return void
	 */
	public function testRollbackDeleteFailureIsLogged(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->stubOption(option: ['costInPoints' => 10]);
		$this->objects->method('saveObject')->willReturnCallback(
			fn (array $object): ObjectEntityInterface => $this->entity(payload: array_merge($object, ['redemptionId' => 'R-21']))
		);
		$this->ledger->method('forOperation')->willReturnSelf();
		$this->ledger->method('debitPoints')->willThrowException(new RuntimeException('nope'));
		$this->objects->expects($this->once())->method('deleteObject')->with('R-21', 'reg', 'red')
			->willThrowException(new \Exception('delete failed'));
		$messages = [];
		$this->logger->expects($this->exactly(count: 2))->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$messages): void {
				$messages[] = [$message, $context];
			}
		);

		try {
			$this->service()->initiateRedemption(accountId: 'acc-1', optionId: 'opt-1');
			$this->fail(message: 'Expected the debit failure to surface.');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'Debit failed: nope', actual: $e->getMessage());
		}

		$this->assertSame(
			expected: ['Pipelinq: redemption rollback delete failed', ['redemptionId' => 'R-21', 'exception' => 'delete failed']],
			actual: $messages[1]
		);
	}//end testRollbackDeleteFailureIsLogged()

	/**
	 * Without a uuid on the saved record there is nothing to roll back.
	 *
	 * @return void
	 */
	public function testDebitFailureWithoutUuidSkipsRollback(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->stubOption(option: ['costInPoints' => 10]);
		$this->objects->method('saveObject')->willReturnCallback(fn (array $object): ObjectEntityInterface => $this->entity(payload: $object));
		$this->ledger->expects($this->once())->method('forOperation')->with('redemption:')->willReturnSelf();
		$this->ledger->method('debitPoints')->willThrowException(new RuntimeException('x'));
		$this->objects->expects($this->never())->method('deleteObject');

		$this->expectExceptionMessage(message: 'Debit failed: x');
		$this->service()->initiateRedemption(accountId: 'acc-1', optionId: 'opt-1');
	}//end testDebitFailureWithoutUuidSkipsRollback()

	/**
	 * An unconfigured redemption schema refuses the save.
	 *
	 * @return void
	 */
	public function testInitiateRefusesWhenRedemptionSchemaUnconfigured(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->stubOption(option: ['costInPoints' => 10]);
		$this->objects->expects($this->never())->method('saveObject');
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Redemption schema is not configured.');
		$this->service(config: ['redemption_schema' => ''])->initiateRedemption(accountId: 'acc-1', optionId: 'opt-1');
	}//end testInitiateRefusesWhenRedemptionSchemaUnconfigured()

	/**
	 * An unknown code is invalid.
	 *
	 * @return void
	 */
	public function testValidateCodeNotFound(): void {
		$this->objects->expects($this->once())->method('findAll')->with(
			['filters' => ['rewardCode' => 'RDM-1', 'register' => 'reg', 'schema' => 'red'], 'limit' => 1]
		)->willReturn([]);
		$this->assertSame(
			expected: ['valid' => false, 'redemption' => null, 'reason' => 'Code not found'],
			actual: $this->service()->validateCode(code: 'RDM-1')
		);
	}//end testValidateCodeNotFound()

	/**
	 * A non-reserved code is invalid and names its status.
	 *
	 * @return void
	 */
	public function testValidateCodeWrongStatus(): void {
		$row = ['status' => 'used', 'rewardCode' => 'RDM-1'];
		$this->objects->method('findAll')->willReturn(['k' => $this->entity(payload: $row)]);
		$this->assertSame(
			expected: ['valid' => false, 'redemption' => $row, 'reason' => 'Status is used'],
			actual: $this->service()->validateCode(code: 'RDM-1')
		);
	}//end testValidateCodeWrongStatus()

	/**
	 * A reserved, unexpired code is valid.
	 *
	 * @return void
	 */
	public function testValidateCodeValid(): void {
		$row = ['status' => 'reserved', 'validTo' => $this->stamp(modifier: '+2 hours')];
		$this->objects->method('findAll')->willReturn([$row]);
		$this->objects->expects($this->never())->method('saveObject');
		$this->assertSame(
			expected: ['valid' => true, 'redemption' => $row, 'reason' => null],
			actual: $this->service()->validateCode(code: 'RDM-1')
		);
	}//end testValidateCodeValid()

	/**
	 * A reserved code without an expiry is valid.
	 *
	 * @return void
	 */
	public function testValidateCodeWithoutExpiryIsValid(): void {
		$row = ['status' => 'reserved'];
		$this->objects->method('findAll')->willReturn([$row]);
		$this->assertTrue(condition: $this->service()->validateCode(code: 'RDM-1')['valid']);
	}//end testValidateCodeWithoutExpiryIsValid()

	/**
	 * An expired reserved code is invalid and marked lapsed.
	 *
	 * @return void
	 */
	public function testValidateCodeExpiredMarksLapsed(): void {
		$row = ['@self' => ['id' => 'R-30'], 'status' => 'reserved', 'validTo' => $this->stamp(modifier: '-1 hour')];
		$this->objects->method('findAll')->willReturn([$row]);
		$this->objects->expects($this->once())->method('find')->with('R-30', [], false, 'reg', 'red')
			->willReturn($this->entity(payload: $row));
		$this->objects->expects($this->once())->method('saveObject')->with(
			array_merge($row, ['status' => 'lapsed']),
			[],
			'reg',
			'red',
			'R-30'
		)->willReturnCallback(fn (array $object): ObjectEntityInterface => $this->entity(payload: $object));

		$this->assertSame(
			expected: ['valid' => false, 'redemption' => $row, 'reason' => 'Redemption code expired'],
			actual: $this->service()->validateCode(code: 'RDM-1')
		);
	}//end testValidateCodeExpiredMarksLapsed()

	/**
	 * An empty code is never looked up.
	 *
	 * @return void
	 */
	public function testFindByCodeEmptyCodeReturnsNull(): void {
		$this->objects->expects($this->never())->method('findAll');
		$this->assertNull(actual: $this->service()->findByCode(code: ''));
	}//end testFindByCodeEmptyCodeReturnsNull()

	/**
	 * A lookup that throws answers null.
	 *
	 * @return void
	 */
	public function testFindByCodeThrowingLookupReturnsNull(): void {
		$this->objects->method('findAll')->willThrowException(new \Exception('x'));
		$this->assertNull(actual: $this->service()->findByCode(code: 'RDM-1'));
	}//end testFindByCodeThrowingLookupReturnsNull()

	/**
	 * Marking an unknown redemption used is refused.
	 *
	 * @return void
	 */
	public function testMarkUsedRefusesMissingRedemption(): void {
		$this->objects->method('find')->willReturn(null);
		$this->objects->expects($this->never())->method('saveObject');
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Redemption not found.');
		$this->service()->markRedemptionUsed(redemptionId: 'R-40');
	}//end testMarkUsedRefusesMissingRedemption()

	/**
	 * Marking an already-used redemption is idempotent.
	 *
	 * @return void
	 */
	public function testMarkUsedIsIdempotent(): void {
		$row = ['status' => 'used', 'usedOn' => '2026-01-01T00:00:00+00:00'];
		$this->objects->method('find')->willReturn($this->entity(payload: $row));
		$this->objects->expects($this->never())->method('saveObject');
		$this->assertSame(expected: $row, actual: $this->service()->markRedemptionUsed(redemptionId: 'R-40', posTransactionId: 'pos-1'));
	}//end testMarkUsedIsIdempotent()

	/**
	 * Marking a reserved redemption used stamps it and stores the POS id.
	 *
	 * @return void
	 */
	public function testMarkUsedPersistsUsedState(): void {
		$this->objects->method('find')->willReturn($this->entity(payload: ['status' => 'reserved', 'rewardCode' => 'RDM-1']));
		$captured = null;
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, $register, $schema, ?string $uuid) use (&$captured): ObjectEntityInterface {
				$captured = [$object, $uuid];
				return $this->entity(payload: $object);
			}
		);

		$before = $this->stamp(modifier: 'now');
		$result = $this->service()->markRedemptionUsed(redemptionId: 'R-41', posTransactionId: 'pos-7');

		$this->assertSame(expected: 'R-41', actual: $captured[1]);
		$this->assertSame(expected: 'used', actual: $result['status']);
		$this->assertSame(expected: 'pos-7', actual: $result['posTransactionId']);
		$this->assertSame(expected: 'RDM-1', actual: $result['rewardCode']);
		$this->assertGreaterThanOrEqual(expected: $before, actual: $result['usedOn']);
	}//end testMarkUsedPersistsUsedState()

	/**
	 * Expiring a missing redemption answers null.
	 *
	 * @return void
	 */
	public function testExpireMissingRedemptionReturnsNull(): void {
		$this->objects->method('find')->willReturn(null);
		$this->objects->expects($this->never())->method('saveObject');
		$this->assertNull(actual: $this->service()->expireRedemption(redemptionId: 'R-50'));
	}//end testExpireMissingRedemptionReturnsNull()

	/**
	 * Expiring an already-lapsed redemption does not save again.
	 *
	 * @return void
	 */
	public function testExpireAlreadyLapsedIsIdempotent(): void {
		$row = ['status' => 'lapsed'];
		$this->objects->method('find')->willReturn($this->entity(payload: $row));
		$this->objects->expects($this->never())->method('saveObject');
		$this->assertSame(expected: $row, actual: $this->service()->expireRedemption(redemptionId: 'R-50'));
	}//end testExpireAlreadyLapsedIsIdempotent()

	/**
	 * Expiring a reserved redemption persists it as lapsed.
	 *
	 * @return void
	 */
	public function testExpireReservedPersistsLapsed(): void {
		$this->objects->method('find')->willReturn($this->entity(payload: ['status' => 'reserved']));
		$this->objects->expects($this->once())->method('saveObject')
			->with(['status' => 'lapsed'], [], 'reg', 'red', 'R-51')
			->willReturnCallback(fn (array $object): ObjectEntityInterface => $this->entity(payload: $object));
		$this->assertSame(expected: ['status' => 'lapsed'], actual: $this->service()->expireRedemption(redemptionId: 'R-51'));
	}//end testExpireReservedPersistsLapsed()

	/**
	 * getRedemption refuses an empty id, an unconfigured schema and a throwing read.
	 *
	 * @return void
	 */
	public function testGetRedemptionGuards(): void {
		$this->objects->expects($this->once())->method('find')->willThrowException(new \Exception('x'));
		$this->assertNull(actual: $this->service()->getRedemption(redemptionId: ''));
		$this->assertNull(actual: $this->service(config: ['register' => ''])->getRedemption(redemptionId: 'R-1'));
		$this->assertNull(actual: $this->service()->getRedemption(redemptionId: 'R-1'));
	}//end testGetRedemptionGuards()

	/**
	 * Options are filtered to valid and affordable ones.
	 *
	 * @return void
	 */
	public function testValidOptionsFilterValidityAndBalance(): void {
		$this->accounts->expects($this->once())->method('getAccount')->with('acc-1')
			->willReturn($this->account(overrides: ['currentBalance' => 100]));
		$affordable = ['name' => 'cheap', 'costInPoints' => 100];
		$this->objects->expects($this->once())->method('findAll')->with(
			['filters' => ['programmeId' => 'prog-1', 'register' => 'reg', 'schema' => 'opt'], 'limit' => 200]
		)->willReturn(
			[
				'x' => $this->entity(payload: $affordable),
				'y' => $this->entity(payload: ['name' => 'dear', 'costInPoints' => 101]),
				'z' => $this->entity(payload: ['name' => 'future', 'costInPoints' => 1, 'validFrom' => $this->day(modifier: '+3 days')]),
			]
		);

		$this->assertSame(expected: [$affordable], actual: $this->service()->getValidRedemptionOptions(accountId: 'acc-1', programmeId: 'prog-1'));
	}//end testValidOptionsFilterValidityAndBalance()

	/**
	 * A missing account has zero balance, so only free options remain.
	 *
	 * @return void
	 */
	public function testValidOptionsForMissingAccountKeepOnlyFreeOnes(): void {
		$this->accounts->method('getAccount')->willReturn(null);
		$free = ['name' => 'free', 'costInPoints' => 0, 'validTo' => $this->day(modifier: '+1 day')];
		$this->objects->method('findAll')->willReturn([$free, ['name' => 'paid', 'costInPoints' => 1]]);

		$this->assertSame(expected: [$free], actual: $this->service()->getValidRedemptionOptions(accountId: 'acc-1', programmeId: 'prog-1'));
	}//end testValidOptionsForMissingAccountKeepOnlyFreeOnes()

	/**
	 * An unconfigured option schema lists nothing and never queries.
	 *
	 * @return void
	 */
	public function testValidOptionsUnconfiguredReturnsEmpty(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->objects->expects($this->never())->method('findAll');
		$service = $this->service(config: ['redemptionOption_schema' => '']);
		$this->assertSame(expected: [], actual: $service->getValidRedemptionOptions(accountId: 'acc-1', programmeId: 'p'));
	}//end testValidOptionsUnconfiguredReturnsEmpty()

	/**
	 * A throwing option query lists nothing.
	 *
	 * @return void
	 */
	public function testValidOptionsThrowingQueryReturnsEmpty(): void {
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->objects->method('findAll')->willThrowException(new \Exception('x'));
		$this->assertSame(expected: [], actual: $this->service()->getValidRedemptionOptions(accountId: 'acc-1', programmeId: 'p'));
	}//end testValidOptionsThrowingQueryReturnsEmpty()
}//end class
