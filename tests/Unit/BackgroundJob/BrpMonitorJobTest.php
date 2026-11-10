<?php

/**
 * Characterisation tests for the BRP availability and SLA monitor.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use ArrayObject;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\BackgroundJob\BrpMonitorJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The 24-hour report, the alert and every skip of the monitor.
 */
class BrpMonitorJobTest extends TestCase {
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
	 * Without the register or the audit schema the run is skipped with a note.
	 *
	 * @return void
	 */
	public function testUnconfiguredIsSkipped(): void {
		foreach ([['register' => ''], ['bsnAuditRecord_schema' => '']] as $config) {
			$this->calls = new ArrayObject();
			$this->runMonitor(config: $config, audit: [], requests: []);
			$this->assertSame(
				[['info', 'BRP monitor: audit schema not configured; skipping', []]],
				array_values(array_filter($this->calls->getArrayCopy(), static fn (array $call): bool => $call[0] !== 'config'))
			);
		}
	}//end testUnconfiguredIsSkipped()

	/**
	 * The report counts the lookups inside the last 24 hours, their errors,
	 * the cache hits and the average duration, and alerts every admin when
	 * the error rate reaches 10%; a failing alert is logged and the next one
	 * is still sent.
	 *
	 * @return void
	 */
	public function testReportAndAlert(): void {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$ago = static fn (string $modify): string => $now->modify($modify)->format(DATE_ATOM);
		$entity = new class($ago('-90 minutes')) {
			public function __construct(
				private string $moment,
			) {
			}

			public function jsonSerialize(): array {
				return ['moment' => $this->moment, 'outcome' => 'succeeded'];
			}
		};
		$audit = [
			['moment' => $ago('-1 hour'), 'outcome' => 'succeeded'],
			['moment' => $ago('-2 hours'), 'outcome' => 'error'],
			['moment' => $ago('-3 hours'), 'outcome' => 'timeout'],
			['moment' => $ago('-4 hours'), 'outcome' => 'denied'],
			['moment' => $ago('-30 hours'), 'outcome' => 'error'],
			['moment' => 'not a date', 'outcome' => 'error'],
			['outcome' => 'error'],
			$entity,
		];
		$requests = [
			['requestMoment' => $ago('-1 hour'), 'responseInCache' => true, 'responseDurationMs' => '100'],
			['requestMoment' => $ago('-2 hours'), 'responseInCache' => 'yes', 'responseDurationMs' => 301],
			['requestMoment' => $ago('-3 hours')],
			['requestMoment' => $ago('-25 hours'), 'responseInCache' => true, 'responseDurationMs' => 9000],
			['requestMoment' => 'garbage', 'responseInCache' => true],
		];

		$this->runMonitor(config: [], audit: $audit, requests: $requests);
		$calls = array_values(array_filter($this->calls->getArrayCopy(), static fn (array $call): bool => $call[0] !== 'config'));

		$this->assertSame(['findAll', ['filters' => ['action' => 'brp-lookup-executed', 'register' => 'reg', 'schema' => 'audit']]], $calls[0]);
		$this->assertSame(['findAll', ['filters' => ['register' => 'reg', 'schema' => 'verzoek']]], $calls[1]);
		$this->assertSame('set', $calls[2][0]);
		$this->assertSame('brp.monitor_report', $calls[2][1]);
		$report = json_decode($calls[2][2], true);
		$this->assertSame(['windowStart', 'windowEnd', 'totalLookups', 'successfulLookups', 'errorCount', 'errorRate', 'cacheHits', 'cacheHitRatio', 'avgResponseMs', 'generatedAt'], array_keys($report));
		$this->assertSame([5, 2, 2, 0.4, 1, 0.3333, 201], [$report['totalLookups'], $report['successfulLookups'], $report['errorCount'], $report['errorRate'], $report['cacheHits'], $report['cacheHitRatio'], $report['avgResponseMs']]);
		$this->assertSame($report['windowEnd'], $report['generatedAt']);
		$this->assertSame(
			(new DateTimeImmutable($report['windowEnd']))->modify('-24 hours')->format(DATE_ATOM),
			$report['windowStart']
		);
		$subject = ['errorRate' => '0.4', 'totalLookups' => '5', 'errorCount' => '2'];
		$this->assertSame(
			[
				['notify', 'pipelinq', 'admin-1', 'brp-monitor', 'error-rate', 'brp_error_rate', $subject],
				['warning', 'BRP monitor notify failed', ['admin' => 'admin-2', 'error' => 'push down']],
				['notify', 'pipelinq', 'admin-3', 'brp-monitor', 'error-rate', 'brp_error_rate', $subject],
			],
			array_slice($calls, 3)
		);
	}//end testReportAndAlert()

