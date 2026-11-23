<?php

/**
 * Unit tests for the quota lookups of QuotaService.
 *
 * Covers reading a quota from OpenRegister (only when the register and
 * schema are configured), the team-against-reps hierarchy report, and the
 * configurable at-risk thresholds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\QuotaService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for QuotaService lookups and thresholds.
 */
class QuotaServiceLookupTest extends TestCase {

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Service under test.
	 *
	 * @var QuotaService
	 */
	private QuotaService $service;

	/**
	 * Wire the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->service = new QuotaService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->objectService
		);
	}//end setUp()

	/**
	 * Configure the register and quota schema slugs.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The salesQuota schema slug.
	 *
	 * @return void
	 */
	private function configure(string $register, string $schema): void {
		$this->appConfig->method('getValueString')->willReturnMap(
			[
				['pipelinq', 'register', '', $register],
				['pipelinq', 'salesQuota_schema', '', $schema],
			]
		);
	}//end configure()

	/**
	 * Answer quota lookups from a map of "owner/level" to amount.
	 *
	 * @param array<string, float|null> $amounts Amounts by "owner/level"; null means a quota without an amount.
	 *
	 * @return void
	 */
	private function quotas(array $amounts): void {
		$this->objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($amounts): array {
				$filters = $config['filters'];
				$key = $filters['owner_id'] . '/' . $filters['level'];
				if (array_key_exists($key, $amounts) === false) {
					return [];
				}

				if ($amounts[$key] === null) {
					return [['owner_id' => $filters['owner_id']]];
				}

				return [['owner_id' => $filters['owner_id'], 'quota_amount' => $amounts[$key]]];
			}
		);
	}//end quotas()

	/**
	 * A quota is read with the register, schema and the owner, period and level filters.
	 *
	 * @return void
	 */
	public function testReadsAQuotaWithItsFilters(): void {
		$this->configure(register: 'crm', schema: 'quota');
		$this->objectService->expects(self::once())->method('findAll')
			->with(
				[
					'filters' => [
						'register' => 'crm',
						'schema' => 'quota',
						'owner_id' => 'alice',
						'period_id' => '2026-Q4',
						'level' => 'rep',
					],
				]
			)
			->willReturn([['quota_amount' => '125000.50'], ['quota_amount' => 1]]);

		self::assertSame(
			expected: 125000.5,
			actual: $this->service->getQuotaAmount(ownerId: 'alice', periodId: '2026-Q4', level: 'rep')
		);
	}//end testReadsAQuotaWithItsFilters()

	/**
	 * Without a configured register or schema nothing is queried.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredAppReadsNoQuota(): void {
		$this->configure(register: 'crm', schema: '');
		$this->objectService->expects(self::never())->method('findAll');

		self::assertNull(actual: $this->service->getQuota(ownerId: 'alice', periodId: '2026-Q4', level: 'rep'));
	}//end testAnUnconfiguredAppReadsNoQuota()

	/**
	 * A missing quota, a quota without an amount, and a failing query all read as no amount.
	 *
	 * @return void
	 */
	public function testMissingAmountsReadAsNull(): void {
		$this->configure(register: 'crm', schema: 'quota');
		$this->quotas(amounts: ['bob/rep' => null]);

		self::assertNull(actual: $this->service->getQuotaAmount(ownerId: 'alice', periodId: 'p', level: 'rep'));
		self::assertNull(actual: $this->service->getQuotaAmount(ownerId: 'bob', periodId: 'p', level: 'rep'));
	}//end testMissingAmountsReadAsNull()

	/**
	 * A failing query is logged and reads as no quota.
	 *
	 * @return void
	 */
	public function testAFailingQueryIsLogged(): void {
		$this->configure(register: 'crm', schema: 'quota');
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects(self::once())->method('warning')
			->with('Pipelinq: quota query failed', ['exception' => 'db down']);

		self::assertNull(actual: $this->service->getQuota(ownerId: 'alice', periodId: 'p', level: 'team'));
	}//end testAFailingQueryIsLogged()

	/**
	 * The hierarchy report compares each team quota with the sum of its reps.
	 *
	 * @return void
	 */
	public function testReportsTheTeamAgainstItsReps(): void {
		$this->configure(register: 'crm', schema: 'quota');
		$this->quotas(
			amounts: [
				'north/team' => 100000.0,
				'alice/rep' => 60000.0,
				'bob/rep' => 55000.0,
				'carol/rep' => 10000.0,
			]
		);

		$report = $this->service->validateQuotaHierarchy(
			periodId: '2026-Q4',
			teamMembers: ['north' => ['alice', 'bob', 'nobody'], 'south' => ['carol']]
		);

		self::assertSame(
			expected: [
				['team_id' => 'north', 'team_quota' => 100000.0, 'rep_quotas_sum' => 115000.0, 'variance_percent' => 15.0],
				['team_id' => 'south', 'team_quota' => 0.0, 'rep_quotas_sum' => 10000.0, 'variance_percent' => 0.0],
			],
			actual: $report
		);
	}//end testReportsTheTeamAgainstItsReps()

	/**
	 * Configured thresholds apply; a zero or negative one falls back to the default.
	 *
	 * @return void
	 */
	public function testAtRiskThresholdsAreConfigurable(): void {
		$this->appConfig->method('getValueInt')->willReturnMap(
			[
				['pipelinq', QuotaService::AT_RISK_PERCENT_KEY, QuotaService::AT_RISK_PERCENT_DEFAULT, false, 75],
				['pipelinq', QuotaService::AT_RISK_DAYS_KEY, QuotaService::AT_RISK_DAYS_DEFAULT, false, -5],
			]
		);

		self::assertSame(expected: 75, actual: $this->service->getAtRiskPercent());
		self::assertSame(expected: QuotaService::AT_RISK_DAYS_DEFAULT, actual: $this->service->getAtRiskDays());
		self::assertFalse(condition: $this->service->isAtRisk(projected: 80.0, quota: 100.0, daysRemaining: 10));
		self::assertTrue(condition: $this->service->isAtRisk(projected: 70.0, quota: 100.0, daysRemaining: 10));
		self::assertFalse(condition: $this->service->isAtRisk(projected: 0.0, quota: 0.0, daysRemaining: 1));
	}//end testAtRiskThresholdsAreConfigurable()
}//end class
