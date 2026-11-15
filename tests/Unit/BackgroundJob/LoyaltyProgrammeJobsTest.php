<?php

/**
 * Characterisation tests for the two loyalty background jobs.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use ArrayObject;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\BackgroundJob\PointsExpiryBatchJob;
use OCA\Pipelinq\BackgroundJob\TierDowngradeJob;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCA\Pipelinq\Service\TierService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The programme lookup both jobs share, and every branch of the expiry batch.
 */
class LoyaltyProgrammeJobsTest extends TestCase {
	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var ArrayObject<int, array<int, mixed>>
	 */
	private ArrayObject $calls;

	/**
	 * Set up the call log.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls = new ArrayObject();
	}//end setUp()

	/**
	 * Without a register or programme schema nothing is looked up; a failed
	 * lookup and a non-list answer give no programmes.
	 *
	 * @return void
	 */
	public function testProgrammeLookupGuards(): void {
		foreach ([['register' => ''], ['loyaltyProgramme_schema' => '']] as $config) {
			$this->assertSame([], $this->programmes(job: PointsExpiryBatchJob::class, rows: [['id' => 'p']], config: $config));
			$this->assertSame([], $this->programmes(job: TierDowngradeJob::class, rows: [['id' => 'p']], config: $config));
		}

		$this->assertSame([], $this->calls->getArrayCopy());
		$this->assertSame([], $this->programmes(job: PointsExpiryBatchJob::class, rows: null, config: []));
		$this->assertSame([], $this->programmes(job: TierDowngradeJob::class, rows: 'flat', config: []));
		$this->assertSame(
			['findAll', ['filters' => ['status' => 'active', 'register' => 'reg', 'schema' => 'prog'], 'limit' => 500]],
			$this->calls[0]
		);
	}//end testProgrammeLookupGuards()

	/**
	 * Each job turns the rows into arrays its own way: the expiry batch keeps
	 * every row, the tier job drops what it cannot read.
	 *
	 * @return void
	 */
	public function testProgrammeRowsAreConverted(): void {
		$entity = new class {
			public function jsonSerialize(): array {
				return ['id' => 'from-entity'];
			}
		};
		$rows = [['id' => 'p-1'], $entity, 'junk'];

		$this->assertSame([['id' => 'p-1'], ['id' => 'from-entity']], $this->programmes(job: TierDowngradeJob::class, rows: $rows, config: []));
		$expiry = $this->programmes(job: PointsExpiryBatchJob::class, rows: [['id' => 'p-1'], $entity], config: []);
		$this->assertSame(['id' => 'p-1'], $expiry[0]);
		$this->assertCount(2, $expiry);
	}//end testProgrammeRowsAreConverted()

	/**
	 * The expiry batch: only programmes with an inactivity policy run; each
	 * account is expired, warned, skipped or logged as failed.
	 *
	 * @return void
	 */
	public function testExpiryBatchBranches(): void {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$ago = static fn (string $modify): string => $now->modify($modify)->format('c');
		$programmes = [
			['name' => 'no id'],
			['id' => 'p-flat', 'expiryPolicy' => 'x'],
			['id' => 'p-none', 'expiryPolicy' => ['type' => 'none', 'value' => 12]],
			['id' => 'p-zero', 'expiryPolicy' => ['type' => 'inactivityMonths', 'value' => 0]],
			['@self' => ['id' => 'p-1'], 'expiryPolicy' => ['type' => 'inactivityMonths', 'value' => '12', 'advanceNoticeDays' => '45']],
		];
		$accounts = [
			['currentBalance' => 10, 'lastActivityDate' => $ago('-2 years')],
			['accountId' => 'a-empty', 'currentBalance' => 0, 'lastActivityDate' => $ago('-2 years')],
			['accountId' => 'a-never', 'currentBalance' => 5, 'lastActivityDate' => ''],
			['accountId' => 'a-old', 'currentBalance' => 30, 'lastActivityDate' => $ago('-2 years')],
			['accountId' => 'a-warn', 'currentBalance' => 7, 'customerId' => 'cust-1', 'lastActivityDate' => $ago('-11 months -15 days')],
			['accountId' => 'a-anon', 'currentBalance' => 7, 'lastActivityDate' => $ago('-11 months -15 days')],
			['accountId' => 'a-quiet', 'currentBalance' => 7, 'customerId' => 'cust-mute', 'lastActivityDate' => $ago('-11 months -15 days')],
			['accountId' => 'a-recent', 'currentBalance' => 7, 'customerId' => 'cust-2', 'lastActivityDate' => $ago('-1 month')],
			['accountId' => 'a-broken', 'currentBalance' => 99, 'lastActivityDate' => $ago('-2 years')],
		];

		$job = $this->expiryJob(programmes: $programmes, accounts: $accounts);
		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);