	/**
	 * No alert below 10%, none without lookups, none without an admin group;
	 * without the request schema the cache figures are zero and not looked up.
	 *
	 * @return void
	 */
	public function testNoAlertAndNoRequestSchema(): void {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$ok = ['moment' => $now->modify('-1 hour')->format(DATE_ATOM), 'outcome' => 'succeeded'];
		$this->runMonitor(config: ['brpLookupVerzoek_schema' => ''], audit: array_fill(0, 10, $ok), requests: [['requestMoment' => $ok['moment']]]);
		$calls = array_values(array_filter($this->calls->getArrayCopy(), static fn (array $call): bool => $call[0] !== 'config'));
		$this->assertCount(2, $calls);
		$report = json_decode($calls[1][2], true);
		$this->assertSame([10, 10, 0, 0, 0, 0, 0], [$report['totalLookups'], $report['successfulLookups'], $report['errorCount'], $report['errorRate'], $report['cacheHits'], $report['cacheHitRatio'], $report['avgResponseMs']]);

		$this->calls = new ArrayObject();
		$this->runMonitor(config: [], audit: [], requests: []);
		$this->assertSame(['findAll', 'findAll', 'set'], array_column(array_values(array_filter($this->calls->getArrayCopy(), static fn (array $call): bool => $call[0] !== 'config')), 0));

		$this->calls = new ArrayObject();
		$this->runMonitor(config: [], audit: [['moment' => $ok['moment'], 'outcome' => 'error']], requests: [], admins: false);
		$this->assertSame(['findAll', 'findAll', 'set'], array_column(array_values(array_filter($this->calls->getArrayCopy(), static fn (array $call): bool => $call[0] !== 'config')), 0));
	}//end testNoAlertAndNoRequestSchema()

	/**
	 * A failing lookup is logged and stops the run.
	 *
	 * @return void
	 */
	public function testFailureIsLogged(): void {
		$this->runMonitor(config: [], audit: null, requests: []);
		$this->assertSame(
			[['findAll', ['filters' => ['action' => 'brp-lookup-executed', 'register' => 'reg', 'schema' => 'audit']]], ['error', 'BRP monitor job failed', ['error' => 'store down']]],
			array_values(array_filter($this->calls->getArrayCopy(), static fn (array $call): bool => $call[0] !== 'config'))
		);
	}//end testFailureIsLogged()

	/**
	 * Build the job over recording doubles and run it once.
	 *
	 * @param array<string, string>  $config   App-config overrides.
	 * @param array<int, mixed>|null $audit    The audit records, null to throw.
	 * @param array<int, mixed>      $requests The lookup request records.
	 * @param bool                   $admins   Whether there is an admin group.
	 *
	 * @return void
	 */
	private function runMonitor(array $config, ?array $audit, array $requests, bool $admins = true): void {
		$calls = $this->calls;
		$values = $config + ['register' => 'reg', 'bsnAuditRecord_schema' => 'audit', 'brpLookupVerzoek_schema' => 'verzoek'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($values, $calls): string {
				$calls[] = ['config', $key];
				return ($values[$key] ?? $default);
			}
		);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($calls): bool {
				$calls[] = ['set', $key, $value];
				return true;
			}
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($calls, $audit, $requests): array {
				$calls[] = ['findAll', $config];
				if (isset($config['filters']['action']) === true) {
					if ($audit === null) {
						throw new RuntimeException('store down');
					}

					return $audit;
				}

				return $requests;
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$group = $this->createMock(IGroup::class);
		$users = [];
		foreach (['admin-1', 'admin-2', 'admin-3'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}

		$group->method('getUsers')->willReturn($users);
		$groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => ($admins === true && $gid === 'admin') ? $group : null);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(
			function (): INotification {
				$state = new ArrayObject();
				$notification = $this->createMock(INotification::class);
				$notification->method('setApp')->willReturnCallback(static function (string $app) use ($state, $notification) {
					$state['app'] = $app;
					return $notification;
				});
				$notification->method('setUser')->willReturnCallback(static function (string $user) use ($state, $notification) {
					$state['user'] = $user;
					return $notification;
				});
				$notification->method('setObject')->willReturnCallback(static function (string $type, string $id) use ($state, $notification) {
					$state['object'] = [$type, $id];
					return $notification;
				});
				$notification->method('setSubject')->willReturnCallback(static function (string $subject, array $parameters = []) use ($state, $notification) {
					$state['subject'] = [$subject, $parameters];
					return $notification;
				});
				$notification->method('setDateTime')->willReturnSelf();
				$notification->method('getUser')->willReturnCallback(static fn (): string => $state['user']);
				$notification->method('getApp')->willReturnCallback(static fn (): string => $state['app']);
				$notification->method('getObjectType')->willReturnCallback(static fn (): string => $state['object'][0]);
				$notification->method('getObjectId')->willReturnCallback(static fn (): string => $state['object'][1]);
				$notification->method('getSubject')->willReturnCallback(static fn (): string => $state['subject'][0]);
				$notification->method('getSubjectParameters')->willReturnCallback(static fn (): array => $state['subject'][1]);
				return $notification;
			}
		);
		$notifications->method('notify')->willReturnCallback(
			static function (INotification $notification) use ($calls): void {
				if ($notification->getUser() === 'admin-2') {
					throw new RuntimeException('push down');
				}

				$calls[] = ['notify', $notification->getApp(), $notification->getUser(), $notification->getObjectType(), $notification->getObjectId(), $notification->getSubject(), $notification->getSubjectParameters()];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use ($calls, $level): void {
					$calls[] = [$level, $message, $context];
				}
			);
		}

		$job = new BrpMonitorJob($this->createMock(ITimeFactory::class), $appConfig, $groups, $notifications, $logger, $objects);
		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}//end runMonitor()
}//end class
