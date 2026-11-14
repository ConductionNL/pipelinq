<?php

/**
 * Unit tests for RedemptionService.
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
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCA\Pipelinq\Service\RedemptionService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * RedemptionService reserves a reward against the points ledger and validates its code.
 */
class RedemptionServiceTest extends TestCase {

	/**
	 * Redemptions by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $redemptions = [];

	/**
	 * Redemption options by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $options = [];

	/**
	 * The loyalty account, or null for none.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $account = ['status' => 'active', 'currentBalance' => 500, 'customerId' => 'klant-1'];

	/**
	 * Debits made, as [operation, amount, redemptionId].
	 *
	 * @var list<array{0: string, 1: int, 2: string}>
	 */
	private array $debits = [];

	/**
	 * Uuids deleted.
	 *
	 * @var list<string>
	 */
	private array $deleted = [];

	/**
	 * Whether the debit fails.
	 *
	 * @var bool
	 */
	private bool $debitFails = false;

	/**
	 * Today in UTC.
	 *
	 * @return string
	 */
	private static function today(): string {
		return gmdate('Y-m-d');
	}//end today()

	/**
	 * The service over in-memory doubles.
	 *
	 * @return RedemptionService
	 */
	private function service(): RedemptionService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key): string => $key . '-id');

		$accounts = $this->createMock(LoyaltyAccountService::class);
		$accounts->method('getAccount')->willReturnCallback(fn (): ?array => $this->account);

		$ledger = $this->createMock(PointsLedgerService::class);
		$operation = '';
		$ledger->method('forOperation')->willReturnCallback(function (string $operationId) use ($ledger, &$operation): PointsLedgerService {
			$operation = $operationId;
			return $ledger;
		});
		$ledger->method('debitPoints')->willReturnCallback(
			function (string $accountId, int $amount, string $redemptionId, array $sourceDocument, string $processedBy) use (&$operation): array {
				if ($this->debitFails === true) {
					throw new RuntimeException('Insufficient balance.');
				}

				$this->debits[] = [$operation, $amount, $redemptionId];
				return [];
			}
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null): ?ObjectEntity {
			$store = ($schema === 'redemptionOption_schema-id' ? $this->options : $this->redemptions);
			return isset($store[$id]) === true ? self::entity(uuid: (string)$id, payload: $store[$id]) : null;
		});
		$objects->method('findAll')->willReturnCallback(function (array $config=[]): array {
			$filters = $config['filters'];
			$found = [];
			foreach ($this->redemptions as $uuid => $row) {
				$matches = true;
				foreach (['rewardCode', 'accountId', 'optionId', 'status'] as $key) {
					if (isset($filters[$key]) === true && ($row[$key] ?? null) !== $filters[$key]) {
						$matches = false;
					}
				}

				if ($matches === true) {
					$found[] = self::entity(uuid: $uuid, payload: $row);
				}
			}

			return $found;
		});
		$objects->method('saveObject')->willReturnCallback(function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): ObjectEntity {
			$uuid = ($uuid ?? 'red-' . (count($this->redemptions) + 1));
			unset($object['@self'], $object['id']);
			$this->redemptions[$uuid] = $object;
			return self::entity(uuid: $uuid, payload: $object);
		});
		$objects->method('deleteObject')->willReturnCallback(function (string $uuid): bool {
			$this->deleted[] = $uuid;
			unset($this->redemptions[$uuid]);
			return true;
		});

		return new RedemptionService(appConfig: $config, accountService: $accounts, ledgerService: $ledger, logger: $this->createMock(LoggerInterface::class), objectService: $objects);
	}//end service()

	/**
	 * An entity holding a payload.
	 *
	 * @param string               $uuid    The uuid.
	 * @param array<string, mixed> $payload The data.
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
	 * Initiating reserves a coded redemption and debits its cost once for that redemption.
	 *
	 * @return void
	 */
	public function testInitiatingReservesAndDebits(): void {
		$service = $this->service();
		$this->options['opt-1'] = ['programmeId' => 'prog-1', 'costInPoints' => 200];

		$saved = $service->initiateRedemption(accountId: 'acc-1', optionId: 'opt-1');

		self::assertSame('reserved', $saved['status']);
		self::assertSame(200, $saved['costInPoints']);
		self::assertSame('klant-1', $saved['customerId']);
		self::assertSame('prog-1', $saved['programmeId']);
		self::assertMatchesRegularExpression('/^RDM-[0-9A-F]{8}$/', $saved['rewardCode']);
		self::assertGreaterThan(gmdate('c'), $saved['validTo']);
		self::assertSame([['redemption:red-1', 200, 'red-1']], $this->debits);
	}//end testInitiatingReservesAndDebits()

	/**
	 * A failed debit rolls the reservation back.
	 *
	 * @return void
	 */
	public function testAFailedDebitRollsBack(): void {
		$service = $this->service();
		$this->options['opt-1'] = ['costInPoints' => 10];
		$this->debitFails = true;

		try {
			$service->initiateRedemption(accountId: 'acc-1', optionId: 'opt-1');
			self::fail('the redemption stood without a debit');
		} catch (RuntimeException $e) {
			self::assertSame('Debit failed: Insufficient balance.', $e->getMessage());
		}

		self::assertSame(['red-1'], $this->deleted);
		self::assertSame([], $this->redemptions);
	}//end testAFailedDebitRollsBack()

	/**
	 * Each eligibility refusal writes nothing.
	 *
	 * @return void
	 */
	public function testEligibilityRefusals(): void {
		$service = $this->service();
		$this->options['cheap'] = ['costInPoints' => 10];
		$this->options['dear'] = ['costInPoints' => 501];
		$this->options['future'] = ['costInPoints' => 10, 'validFrom' => '2999-01-01'];
		$this->options['past'] = ['costInPoints' => 10, 'validTo' => '2001-01-01'];
		$this->options['limited'] = ['costInPoints' => 10, 'perCustomerLimit' => 1];
		$this->redemptions['old'] = ['accountId' => 'acc-1', 'optionId' => 'limited', 'status' => 'used'];

		$cases = [
			['missing', 'Redemption option not found.'],
			['dear', 'Insufficient balance.'],
			['future', 'Redemption option is not currently valid.'],
			['past', 'Redemption option is not currently valid.'],
			['limited', 'Redemption limit reached for this option.'],
		];
		foreach ($cases as [$optionId, $message]) {
			try {
				$service->initiateRedemption(accountId: 'acc-1', optionId: $optionId);
				self::fail($optionId . ' was redeemed');
			} catch (RuntimeException $e) {
				self::assertSame($message, $e->getMessage(), $optionId);
			}
		}

		$this->account['status'] = 'frozen';
		try {
			$service->initiateRedemption(accountId: 'acc-1', optionId: 'cheap');
			self::fail('a frozen account redeemed');
		} catch (RuntimeException $e) {
			self::assertSame('Account is not active.', $e->getMessage());
		}

		$this->account = null;
		try {
			$service->initiateRedemption(accountId: 'acc-1', optionId: 'cheap');
			self::fail('a missing account redeemed');
		} catch (RuntimeException $e) {
			self::assertSame('Account not found.', $e->getMessage());
		}

		self::assertSame([], $this->debits);
		self::assertSame(['old'], array_keys($this->redemptions));
	}//end testEligibilityRefusals()

	/**
	 * A code is valid while reserved and unexpired; an expired one lapses.
	 *
	 * @return void
	 */
	public function testValidatingACode(): void {
		$service = $this->service();
		$this->redemptions['r-ok'] = ['rewardCode' => 'RDM-OK', 'status' => 'reserved', 'validTo' => '2999-01-01T00:00:00+00:00'];
		$this->redemptions['r-used'] = ['rewardCode' => 'RDM-USED', 'status' => 'used'];
		$this->redemptions['r-old'] = ['rewardCode' => 'RDM-OLD', 'status' => 'reserved', 'validTo' => '2001-01-01T00:00:00+00:00'];

		$ok = $service->validateCode(code: 'RDM-OK');
		self::assertTrue($ok['valid']);
		self::assertNull($ok['reason']);

		self::assertSame(['valid' => false, 'redemption' => null, 'reason' => 'Code not found'], $service->validateCode(code: 'RDM-NONE'));
		self::assertSame('Status is used', $service->validateCode(code: 'RDM-USED')['reason']);

		$old = $service->validateCode(code: 'RDM-OLD');
		self::assertFalse($old['valid']);
		self::assertSame('Redemption code expired', $old['reason']);
		self::assertSame('lapsed', $this->redemptions['r-old']['status']);
	}//end testValidatingACode()

	/**
	 * Marking used records the POS transaction once; a used redemption is returned as it is.
	 *
	 * @return void
	 */
	public function testMarkingUsed(): void {
		$service = $this->service();
		$this->redemptions['r-1'] = ['status' => 'reserved'];

		$used = $service->markRedemptionUsed(redemptionId: 'r-1', posTransactionId: 'pos-1');
		self::assertSame('used', $used['status']);
		self::assertSame('pos-1', $used['posTransactionId']);
		self::assertArrayHasKey('usedOn', $used);

		$again = $service->markRedemptionUsed(redemptionId: 'r-1', posTransactionId: 'pos-2');
		self::assertSame('pos-1', $again['posTransactionId']);

		$this->expectExceptionMessage('Redemption not found.');
		$service->markRedemptionUsed(redemptionId: 'missing');
	}//end testMarkingUsed()

	/**
	 * Expiring lapses a reserved redemption and leaves a lapsed one alone.
	 *
	 * @return void
	 */
	public function testExpiring(): void {
		$service = $this->service();
		$this->redemptions['r-1'] = ['status' => 'reserved'];

		self::assertSame('lapsed', $service->expireRedemption(redemptionId: 'r-1')['status']);
		self::assertSame('lapsed', $service->expireRedemption(redemptionId: 'r-1')['status']);
		self::assertNull($service->expireRedemption(redemptionId: 'missing'));
	}//end testExpiring()

	/**
	 * Valid options are those currently valid and affordable for the account.
	 *
	 * @return void
	 */
	public function testValidOptionsAreAffordableAndCurrent(): void {
		$rows = [
			self::entity(uuid: 'a', payload: ['name' => 'Koffie', 'costInPoints' => 50]),
			self::entity(uuid: 'b', payload: ['name' => 'Lunch', 'costInPoints' => 150]),
			self::entity(uuid: 'c', payload: ['name' => 'Oud', 'costInPoints' => 10, 'validTo' => '2001-01-01']),
			self::entity(uuid: 'd', payload: ['name' => 'Nu', 'costInPoints' => 100, 'validFrom' => self::today(), 'validTo' => self::today()]),
		];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key): string => $key . '-id');
		$accounts = $this->createMock(LoyaltyAccountService::class);
		$accounts->method('getAccount')->willReturn(['currentBalance' => 100]);
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('findAll')->with(['filters' => ['programmeId' => 'prog-1', 'register' => 'register-id', 'schema' => 'redemptionOption_schema-id'], 'limit' => 200])->willReturn($rows);
		$options = new RedemptionService(appConfig: $config, accountService: $accounts, ledgerService: $this->createMock(PointsLedgerService::class), logger: $this->createMock(LoggerInterface::class), objectService: $store);

		self::assertSame(['Koffie', 'Nu'], array_column($options->getValidRedemptionOptions(accountId: 'acc-1', programmeId: 'prog-1'), 'name'));
	}//end testValidOptionsAreAffordableAndCurrent()
}//end class
