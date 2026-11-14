<?php

/**
 * Coverage tests for SnapshotGenerationService::generate().
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
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ExchangeRateService;
use OCA\Pipelinq\Service\FiscalPeriodService;
use OCA\Pipelinq\Service\ForecastRollupService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\QuotaService;
use OCA\Pipelinq\Service\SnapshotGenerationService;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Hierarchy resolution, deal fetching, persistence, admin notification and
 * the quota-hierarchy report of a snapshot run.
 *
 * @covers \OCA\Pipelinq\Service\SnapshotGenerationService
 * @uses   \OCA\Pipelinq\Service\ExchangeRateService
 * @uses   \OCA\Pipelinq\Service\FiscalPeriodService
 * @uses   \OCA\Pipelinq\Service\ForecastRollupService
 * @uses   \OCA\Pipelinq\Service\RateOverrideStore
 * @uses   \OCA\Pipelinq\Service\Forecast\SnapshotLevel
 * @uses   \OCA\Pipelinq\Service\Forecast\SnapshotNode
 * @uses   \OCA\Pipelinq\Service\Forecast\SnapshotRun
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SnapshotGenerationServiceCoverageTest extends TestCase {
	/**
	 * App config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Group mocks by group id (null = group does not exist).
	 *
	 * @var array<string, IGroup|null>
	 */
	private array $groups = [];

	/**
	 * The group manager mock.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The quota service mock.
	 *
	 * @var QuotaService&MockObject
	 */
	private QuotaService&MockObject $quotaService;

	/**
	 * The notification service mock.
	 *
	 * @var NotificationService&MockObject
	 */
	private NotificationService&MockObject $notifier;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The service under test.
	 *
	 * @var SnapshotGenerationService
	 */
	private SnapshotGenerationService $service;

	/**
	 * Set up fixtures: real roll-up, exchange-rate and fiscal-period services,
	 * mocked I/O collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = [
			'register' => 'reg-1',
			'lead_schema' => 'lead-schema',
			'forecastSnapshot_schema' => 'snap-schema',
		];
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				if ($app !== 'pipelinq') {
					return $default;
				}

				return ($this->config[$key] ?? $default);
			}
		);

		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid): ?IGroup => ($this->groups[$gid] ?? null)
		);

		$this->quotaService = $this->createMock(originalClassName: QuotaService::class);
		$this->notifier = $this->createMock(originalClassName: NotificationService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);

		$exchange = new ExchangeRateService(appConfig: $appConfig);
		$this->service = new SnapshotGenerationService(
			appConfig: $appConfig,
			groupManager: $this->groupManager,
			rollup: new ForecastRollupService(exchangeRate: $exchange),
			exchangeRate: $exchange,
			period: new FiscalPeriodService(),
			quotaService: $this->quotaService,
			notifier: $this->notifier,
			logger: $this->logger,
			objectService: $this->objectService,
		);
	}//end setUp()

	/**
	 * A group mock with the given member uids.
	 *
	 * @param array<int, string> $uids Member uids.
	 *
	 * @return IGroup&MockObject
	 */
	private function group(array $uids): IGroup&MockObject {
		$users = [];
		foreach ($uids as $uid) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}

		$group = $this->createMock(originalClassName: IGroup::class);
		$group->method('getUsers')->willReturn($users);
		return $group;
	}//end group()

	/**
	 * An entity mock whose serialised form is the given data.
	 *
	 * @param array<string, mixed> $data The serialised data.
	 *
	 * @return ObjectEntityInterface&MockObject
	 */
	private function entity(array $data): ObjectEntityInterface&MockObject {
		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Capture every saved snapshot, returning a stored entity.
	 *
	 * @return array<int, array<string, mixed>> Reference to the saved list.
	 */
	private function &captureSaves(): array {
		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, $register, $schema, ?string $uuid) use (&$saved): ObjectEntityInterface {
				$this->assertSame(expected: [], actual: $extend);
				$this->assertSame(expected: 'reg-1', actual: $register);
				$this->assertSame(expected: 'snap-schema', actual: $schema);
				$this->assertNull(actual: $uuid);
				$saved[] = $object;
				return $this->entity(data: $object);
			}
		);
		return $saved;
	}//end captureSaves()

	/**
	 * Index snapshots by "level:owner".
	 *
	 * @param array<int, array<string, mixed>> $snapshots The snapshots.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function byKey(array $snapshots): array {
		$byKey = [];
		foreach ($snapshots as $snapshot) {
			$byKey[$snapshot['level'] . ':' . $snapshot['owner_id']] = $snapshot;
		}

		return $byKey;
	}//end byKey()

	/**
	 * A full run resolves teams from groups, divisions from the map, buckets
	 * the assignees' deals and persists every level.
	 *
	 * @return void
	 */
	public function testGeneratePersistsFullHierarchy(): void {
		$this->config['forecast_team_groups'] = ' team-east , ,team-west';
		$this->config['forecast_division_map'] = '{"team-east":"div-north","team-west":"div-north"}';
		$this->groups['team-east'] = $this->group(uids: ['rep.a', 'rep.b']);
		$this->groups['team-west'] = $this->group(uids: ['rep.c']);

		$this->objectService->expects($this->once())
			->method('findAll')
			->with(['filters' => ['register' => 'reg-1', 'schema' => 'lead-schema']])
			->willReturn(
				[
					$this->entity(data: ['id' => 'd1', 'assignee' => 'rep.a', 'forecast_category' => 'commit', 'value' => 100]),
					['id' => 'd2', 'assignee' => 'rep.c', 'forecast_category' => 'best_case', 'value' => 50],
					['id' => 'd3', 'assignee' => '', 'forecast_category' => 'commit', 'value' => 999],
					['id' => 'd4', 'forecast_category' => 'commit', 'value' => 999],
				]
			);
		$saved = &$this->captureSaves();
		$this->notifier->expects($this->never())->method('sendNotification');
		$this->logger->expects($this->never())->method('error');

		$result = $this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$this->assertSame(expected: 'Q2-2026', actual: $result['period_id']);
		$this->assertSame(expected: '2026-05-20', actual: $result['as_of']);
		$this->assertSame(expected: 7, actual: $result['generated']);
		$this->assertSame(expected: [], actual: $result['errors']);

		$byKey = self::byKey(snapshots: $saved);
		$this->assertSame(
			expected: ['rep:rep.a', 'rep:rep.b', 'rep:rep.c', 'team:team-east', 'team:team-west', 'division:div-north', 'company:company'],
			actual: array_keys($byKey)
		);
		$this->assertSame(expected: 100.0, actual: $byKey['rep:rep.a']['commit_amount']);
		$this->assertSame(expected: ['d1'], actual: $byKey['rep:rep.a']['deal_snapshot_ids']);
		$this->assertSame(expected: 0.0, actual: $byKey['rep:rep.b']['commit_amount']);
		$this->assertSame(expected: 50.0, actual: $byKey['rep:rep.c']['best_case_amount']);
		$this->assertSame(expected: 100.0, actual: $byKey['division:div-north']['commit_amount']);
		$this->assertSame(expected: 50.0, actual: $byKey['division:div-north']['best_case_amount']);
		$this->assertSame(expected: 100.0, actual: $byKey['company:company']['commit_amount']);
	}//end testGeneratePersistsFullHierarchy()

	/**
	 * The quota hierarchy is validated with the resolved team membership and
	 * returned as the hierarchy report.
	 *
	 * @return void
	 */
	public function testGenerateReturnsQuotaHierarchyReport(): void {
		$this->config['forecast_team_groups'] = 'team-east,team-ghost';
		$this->groups['team-east'] = $this->group(uids: ['rep.a']);
		$this->objectService->method('findAll')->willReturn([]);
		$this->captureSaves();

		$report = [
			['team_id' => 'team-east', 'team_quota' => 100.0, 'rep_quotas_sum' => 100.0, 'variance_percent' => 0.0],
		];
		$this->quotaService->expects($this->once())
			->method('validateQuotaHierarchy')
			->with('Q2-2026', ['team-east' => ['rep.a'], 'team-ghost' => []])
			->willReturn($report);
		$this->logger->expects($this->never())->method('info');

		$result = $this->service->generate(asOf: new DateTimeImmutable('2026-04-01'));

		$this->assertSame(expected: $report, actual: $result['hierarchy_report']);
		// Rep rep.a, two teams (one without members) and the company.
		$this->assertSame(expected: 4, actual: $result['generated']);
	}//end testGenerateReturnsQuotaHierarchyReport()

	/**
	 * A team whose quota differs from its reps' sum is logged; matching teams
	 * and teams without a variance key are not.
	 *
	 * @return void
	 */
	public function testGenerateLogsQuotaVariance(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$this->captureSaves();
		$this->quotaService->method('validateQuotaHierarchy')->willReturn(
			[
				['team_id' => 'team-ok', 'team_quota' => 10.0, 'rep_quotas_sum' => 10.0, 'variance_percent' => 0.0],
				['team_id' => 'team-under', 'team_quota' => 100.0, 'rep_quotas_sum' => 80.0, 'variance_percent' => -20.0],
				['team_id' => 'team-none', 'team_quota' => 5.0, 'rep_quotas_sum' => 5.0],
			]
		);
		$this->logger->expects($this->once())
			->method('info')
			->with(
				'Pipelinq: forecast quota hierarchy variance',
				[
					'period_id' => 'Q3-2026',
					'team_id' => 'team-under',
					'team_quota' => 100.0,
					'rep_quotas_sum' => 80.0,
					'variance_percent' => -20.0,
				]
			);

		$result = $this->service->generate(asOf: new DateTimeImmutable('2026-08-15'));
		$this->assertSame(expected: 'Q3-2026', actual: $result['period_id']);
	}//end testGenerateLogsQuotaVariance()

	/**
	 * Without configured teams only the company snapshot is generated.
	 *
	 * @return void
	 */
	public function testGenerateWithoutTeamsPersistsCompanyOnly(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$saved = &$this->captureSaves();
		$this->groupManager->expects($this->never())->method('get');
		$this->quotaService->expects($this->once())
			->method('validateQuotaHierarchy')
			->with('Q4-2026', [])
			->willReturn([]);

		$result = $this->service->generate(asOf: new DateTimeImmutable('2026-11-13'));

		$this->assertSame(expected: 1, actual: $result['generated']);
		$this->assertCount(expectedCount: 1, haystack: $saved);
		$this->assertSame(expected: 'company', actual: $saved[0]['level']);
		$this->assertSame(expected: 'company', actual: $saved[0]['owner_id']);
	}//end testGenerateWithoutTeamsPersistsCompanyOnly()

	/**
	 * Without an explicit date the run is dated today.
	 *
	 * @return void
	 */
	public function testGenerateDefaultsToToday(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$this->captureSaves();
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);

		$before = new DateTimeImmutable();
		$result = $this->service->generate();
		$after = new DateTimeImmutable();

		$this->assertContains(needle: $result['as_of'], haystack: [$before->format('Y-m-d'), $after->format('Y-m-d')]);
		$period = new FiscalPeriodService();
		$this->assertContains(needle: $result['period_id'], haystack: [$period->periodIdFor(date: $before), $period->periodIdFor(date: $after)]);
	}//end testGenerateDefaultsToToday()

	/**
	 * An unconfigured lead schema skips the deal fetch: every rep is zero.
	 *
	 * @return void
	 */
	public function testGenerateWithoutLeadSchemaSkipsDealFetch(): void {
		$this->config['lead_schema'] = '';
		$this->config['forecast_team_groups'] = 'team-east';
		$this->groups['team-east'] = $this->group(uids: ['rep.a']);
		$this->objectService->expects($this->never())->method('findAll');
		$saved = &$this->captureSaves();
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);

		$result = $this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$this->assertSame(expected: 3, actual: $result['generated']);
		$byKey = self::byKey(snapshots: $saved);
		$this->assertSame(expected: 0.0, actual: $byKey['rep:rep.a']['commit_amount']);
		$this->assertSame(expected: [], actual: $byKey['rep:rep.a']['deal_snapshot_ids']);
	}//end testGenerateWithoutLeadSchemaSkipsDealFetch()

	/**
	 * A failing deal fetch is logged and the run continues with no deals.
	 *
	 * @return void
	 */
	public function testGenerateDealFetchFailureIsLogged(): void {
		$this->config['forecast_team_groups'] = 'team-east';
		$this->groups['team-east'] = $this->group(uids: ['rep.a']);
		$this->objectService->expects($this->once())
			->method('findAll')
			->willThrowException(new RuntimeException('register offline'));
		$saved = &$this->captureSaves();
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);
		$this->logger->expects($this->once())
			->method('error')
			->with('Pipelinq: snapshot deal fetch failed', ['exception' => 'register offline']);

		$result = $this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$this->assertSame(expected: 3, actual: $result['generated']);
		$this->assertSame(expected: [], actual: $result['errors']);
		$this->assertSame(expected: 0.0, actual: self::byKey(snapshots: $saved)['company:company']['commit_amount']);
	}//end testGenerateDealFetchFailureIsLogged()

	/**
	 * Invalid or non-object division maps yield no division snapshots.
	 *
	 * @return void
	 */
	public function testGenerateIgnoresUnusableDivisionMap(): void {
		$this->config['forecast_team_groups'] = 'team-east';
		$this->groups['team-east'] = $this->group(uids: ['rep.a']);
		$this->objectService->method('findAll')->willReturn([]);
		$saved = &$this->captureSaves();
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);

		$this->config['forecast_division_map'] = '{not json';
		$this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));
		$this->config['forecast_division_map'] = '"div-north"';
		$this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$levels = array_column($saved, 'level');
		$this->assertSame(expected: ['rep', 'team', 'company', 'rep', 'team', 'company'], actual: $levels);
	}//end testGenerateIgnoresUnusableDivisionMap()

	/**
	 * A division map groups teams under their division by id.
	 *
	 * @return void
	 */
	public function testGenerateDivisionMapGroupsTeams(): void {
		$this->config['forecast_team_groups'] = 'team-east,team-west';
		$this->config['forecast_division_map'] = '{"team-east":"div-north","team-west":"div-south"}';
		$this->groups['team-east'] = $this->group(uids: ['rep.a']);
		$this->groups['team-west'] = $this->group(uids: ['rep.b']);
		$this->objectService->method('findAll')->willReturn(
			[
				['assignee' => 'rep.a', 'forecast_category' => 'commit', 'value' => 10],
				['assignee' => 'rep.b', 'forecast_category' => 'commit', 'value' => 30],
			]
		);
		$saved = &$this->captureSaves();
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);

		$this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$byKey = self::byKey(snapshots: $saved);
		$this->assertSame(expected: 10.0, actual: $byKey['division:div-north']['commit_amount']);
		$this->assertSame(expected: 30.0, actual: $byKey['division:div-south']['commit_amount']);
		$this->assertFalse(condition: $byKey['division:div-south']['partial']);
		$this->assertSame(expected: 40.0, actual: $byKey['company:company']['commit_amount']);
	}//end testGenerateDivisionMapGroupsTeams()

	/**
	 * Persist failures are collected, logged per snapshot and reported to
	 * every admin.
	 *
	 * @return void
	 */
	public function testGeneratePersistFailuresNotifyAdmins(): void {
		$this->config['forecast_team_groups'] = 'team-east';
		$this->groups['team-east'] = $this->group(uids: ['rep.a']);
		$this->groups['admin'] = $this->group(uids: ['admin1', 'admin2']);
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->expects($this->exactly(count: 3))
			->method('saveObject')
			->willReturnCallback(
				function (array $object): ObjectEntityInterface {
					if ($object['level'] === 'team') {
						throw new RuntimeException('validation failed');
					}

					return $this->entity(data: $object);
				}
			);
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Pipelinq: failed to persist forecast snapshot',
				['level' => 'team', 'owner_id' => 'team-east', 'exception' => 'validation failed']
			);

		$notified = [];
		$this->notifier->expects($this->exactly(count: 2))
			->method('sendNotification')
			->willReturnCallback(
				static function (string $userId, string $subject, array $parameters) use (&$notified): void {
					$notified[] = [$userId, $subject, $parameters];
				}
			);

		$result = $this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$this->assertSame(expected: 2, actual: $result['generated']);
		$this->assertSame(expected: ['team/team-east: validation failed'], actual: $result['errors']);
		$parameters = ['period' => 'Q2-2026', 'errors' => 'team/team-east: validation failed'];
		$this->assertSame(
			expected: [
				['admin1', 'forecast_snapshot_partial_failure', $parameters],
				['admin2', 'forecast_snapshot_partial_failure', $parameters],
			],
			actual: $notified
		);
	}//end testGeneratePersistFailuresNotifyAdmins()

	/**
	 * Without a snapshot schema every snapshot fails with a clear message,
	 * the admins get all the errors joined, and nothing is saved.
	 *
	 * @return void
	 */
	public function testGenerateUnconfiguredSnapshotSchemaFailsEverySnapshot(): void {
		$this->config['forecastSnapshot_schema'] = '';
		$this->config['forecast_team_groups'] = 'team-east';
		$this->groups['team-east'] = $this->group(uids: ['rep.a']);
		$this->groups['admin'] = $this->group(uids: ['admin1']);
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);
		$this->logger->expects($this->exactly(count: 3))->method('error');

		$expected = [
			'rep/rep.a: Forecast snapshot schema is not configured.',
			'team/team-east: Forecast snapshot schema is not configured.',
			'company/company: Forecast snapshot schema is not configured.',
		];
		$this->notifier->expects($this->once())
			->method('sendNotification')
			->with('admin1', 'forecast_snapshot_partial_failure', ['period' => 'Q2-2026', 'errors' => implode('; ', $expected)]);

		$result = $this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$this->assertSame(expected: 0, actual: $result['generated']);
		$this->assertSame(expected: $expected, actual: $result['errors']);
	}//end testGenerateUnconfiguredSnapshotSchemaFailsEverySnapshot()

	/**
	 * Without a register no snapshot is saved either.
	 *
	 * @return void
	 */
	public function testGenerateMissingRegisterFailsPersistence(): void {
		$this->config['register'] = '';
		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('saveObject');
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);

		$result = $this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$this->assertSame(expected: 0, actual: $result['generated']);
		$this->assertSame(expected: ['company/company: Forecast snapshot schema is not configured.'], actual: $result['errors']);
	}//end testGenerateMissingRegisterFailsPersistence()

	/**
	 * Without an admin group nobody is notified, and nothing breaks.
	 *
	 * @return void
	 */
	public function testGenerateFailureWithoutAdminGroupNotifiesNobody(): void {
		$this->config['forecastSnapshot_schema'] = '';
		$this->objectService->method('findAll')->willReturn([]);
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);
		$this->groupManager->expects($this->once())->method('get')->with('admin');
		$this->notifier->expects($this->never())->method('sendNotification');
		$this->logger->expects($this->never())->method('warning');

		$result = $this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$this->assertCount(expectedCount: 1, haystack: $result['errors']);
	}//end testGenerateFailureWithoutAdminGroupNotifiesNobody()

	/**
	 * A failing notification is logged as a warning and the summary is still returned.
	 *
	 * @return void
	 */
	public function testGenerateNotificationFailureIsLogged(): void {
		$this->config['forecastSnapshot_schema'] = '';
		$this->groups['admin'] = $this->group(uids: ['admin1', 'admin2']);
		$this->objectService->method('findAll')->willReturn([]);
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);
		$this->notifier->expects($this->once())
			->method('sendNotification')
			->willThrowException(new RuntimeException('notifier down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('Pipelinq: failed to notify admin of snapshot failures', ['exception' => 'notifier down']);

		$result = $this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$this->assertSame(expected: 'Q2-2026', actual: $result['period_id']);
		$this->assertSame(expected: 0, actual: $result['generated']);
	}//end testGenerateNotificationFailureIsLogged()

	/**
	 * Deals returned as entities exposing only getObject() are grouped too.
	 *
	 * @return void
	 */
	public function testGenerateReadsDealsFromGetObject(): void {
		$this->config['forecast_team_groups'] = 'team-east';
		$this->groups['team-east'] = $this->group(uids: ['rep.a']);
		$deal = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$deal->method('jsonSerialize')->willReturn(null);
		$deal->method('getObject')->willReturn(['uuid' => 'd9', 'assignee' => 'rep.a', 'forecast_category' => 'closed_won', 'value' => 7]);
		$this->objectService->method('findAll')->willReturn([$deal, 'not-a-deal']);
		$saved = &$this->captureSaves();
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);

		$this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$rep = self::byKey(snapshots: $saved)['rep:rep.a'];
		$this->assertSame(expected: 7.0, actual: $rep['closed_won_amount']);
		$this->assertSame(expected: ['d9'], actual: $rep['deal_snapshot_ids']);
	}//end testGenerateReadsDealsFromGetObject()

	/**
	 * Every saved snapshot carries the run's period, date and reporting currency.
	 *
	 * @return void
	 */
	public function testGenerateStampsRunOnEverySnapshot(): void {
		$this->config['forecast_team_groups'] = 'team-east';
		$this->config['forecast_reporting_currency'] = 'usd';
		$this->groups['team-east'] = $this->group(uids: ['rep.a']);
		$this->objectService->method('findAll')->willReturn([]);
		$saved = &$this->captureSaves();
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);

		$this->service->generate(asOf: new DateTimeImmutable('2026-02-10'));

		$this->assertCount(expectedCount: 3, haystack: $saved);
		foreach ($saved as $snapshot) {
			$this->assertSame(expected: 'Q1-2026', actual: $snapshot['period_id']);
			$this->assertSame(expected: '2026-02-10', actual: $snapshot['as_of_date']);
			$this->assertSame(expected: 'USD', actual: $snapshot['currency']);
		}
	}//end testGenerateStampsRunOnEverySnapshot()
	/**
	 * A positive quota asks whether the projected attainment is at risk,
	 * with the run's days remaining, and records the answer.
	 *
	 * @return void
	 */
	public function testGenerateAsksAtRiskForPositiveQuota(): void {
		$this->config['forecast_team_groups'] = 'team-east';
		$this->groups['team-east'] = $this->group(uids: ['rep.a']);
		$this->objectService->method('findAll')->willReturn(
			[['assignee' => 'rep.a', 'forecast_category' => 'commit', 'value' => 300]]
		);
		$saved = &$this->captureSaves();
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);
		$this->quotaService->method('getQuotaAmount')->willReturnCallback(
			static function (string $ownerId, string $periodId, string $level): ?float {
				if ($ownerId === 'rep.a' && $periodId === 'Q2-2026' && $level === 'rep') {
					return 1000.0;
				}

				return null;
			}
		);
		$this->quotaService->method('projectedAttainment')->willReturnCallback(
			static fn (float $closedWon, float $commit, float $bestCase): float => ($closedWon + $commit + $bestCase)
		);
		$daysRemaining = (new FiscalPeriodService())->daysRemaining(periodId: 'Q2-2026', now: new DateTimeImmutable('2026-05-20'));
		$this->quotaService->expects($this->once())
			->method('isAtRisk')
			->with(300.0, 1000.0, $daysRemaining)
			->willReturn(true);

		$this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$byKey = self::byKey(snapshots: $saved);
		$this->assertTrue(condition: $byKey['rep:rep.a']['at_risk']);
		$this->assertSame(expected: 1000.0, actual: $byKey['rep:rep.a']['quota_amount']);
		$this->assertSame(expected: $daysRemaining, actual: $byKey['rep:rep.a']['days_remaining']);
		$this->assertFalse(condition: $byKey['rep:rep.a']['period_closed']);
		$this->assertFalse(condition: $byKey['team:team-east']['at_risk']);
		$this->assertNull(actual: $byKey['team:team-east']['quota_amount']);
	}//end testGenerateAsksAtRiskForPositiveQuota()

	/**
	 * A zero quota is never at risk and never asks.
	 *
	 * @return void
	 */
	public function testGenerateZeroQuotaIsNeverAtRisk(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$saved = &$this->captureSaves();
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);
		$this->quotaService->method('getQuotaAmount')->willReturn(0.0);
		$this->quotaService->expects($this->never())->method('isAtRisk');

		$this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$this->assertFalse(condition: $saved[0]['at_risk']);
		$this->assertSame(expected: 0.0, actual: $saved[0]['quota_amount']);
	}//end testGenerateZeroQuotaIsNeverAtRisk()

	/**
	 * A foreign-currency deal puts the rate used on every saved snapshot.
	 *
	 * @return void
	 */
	public function testGenerateForeignDealCarriesRates(): void {
		$this->config['forecast_team_groups'] = 'team-east';
		$this->groups['team-east'] = $this->group(uids: ['rep.a']);
		$this->objectService->method('findAll')->willReturn(
			[['assignee' => 'rep.a', 'forecast_category' => 'commit', 'value' => 10, 'currency' => 'GBP']]
		);
		$saved = &$this->captureSaves();
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);

		$this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$this->assertCount(expectedCount: 3, haystack: $saved);
		foreach ($saved as $snapshot) {
			$this->assertSame(expected: ['GBP'], actual: array_keys($snapshot['exchange_rates']));
		}
	}//end testGenerateForeignDealCarriesRates()

	/**
	 * A division mapped to a team that is not configured is saved partial,
	 * naming the missing team.
	 *
	 * @return void
	 */
	public function testGenerateDivisionWithUnconfiguredTeamIsPartial(): void {
		$this->config['forecast_team_groups'] = 'team-east';
		$this->config['forecast_division_map'] = '{"team-east":"div-north","team-gone":"div-north"}';
		$this->groups['team-east'] = $this->group(uids: ['rep.a']);
		$this->objectService->method('findAll')->willReturn([]);
		$saved = &$this->captureSaves();
		$this->quotaService->method('validateQuotaHierarchy')->willReturn([]);

		$this->service->generate(asOf: new DateTimeImmutable('2026-05-20'));

		$division = self::byKey(snapshots: $saved)['division:div-north'];
		$this->assertTrue(condition: $division['partial']);
		$this->assertSame(expected: ['team-gone'], actual: $division['missing_reps']);
	}//end testGenerateDivisionWithUnconfiguredTeamIsPartial()
}//end class