		$cutoff = $now->modify('-12 months')->format('Y-m-d');
		$calls = array_map(
			static function (array $call): array {
				if ($call[0] === 'warning' || $call[0] === 'debug') {
					$call[2] = array_keys($call[2]) === ['accountId', 'exception'] ? [$call[2]['accountId'], $call[2]['exception']] : $call[2];
				}

				return $call;
			},
			array_slice($this->calls->getArrayCopy(), 1)
		);
		$this->assertSame(
			[
				['accounts', 'p-1', 10000],
				['expire', 'expiry:' . $cutoff, 'a-old', 30, 'inactivity-expiry'],
				['info', 'Pipelinq: expired points for inactive account', ['accountId' => 'a-old', 'points' => 30]],
				['notify', 'cust-1', 'loyalty_points_expiring', ['points' => 7, 'noticeDays' => 45, 'accountId' => 'a-warn'], 'customerLoyaltyAccount', 'a-warn'],
				['notify', 'cust-mute', 'loyalty_points_expiring', ['points' => 7, 'noticeDays' => 45, 'accountId' => 'a-quiet'], 'customerLoyaltyAccount', 'a-quiet'],
				['debug', 'Pipelinq: advance-expiry notification failed', ['a-quiet', 'muted']],
				['expire', 'expiry:' . $cutoff, 'a-broken', 99, 'inactivity-expiry'],
				['warning', 'Pipelinq: expiry batch failed for one account; continuing', ['a-broken', 'ledger down']],
			],
			$calls
		);
	}//end testExpiryBatchBranches()

	/**
	 * The programmes a job reads.
	 *
	 * @param class-string          $job    The job.
	 * @param mixed                 $rows   What findAll answers, null to throw.
	 * @param array<string, string> $config App-config overrides.
	 *
	 * @return array<int, mixed> The programmes.
	 */
	private function programmes(string $job, mixed $rows, array $config): array {
		$instance = $job === TierDowngradeJob::class
			? new TierDowngradeJob($this->createMock(ITimeFactory::class), $this->appConfig(config: $config), $this->container(rows: $rows), $this->createMock(LoyaltyAccountService::class), $this->createMock(TierService::class), $this->createMock(LoggerInterface::class))
			: $this->expiryJob(programmes: $rows, accounts: [], config: $config);
		$method = new ReflectionMethod($instance, 'getActiveProgrammes');
		$method->setAccessible(true);
		return $method->invoke($instance);
	}//end programmes()

	/**
	 * The expiry job over recording doubles.
	 *
	 * @param mixed                            $programmes What findAll answers, null to throw.
	 * @param array<int, array<string, mixed>> $accounts   The accounts of programme p-1.
	 * @param array<string, string>            $config     App-config overrides.
	 *
	 * @return PointsExpiryBatchJob
	 */
	private function expiryJob(mixed $programmes, array $accounts, array $config = []): PointsExpiryBatchJob {
		$calls = $this->calls;
		$loyalty = $this->createMock(LoyaltyAccountService::class);
		$loyalty->method('listAccountsForProgramme')->willReturnCallback(
			static function (string $programmeId, int $limit = 1000) use ($calls, $accounts): array {
				$calls[] = ['accounts', $programmeId, $limit];
				return $accounts;
			}
		);

		$ledger = $this->createMock(PointsLedgerService::class);
		$ledger->method('forOperation')->willReturnCallback(
			function (string $operationId) use ($calls): PointsLedgerService {
				$scoped = $this->createMock(PointsLedgerService::class);
				$scoped->method('expirePoints')->willReturnCallback(
					static function (string $accountId, int $amount, string $reason) use ($calls, $operationId): array {
						$calls[] = ['expire', $operationId, $accountId, $amount, $reason];
						if ($accountId === 'a-broken') {
							throw new RuntimeException('ledger down');
						}

						return [];
					}
				);
				return $scoped;
			}
		);

		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('sendNotification')->willReturnCallback(
			static function (string $userId, string $subject, array $parameters, string $objectType = '', string $objectId = '') use ($calls): void {
				$calls[] = ['notify', $userId, $subject, $parameters, $objectType, $objectId];
				if ($userId === 'cust-mute') {
					throw new RuntimeException('muted');
				}
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use ($calls, $level): void {
					$calls[] = [$level, $message, $context];
				}
			);
		}

		return new PointsExpiryBatchJob(
			$this->createMock(ITimeFactory::class),
			$this->appConfig(config: $config),
			$this->container(rows: $programmes),
			$loyalty,
			$ledger,
			$notifications,
			$logger,
		);
	}//end expiryJob()

	/**
	 * The app config.
	 *
	 * @param array<string, string> $config Overrides.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(array $config): IAppConfig {
		$values = $config + ['register' => 'reg', 'loyaltyProgramme_schema' => 'prog'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $default
		);
		return $appConfig;
	}//end appConfig()

	/**
	 * A container whose object service answers findAll with the rows.
	 *
	 * @param mixed $rows What findAll answers, null to throw.
	 *
	 * @return ContainerInterface
	 */
	private function container(mixed $rows): ContainerInterface {
		$calls = $this->calls;
		$objects = new class($calls, $rows) {
			public function __construct(
				private ArrayObject $calls,
				private mixed $rows,
			) {
			}

			public function findAll(array $config): mixed {
				$this->calls[] = ['findAll', $config];
				if ($this->rows === null) {
					throw new RuntimeException('down');
				}

				return $this->rows;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		return $container;
	}//end container()
}//end class
