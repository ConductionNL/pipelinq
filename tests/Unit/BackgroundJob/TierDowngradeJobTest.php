<?php

/**
 * Unit tests for the run() of TierDowngradeJob: which accounts of which
 * active programmes get their tier re-evaluated.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
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
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\TierDowngradeJob;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\TierService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Expired tiers are re-evaluated once per account; everything else is left alone.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\TierDowngradeJob
 * @uses   \OCA\Pipelinq\BackgroundJob\ActiveLoyaltyProgrammes
 */
class TierDowngradeJobTest extends TestCase {

	/**
	 * A tier end long past.
	 *
	 * @var string
	 */
	private const PAST = '2000-01-01T00:00:00+00:00';

	/**
	 * A tier end far ahead.
	 *
	 * @var string
	 */
	private const FUTURE = '2999-01-01T00:00:00+00:00';

	/**
	 * Programmes the account service was asked for, as [programmeId, limit].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $listed = [];

	/**
	 * Accounts whose tier was re-evaluated.
	 *
	 * @var array<int, string>
	 */
	private array $updated = [];

	/**
	 * Warnings logged, as [message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * Build the job and run it once.
	 *
	 * @param array<int, mixed>                               $programmes The active programme rows.
	 * @param array<string, array<int, array<string, mixed>>> $accounts   Accounts per programme id.
	 *
	 * @return void
	 */
	private function runJob(array $programmes, array $accounts): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = ['register' => 'reg', 'loyaltyProgramme_schema' => 'prog'];
				return ($values[$key] ?? $default);
			}
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default = 0): int {
				return $default;
			}
		);

		$objects = new class ($programmes) {
			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $rows The programme rows.
			 */
			public function __construct(private array $rows) {
			}//end __construct()

			/**
			 * The active programmes.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				return $this->rows;
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$loyalty = $this->createMock(LoyaltyAccountService::class);
		$loyalty->method('listAccountsForProgramme')->willReturnCallback(
			function (string $programmeId, int $limit = 1000, int $offset = 0) use ($accounts): array {
				$this->listed[] = [$programmeId, $limit];
				return ($accounts[$programmeId] ?? []);
			}
		);

		$tiers = $this->createMock(TierService::class);
		$tiers->method('updateTierIfNeeded')->willReturnCallback(
			function (string $accountId): array {
				$this->updated[] = $accountId;
				if ($accountId === 'a-broken') {
					throw new RuntimeException('tier table locked');
				}

				return [];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		$job = new TierDowngradeJob(
			$this->createMock(ITimeFactory::class),
			$appConfig,
			$container,
			$loyalty,
			$tiers,
			$logger
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * Only accounts whose tier has expired are re-evaluated; accounts without
	 * an id or an end date, or whose tier is still valid, are skipped.
	 *
	 * @return void
	 */
	public function testOnlyExpiredTiersAreReEvaluated(): void {
		$this->runJob(
			programmes: [['id' => 'p-1']],
			accounts: [
				'p-1' => [
					['id' => 'a-expired', 'tierValidTo' => self::PAST],
					['id' => 'a-valid', 'tierValidTo' => self::FUTURE],
					['id' => 'a-open'],
					['tierValidTo' => self::PAST],
					['@self' => ['id' => 'a-self'], 'accountId' => 'ignored', 'tierValidTo' => self::PAST],
					['accountId' => 'a-account', 'id' => 'ignored', 'tierValidTo' => self::PAST],
				],
			]
		);

		$this->assertSame([['p-1', 10000]], $this->listed);
		$this->assertSame(['a-expired', 'a-self', 'a-account'], $this->updated);
		$this->assertSame([], $this->warnings);
	}//end testOnlyExpiredTiersAreReEvaluated()

	/**
	 * One failing account is logged and the rest are still processed.
	 *
	 * @return void
	 */
	public function testAFailingAccountDoesNotStopTheBatch(): void {
		$this->runJob(
			programmes: [['id' => 'p-1']],
			accounts: ['p-1' => [['id' => 'a-broken', 'tierValidTo' => self::PAST], ['id' => 'a-next', 'tierValidTo' => self::PAST]]]
		);

		$this->assertSame(['a-broken', 'a-next'], $this->updated);
		$this->assertSame(
			[['Pipelinq: tier downgrade processing failed for account', ['accountId' => 'a-broken', 'exception' => 'tier table locked']]],
			$this->warnings
		);
	}//end testAFailingAccountDoesNotStopTheBatch()

	/**
	 * Every programme with an id is walked, identified by its `@self` id or
	 * its own field; a programme without any id is skipped.
	 *
	 * @return void
	 */
	public function testEveryIdentifiedProgrammeIsWalked(): void {
		$this->runJob(
			programmes: [
				['@self' => ['id' => 'p-self']],
				['programmeId' => 'p-own'],
				['name' => 'No id'],
				['uuid' => 'p-uuid'],
			],
			accounts: ['p-uuid' => [['id' => 'a-1', 'tierValidTo' => self::PAST]]]
		);

		$this->assertSame(['p-self', 'p-own', 'p-uuid'], array_column($this->listed, 0));
		$this->assertSame(['a-1'], $this->updated);
	}//end testEveryIdentifiedProgrammeIsWalked()

	/**
	 * Without active programmes no account is listed.
	 *
	 * @return void
	 */
	public function testNoProgrammesMeansNoWork(): void {
		$this->runJob(programmes: [], accounts: []);

		$this->assertSame([], $this->listed);
		$this->assertSame([], $this->updated);
	}//end testNoProgrammesMeansNoWork()
}//end class
