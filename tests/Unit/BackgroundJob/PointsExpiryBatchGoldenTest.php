<?php

/**
 * Pins what the points expiry batch does for each programme and account shape.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\BackgroundJob\PointsExpiryBatchJob;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Every call the batch makes, in order, for a mixed set of programmes and accounts.
 */
class PointsExpiryBatchGoldenTest extends TestCase {

	/**
	 * Calls seen, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * An entity as OpenRegister returns it.
	 *
	 * @param string               $uuid   The uuid ('' for none).
	 * @param array<string, mixed> $object The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $object): ObjectEntity {
		$entity = new ObjectEntity();
		if ($uuid !== '') {
			$entity->setUuid($uuid);
		}

		$entity->setObject($object);
		return $entity;
	}//end entity()

	/**
	 * A moment relative to now, as the account stores it.
	 *
	 * @param string $shift The shift, e.g. '-13 months'.
	 *
	 * @return string
	 */
	private function ago(string $shift): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($shift)->format('c');
	}//end ago()

	/**
	 * Runs the batch over the fixture and returns the calls.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function runBatch(): array {
		$policy  = static fn (int $months, ?int $notice = null): array => array_filter(
			['type' => 'inactivityMonths', 'value' => $months, 'advanceNoticeDays' => $notice],
			static fn ($v): bool => $v !== null
		);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn(
			[
				$this->entity(uuid: '', object: ['expiryPolicy' => $policy(12)]),
				$this->entity(uuid: 'p-flat', object: ['expiryPolicy' => 'twelve']),
				$this->entity(uuid: 'p-none', object: ['expiryPolicy' => ['type' => 'none', 'value' => 12]]),
				$this->entity(uuid: 'p-zero', object: ['expiryPolicy' => $policy(0)]),
				$this->entity(uuid: 'p-unset', object: []),
				$this->entity(uuid: 'p-year', object: ['expiryPolicy' => $policy(12, 60)]),
				$this->entity(uuid: 'p-half', object: ['expiryPolicy' => $policy(6)]),
			]
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => 'v-'.$key
		);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $default
		);

		$accounts = [
			'p-year' => [
				['currentBalance' => 50, 'lastActivityDate' => $this->ago('-20 months')],
				['uuid' => 'a-empty', 'currentBalance' => 0, 'lastActivityDate' => $this->ago('-20 months')],
				['uuid' => 'a-never', 'currentBalance' => 40],
				['uuid' => 'a-old', 'currentBalance' => 80, 'lastActivityDate' => $this->ago('-13 months'), 'customerId' => 'c-old'],
				['uuid' => 'a-anon', 'currentBalance' => 30, 'lastActivityDate' => $this->ago('-11 months')],
				['uuid' => 'a-warn', 'currentBalance' => 5, 'lastActivityDate' => $this->ago('-11 months'), 'customerId' => 'c-warn'],
				['uuid' => 'a-mute', 'currentBalance' => 7, 'lastActivityDate' => $this->ago('-11 months'), 'customerId' => 'c-mute'],
				['uuid' => 'a-recent', 'currentBalance' => 9, 'lastActivityDate' => $this->ago('-1 months'), 'customerId' => 'c-recent'],
				['@self' => ['id' => 'a-broken'], 'currentBalance' => 11, 'lastActivityDate' => $this->ago('-14 months')],
			],
			'p-half' => [
				['uuid' => 'b-warn', 'currentBalance' => 3, 'lastActivityDate' => $this->ago('-5 months -10 days'), 'customerId' => 'c-b'],
				['uuid' => 'b-old', 'currentBalance' => 4, 'lastActivityDate' => $this->ago('-7 months')],
			],
		];
		$loyalty = $this->createMock(LoyaltyAccountService::class);
		$loyalty->method('listAccountsForProgramme')->willReturnCallback(
			function (string $programmeId, int $limit = 1000) use ($accounts): array {
				$this->calls[] = ['list', $programmeId, $limit];
				return $accounts[$programmeId] ?? [];
			}
		);
		$ledger = $this->createMock(PointsLedgerService::class);
		$ledger->method('forOperation')->willReturnCallback(
			function (string $operationId) use ($ledger) {
				$this->calls[] = ['forOperation', $operationId];
				return $ledger;
			}
		);
		$ledger->method('expirePoints')->willReturnCallback(
			function (string $accountId, int $amount, string $reason): array {
				$this->calls[] = ['expire', $accountId, $amount, $reason];
				if ($accountId === 'a-broken') {
					throw new RuntimeException('ledger down');
				}

				return [];
			}
		);
		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('sendNotification')->willReturnCallback(
			function (string $userId, string $subject, array $parameters, string $objectType = '', string $objectId = ''): void {
				$this->calls[] = ['notify', $userId, $subject, $parameters, $objectType, $objectId];
				if ($userId === 'c-mute') {
					throw new RuntimeException('mail down');
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'debug', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		$job = new PointsExpiryBatchJob(
			time: $this->createMock(ITimeFactory::class),
			appConfig: $config,
			container: $container,
			loyaltyService: $loyalty,
			ledgerService: $ledger,
			notificationService: $notifications,
			logger: $logger,
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
		return $this->calls;
	}//end runBatch()

	/**
	 * The batch makes exactly these calls, in this order.
	 *
	 * @return void
	 */
	public function testEveryProgrammeAndAccountShape(): void {
		$now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$year = 'expiry:'.$now->modify('-12 months')->format('Y-m-d');
		$half = 'expiry:'.$now->modify('-6 months')->format('Y-m-d');
		$warn = static fn (string $id, int $points, int $days): array => [
			'notify',
			'c-'.$id,
			'loyalty_points_expiring',
			['points' => $points, 'noticeDays' => $days, 'accountId' => 'a-'.$id],
			'customerLoyaltyAccount',
			'a-'.$id,
		];

		$expected = [
			['list', 'p-year', 10000],
			['forOperation', $year],
			['expire', 'a-old', 80, 'inactivity-expiry'],
			['info', 'Pipelinq: expired points for inactive account', ['accountId' => 'a-old', 'points' => 80]],
			$warn('warn', 5, 60),
			$warn('mute', 7, 60),
			['debug', 'Pipelinq: advance-expiry notification failed', ['accountId' => 'a-mute', 'exception' => 'mail down']],
			['forOperation', $year],
			['expire', 'a-broken', 11, 'inactivity-expiry'],
			['warning', 'Pipelinq: expiry batch failed for one account; continuing', ['accountId' => 'a-broken', 'exception' => 'ledger down']],
			['list', 'p-half', 10000],
			['notify', 'c-b', 'loyalty_points_expiring', ['points' => 3, 'noticeDays' => 30, 'accountId' => 'b-warn'], 'customerLoyaltyAccount', 'b-warn'],
			['forOperation', $half],
			['expire', 'b-old', 4, 'inactivity-expiry'],
			['info', 'Pipelinq: expired points for inactive account', ['accountId' => 'b-old', 'points' => 4]],
		];
		$this->assertSame($expected, $this->runBatch());
	}//end testEveryProgrammeAndAccountShape()

	/**
	 * Without a register the batch reads no accounts at all.
	 *
	 * @return void
	 */
	public function testNoProgrammesWithoutARegister(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $default
		);
		$loyalty = $this->createMock(LoyaltyAccountService::class);
		$loyalty->expects($this->never())->method('listAccountsForProgramme');
		$job = new PointsExpiryBatchJob(
			time: $this->createMock(ITimeFactory::class),
			appConfig: $config,
			container: $this->createMock(ContainerInterface::class),
			loyaltyService: $loyalty,
			ledgerService: $this->createMock(PointsLedgerService::class),
			notificationService: $this->createMock(NotificationService::class),
			logger: $this->createMock(LoggerInterface::class),
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testNoProgrammesWithoutARegister()
}//end class
