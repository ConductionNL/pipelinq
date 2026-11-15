<?php

/**
 * Unit tests for LoyaltyAccountService.
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
 * @spec openspec/specs/loyalty-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Loyalty accounts: creation behind mandatory opt-in, lookup, get-or-create,
 * blocking, balance and tier updates, listing, and the refusal to run
 * unscoped when the schema is not configured.
 *
 * @covers \OCA\Pipelinq\Service\LoyaltyAccountService
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 *
 * @spec openspec/specs/loyalty-engine/spec.md
 */
class LoyaltyAccountServiceTest extends TestCase {
	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var ObjectServiceInterface&MockObject */
	private ObjectServiceInterface $objectService;

	/** @var array<string,string> */
	private array $config = ['register' => 'pipelinq', 'klantLoyaltyAccount_schema' => 'loyaltyAccount'];

	/** @var array<int,array<string,mixed>> */
	private array $saves = [];

	/**
	 * Wire the mocks; app config answers from $this->config.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return LoyaltyAccountService
	 */
	private function service(): LoyaltyAccountService {
		return new LoyaltyAccountService($this->appConfig, $this->logger, $this->objectService);
	}//end service()

	/**
	 * An entity serialising to $data.
	 *
	 * @param array<string,mixed> $data The payload.
	 *
	 * @return ObjectEntityInterface&MockObject
	 */
	private function entity(array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Record saveObject calls; each save answers its own payload plus an id.
	 *
	 * @return void
	 */
	private function recordSaves(): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $this->entity(['id' => $uuid ?? 'new-id'] + $object);
			}
		);
	}//end recordSaves()

	/**
	 * A new account starts empty and active, with the opt-in recorded.
	 *
	 * @return void
	 */
	public function testCreateAccountPersistsAnActiveOptedInAccount(): void {
		$this->recordSaves();

		$account = $this->service()->createAccount('klant-1', 'prog-1', true, '2.1');

		self::assertCount(1, $this->saves);
		$saved = $this->saves[0];
		self::assertNull($saved['uuid']);
		self::assertSame('pipelinq', $saved['register']);
		self::assertSame('loyaltyAccount', $saved['schema']);
		$object = $saved['object'];
		self::assertSame('klant-1', $object['customerId']);
		self::assertSame('prog-1', $object['programmeId']);
		self::assertSame(0, $object['currentBalance']);
		self::assertSame(0, $object['lifetimePoints']);
		self::assertSame('active', $object['status']);
		self::assertTrue($object['optInAccepted']);
		self::assertSame('2.1', $object['optInTermsVersion']);
		self::assertFalse($object['anonymized']);
		self::assertSame($object['createdOn'], $object['optInTimestamp']);
		self::assertSame($object['createdOn'], $object['lastActivityDate']);
		self::assertNotFalse(strtotime($object['createdOn']));
		self::assertSame('new-id', $account['id']);
	}//end testCreateAccountPersistsAnActiveOptedInAccount()

	/**
	 * Without opt-in, or without customer or programme, nothing is written.
	 *
	 * @return void
	 */
	public function testCreateAccountRefusesWithoutOptInOrIds(): void {
		$this->objectService->expects(self::never())->method('saveObject');
		$service = $this->service();

		foreach ([['klant-1', 'prog-1', false, 'Opt-in is mandatory'], ['', 'prog-1', true, 'klantId and programmeId are required.'], ['klant-1', '', true, 'klantId and programmeId are required.']] as [$customer, $programme, $optIn, $message]) {
			try {
				$service->createAccount($customer, $programme, $optIn);
				self::fail('expected a refusal');
			} catch (RuntimeException $e) {
				self::assertStringContainsString($message, $e->getMessage());
			}
		}
	}//end testCreateAccountRefusesWithoutOptInOrIds()

	/**
	 * Without the schema configured the write is refused, with a warning.
	 *
	 * @return void
	 */
	public function testCreateAccountRefusesWithoutConfiguredSchema(): void {
		$this->config['klantLoyaltyAccount_schema'] = '';
		$this->objectService->expects(self::never())->method('saveObject');
		$this->logger->expects(self::atLeastOnce())->method('warning');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('CustomerLoyaltyAccount schema is not configured.');
		$this->service()->createAccount('klant-1', 'prog-1', true);
	}//end testCreateAccountRefusesWithoutConfiguredSchema()

	/**
	 * getAccount reads one object in the configured register and schema.
	 *
	 * @return void
	 */
	public function testGetAccountFindsByIdInTheSchema(): void {
		$this->objectService->expects(self::once())->method('find')
			->with('acc-1', self::anything(), self::anything(), 'pipelinq', 'loyaltyAccount')
			->willReturn($this->entity(['id' => 'acc-1', 'currentBalance' => 40]));

		self::assertSame(['id' => 'acc-1', 'currentBalance' => 40], $this->service()->getAccount('acc-1'));
	}//end testGetAccountFindsByIdInTheSchema()

	/**
	 * A missing id, a missing object or a failed lookup reads as no account;
	 * an unconfigured schema never queries.
	 *
	 * @return void
	 */
	public function testGetAccountMisses(): void {
		self::assertNull($this->service()->getAccount(''));

		$this->objectService->method('find')->willReturnOnConsecutiveCalls(
			null,
			self::throwException(new RuntimeException('gone'))
		);
		$this->logger->expects(self::once())->method('debug')->with('Pipelinq: account lookup failed', ['exception' => 'gone']);
		self::assertNull($this->service()->getAccount('acc-1'));
		self::assertNull($this->service()->getAccount('acc-2'));

		$this->config['register'] = '';
		self::assertNull($this->service()->getAccount('acc-3'));
	}//end testGetAccountMisses()

	/**
	 * The lookup by customer and programme asks for one row and returns it.
	 *
	 * @return void
	 */
	public function testFindAccountByKlantAndProgramme(): void {
		$this->objectService->expects(self::once())->method('findAll')->with(
			[
				'filters' => [
					'customerId' => 'klant-1',
					'programmeId' => 'prog-1',
					'register' => 'pipelinq',
					'schema' => 'loyaltyAccount',
				],
				'limit' => 1,
			]
		)->willReturn([$this->entity(['id' => 'acc-1'])]);

		self::assertSame(['id' => 'acc-1'], $this->service()->findAccountByKlantAndProgramme('klant-1', 'prog-1'));
	}//end testFindAccountByKlantAndProgramme()

	/**
	 * No row, a failed query or no configuration is no account.
	 *
	 * @return void
	 */
	public function testFindAccountByKlantAndProgrammeMisses(): void {
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls(
			[],
			self::throwException(new RuntimeException('down'))
		);
		$service = $this->service();
		self::assertNull($service->findAccountByKlantAndProgramme('k', 'p'));
		self::assertNull($service->findAccountByKlantAndProgramme('k', 'p'));

		$this->config['klantLoyaltyAccount_schema'] = '';
		self::assertNull($service->findAccountByKlantAndProgramme('k', 'p'));
	}//end testFindAccountByKlantAndProgrammeMisses()

	/**
	 * get-or-create returns an existing account without writing, and creates one
	 * otherwise.
	 *
	 * @return void
	 */
	public function testGetOrCreateAccount(): void {
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls([['id' => 'existing']], []);
		$this->recordSaves();
		$service = $this->service();

		self::assertSame(['id' => 'existing'], $service->getOrCreateAccount('klant-1', 'prog-1'));
		self::assertSame([], $this->saves);

		$created = $service->getOrCreateAccount('klant-2', 'prog-1', true, '3.0');
		self::assertCount(1, $this->saves);
		self::assertSame('klant-2', $created['customerId']);
		self::assertSame('3.0', $created['optInTermsVersion']);
	}//end testGetOrCreateAccount()

	/**
	 * Blocking sets the status, logs the reason and saves under the account id.
	 *
	 * @return void
	 */
	public function testDisableAccountBlocksAndLogs(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'acc-1', 'status' => 'active', 'currentBalance' => 7]));
		$this->recordSaves();
		$this->logger->expects(self::once())->method('info')->with(
			'Pipelinq: loyalty account disabled',
			['accountId' => 'acc-1', 'reason' => 'fraud']
		);

		$result = $this->service()->disableAccount('acc-1', 'fraud');

		self::assertSame('acc-1', $this->saves[0]['uuid']);
		self::assertSame('blocked', $this->saves[0]['object']['status']);
		self::assertSame(7, $this->saves[0]['object']['currentBalance']);
		self::assertSame('blocked', $result['status']);
	}//end testDisableAccountBlocksAndLogs()

	/**
	 * Balance updates set the balance, add only positive lifetime deltas and
	 * stamp the activity date.
	 *
	 * @return void
	 */
	public function testUpdateBalances(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'acc-1', 'currentBalance' => 10, 'lifetimePoints' => 100]));
		$this->recordSaves();
		$service = $this->service();

		$service->updateBalances('acc-1', 25, 15, '2026-11-01T10:00:00+00:00');
		$service->updateBalances('acc-1', 5, -20, '2026-11-02T10:00:00+00:00');

		self::assertSame(25, $this->saves[0]['object']['currentBalance']);
		self::assertSame(115, $this->saves[0]['object']['lifetimePoints']);
		self::assertSame('2026-11-01T10:00:00+00:00', $this->saves[0]['object']['lastActivityDate']);
		self::assertSame(5, $this->saves[1]['object']['currentBalance']);
		self::assertSame(100, $this->saves[1]['object']['lifetimePoints']);
		self::assertSame('acc-1', $this->saves[1]['uuid']);
	}//end testUpdateBalances()

	/**
	 * A tier change sets the tier, and the dates only when given.
	 *
	 * @return void
	 */
	public function testSetTier(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'acc-1', 'tierAchievedOn' => '2025-01-01', 'tierValidTo' => '2025-12-31']));
		$this->recordSaves();
		$service = $this->service();

		$service->setTier('acc-1', 'gold', '2026-11-01', '2027-10-31');
		$service->setTier('acc-1', null);

		self::assertSame('gold', $this->saves[0]['object']['currentTierId']);
		self::assertSame('2026-11-01', $this->saves[0]['object']['tierAchievedOn']);
		self::assertSame('2027-10-31', $this->saves[0]['object']['tierValidTo']);
		self::assertNull($this->saves[1]['object']['currentTierId']);
		self::assertSame('2025-01-01', $this->saves[1]['object']['tierAchievedOn']);
		self::assertSame('2025-12-31', $this->saves[1]['object']['tierValidTo']);
	}//end testSetTier()

	/**
	 * Updates on an unknown account write nothing and answer null.
	 *
	 * @return void
	 */
	public function testUpdatesOnAnUnknownAccountAnswerNull(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects(self::never())->method('saveObject');
		$service = $this->service();

		self::assertNull($service->disableAccount('nope', 'x'));
		self::assertNull($service->updateBalances('nope', 1, 1, 'now'));
		self::assertNull($service->setTier('nope', 'gold'));
	}//end testUpdatesOnAnUnknownAccountAnswerNull()

	/**
	 * Listing pages through the programme's accounts as plain arrays.
	 *
	 * @return void
	 */
	public function testListAccountsForProgramme(): void {
		$this->objectService->expects(self::once())->method('findAll')->with(
			[
				'filters' => ['programmeId' => 'prog-1', 'register' => 'pipelinq', 'schema' => 'loyaltyAccount'],
				'limit' => 50,
				'offset' => 100,
			]
		)->willReturn(['a' => ['id' => 'acc-1'], 'b' => $this->entity(['id' => 'acc-2'])]);

		self::assertSame([['id' => 'acc-1'], ['id' => 'acc-2']], $this->service()->listAccountsForProgramme('prog-1', 50, 100));
	}//end testListAccountsForProgramme()

	/**
	 * A failed or unconfigured listing is empty.
	 *
	 * @return void
	 */
	public function testListAccountsForProgrammeEmptyOnFailure(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('down'));
		self::assertSame([], $this->service()->listAccountsForProgramme('prog-1'));

		$this->config['register'] = '';
		self::assertSame([], $this->service()->listAccountsForProgramme('prog-1'));
	}//end testListAccountsForProgrammeEmptyOnFailure()
}//end class
