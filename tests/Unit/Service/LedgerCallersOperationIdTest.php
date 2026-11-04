<?php

/**
 * Tests that the redemption and the expiry batch give their ledger
 * movement an operation id.
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
 * @spec openspec/changes/loyalty-ledger-operation-id/specs/loyalty-program/spec.md#requirement-a-retried-movement-is-written-once-req-loy-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\BackgroundJob\PointsExpiryBatchJob;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCA\Pipelinq\Service\RedemptionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The callers of the ledger pass an operation id.
 *
 * @spec openspec/changes/loyalty-ledger-operation-id/specs/loyalty-program/spec.md#requirement-a-retried-movement-is-written-once-req-loy-011
 */
class LedgerCallersOperationIdTest extends TestCase {

	/**
	 * An app config that answers every schema key.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'reg-1',
				default => 'schema-'.$key,
			}
		);
		$config->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);
		return $config;
	}//end appConfig()

	/**
	 * An entity as OpenRegister returns it.
	 *
	 * @param string               $uuid   The uuid.
	 * @param array<string, mixed> $object The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($object);
		return $entity;
	}//end entity()

	/**
	 * A redemption debits under the redemption's own id.
	 *
	 * @return void
	 */
	public function testARedemptionDebitsUnderItsOwnId(): void {
		$accounts = $this->createMock(originalClassName: LoyaltyAccountService::class);
		$accounts->method('getAccount')->willReturn(['uuid' => 'anna', 'status' => 'active', 'currentBalance' => 300]);
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturn($this->entity(uuid: 'opt-1', object: ['costInPoints' => 250]));
		$objects->method('saveObject')->willReturnCallback(
			fn (array $object): ObjectEntity => $this->entity(uuid: 'R-77', object: $object)
		);
		$ledger = $this->createMock(originalClassName: PointsLedgerService::class);
		$ledger->expects($this->once())->method('forOperation')->with('redemption:R-77')->willReturnSelf();
		$ledger->expects($this->once())->method('debitPoints')->with('anna', 250, 'R-77')->willReturn([]);

		$service = new RedemptionService(
			appConfig: $this->appConfig(),
			accountService: $accounts,
			ledgerService: $ledger,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			objectService: $objects
		);

		$service->initiateRedemption(accountId: 'anna', optionId: 'opt-1');
	}//end testARedemptionDebitsUnderItsOwnId()

	/**
	 * The expiry batch expires an account under the run's cutoff day.
	 *
	 * @return void
	 */
	public function testTheExpiryBatchExpiresUnderTheRunsCutoffDay(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn(
			[$this->entity(uuid: 'prog-1', object: ['status' => 'active', 'expiryPolicy' => ['type' => 'inactivityMonths', 'value' => 12]])]
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$accounts = $this->createMock(originalClassName: LoyaltyAccountService::class);
		$accounts->method('listAccountsForProgramme')->willReturn(
			[['uuid' => 'anna', 'currentBalance' => 80, 'lastActivityDate' => '2020-01-01T00:00:00+00:00']]
		);
		$cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-12 months')->format('Y-m-d');
		$ledger = $this->createMock(originalClassName: PointsLedgerService::class);
		$ledger->expects($this->once())->method('forOperation')->with('expiry:'.$cutoff)->willReturnSelf();
		$ledger->expects($this->once())->method('expirePoints')->with('anna', 80, 'inactivity-expiry')->willReturn([]);

		$job = new PointsExpiryBatchJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			appConfig: $this->appConfig(),
			container: $container,
			loyaltyService: $accounts,
			ledgerService: $ledger,
			notificationService: $this->createMock(originalClassName: NotificationService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testTheExpiryBatchExpiresUnderTheRunsCutoffDay()
}//end class
