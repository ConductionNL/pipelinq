<?php

/**
 * Pipelinq BrpMonitorJob.
 *
 * Daily aggregator that scans the BsnAuditRecord schema for the last 24 hours and
 * computes lookup counts, cache-hit ratio, average response time, and error rate.
 * Result is cached in app-config for the admin BRP-Monitor tile.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * BRP availability + SLA monitor.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-010
 */
class BrpMonitorJob extends TimedJob {
	/**
	 * Default interval (24h).
	 */
	private const DEFAULT_INTERVAL_SECONDS = 86400;

	/**
	 * Default error-rate alert threshold (10% of attempts).
	 */
	private const ERROR_RATE_ALERT_THRESHOLD = 0.10;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param IAppConfig $appConfig App config.
	 * @param IGroupManager $groupManager Group manager.
	 * @param INotificationManager $notificationManager NC notifications.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		ITimeFactory $time,
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private INotificationManager $notificationManager,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
		parent::__construct(time: $time);
		$this->setInterval(
			seconds: $this->appConfig->getValueInt(
				AppIdentity::APP_ID,
				'brp.monitor_interval_seconds',
				self::DEFAULT_INTERVAL_SECONDS
			)
		);
	}//end __construct()

	/**
	 * Aggregate the last 24h of BSN audit records.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-010
	 */
	protected function run(mixed $argument): void {
		try {
			$this->monitor();
		} catch (Throwable $e) {
			$this->logger->error('BRP monitor job failed', ['error' => $e->getMessage()]);
		}
	}//end run()

	/**
	 * Build, store and, when the error rate is too high, alert the 24-hour report.
	 *
	 * @return void
	 */
	private function monitor(): void {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'bsnAuditRecord_schema', '');
		if ($this->isAuditConfigured(register: $register, schema: $schema) === false) {
			return;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$window = $now->modify('-24 hours');

		$audit = $this->aggregateAuditRecords(records: $this->auditRecords(register: $register, schema: $schema), window: $window);
		// Cache-hit ratio is sourced from brpLookupVerzoek.responseInCache (more accurate).
		$requestSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'brpLookupVerzoek_schema', '');
		$request = $this->aggregateVerzoeken(verzoeken: $this->requestRecords(register: $register, requestSchema: $requestSchema), window: $window);

		$report = ['windowStart' => $window->format(DATE_ATOM), 'windowEnd' => $now->format(DATE_ATOM)]
			+ self::figures(audit: $audit, request: $request)
			+ ['generatedAt' => $now->format(DATE_ATOM)];
		$this->publishReport(report: $report);
	}//end monitor()

	/**
	 * Whether the register and the audit schema are configured; a skip is noted in the log.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The BSN audit record schema slug.
	 *
	 * @return bool True when both are configured.
	 */
	private function isAuditConfigured(string $register, string $schema): bool {
		if ($register === '' || $schema === '') {
			$this->logger->info('BRP monitor: audit schema not configured; skipping');
			return false;
		}

		return true;
	}//end isAuditConfigured()

	/**
	 * The executed-lookup audit records.
	 *
	 * The findAll() contract returns a non-nullable array, so no `?? []` is needed.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The BSN audit record schema slug.
	 *
	 * @return array<int, mixed> The records.
	 */
	private function auditRecords(string $register, string $schema): array {
		return $this->objectService->findAll(
			config: [
				'filters' => [
					'action' => 'brp-lookup-executed',
					'register' => $register,
					'schema' => $schema,
				],
			]
		);
	}//end auditRecords()

	/**
	 * The brpLookupVerzoek records; none when that schema is not configured.
	 *
	 * @param string $register      The register slug.
	 * @param string $requestSchema The brpLookupVerzoek schema slug (empty = skip).
	 *
	 * @return array<int, mixed> The records.
	 */
	private function requestRecords(string $register, string $requestSchema): array {
		if ($requestSchema === '') {
			return [];
		}

		return $this->objectService->findAll(
			config: ['filters' => ['register' => $register, 'schema' => $requestSchema]]
		);
	}//end requestRecords()

	/**
	 * The report figures of the audit and request aggregates.
	 *
	 * @param array{total: int, errors: int, hits: int}                                $audit   The audit aggregate.
	 * @param array{totalVerzoek: int, cacheHits: int, durations: array<int, int>}     $request The request aggregate.
	 *
	 * @return array<string, int|float> The figures, in report order.
	 */
	private static function figures(array $audit, array $request): array {
		return [
			'totalLookups' => $audit['total'],
			'successfulLookups' => $audit['hits'],
			'errorCount' => $audit['errors'],
			'errorRate' => self::ratio(part: $audit['errors'], whole: $audit['total']),
			'cacheHits' => $request['cacheHits'],
			'cacheHitRatio' => self::ratio(part: $request['cacheHits'], whole: $request['totalVerzoek']),
			'avgResponseMs' => self::average(values: $request['durations']),
		];
	}//end figures()

	/**
	 * A share rounded to four decimals, 0.0 of nothing.
	 *
	 * @param int $part  The part.
	 * @param int $whole The whole.
	 *
	 * @return float The share.
	 */
	private static function ratio(int $part, int $whole): float {
		if ($whole > 0) {
			return round($part / $whole, 4);
		}

		return 0.0;
	}//end ratio()

	/**
	 * The rounded average, 0 of nothing.
	 *
	 * @param array<int, int> $values The values.
	 *
	 * @return int The average.
	 */
	private static function average(array $values): int {
		if (count($values) > 0) {
			return (int)round(array_sum($values) / count($values));
		}

		return 0;
	}//end average()

	/**
	 * Store the report and alert the admins when the error rate reaches the threshold.
	 *
	 * @param array<string, mixed> $report The report.
	 *
	 * @return void
	 *
	 */
	private function publishReport(array $report): void {
		$this->appConfig->setValueString(
			AppIdentity::APP_ID,
			'brp.monitor_report',
			json_encode($report, JSON_THROW_ON_ERROR)
		);

		if ($report['errorRate'] >= self::ERROR_RATE_ALERT_THRESHOLD && $report['totalLookups'] > 0) {
			$this->notifyAdmins(report: $report);
		}
	}//end publishReport()

	/**
	 * Coerce an OpenRegister record (array or entity) to a plain array.
	 *
	 * @param mixed $rec Record from ObjectService::findAll().
	 *
	 * @return array<string, mixed> Array representation (empty when unusable).
	 */
	private function recordToArray(mixed $rec): array {
		if (is_array($rec) === true) {
			return $rec;
		}

		if (method_exists($rec, 'jsonSerialize') === true) {
			return (array)$rec->jsonSerialize();
		}

		return [];
	}//end recordToArray()

	/**
	 * Aggregate audit records inside the rolling window.
	 *
	 * @param iterable<mixed> $records BSN audit records.
	 * @param DateTimeImmutable $window Lower time bound (inclusive).
	 *
	 * @return array{total: int, errors: int, hits: int}
	 */
	private function aggregateAuditRecords(iterable $records, DateTimeImmutable $window): array {
		$total = 0;
		$errors = 0;
		$hits = 0;
		foreach ($records as $rec) {
			$arr = $this->recordToArray(rec: $rec);
			$moment = (string)($arr['moment'] ?? '');
			if ($moment === '' || self::isAtOrAfter(moment: $moment, window: $window) === false) {
				continue;
			}

			$total++;
			$outcome = (string)($arr['outcome'] ?? '');
			$errors += (int)in_array($outcome, ['error', 'timeout'], true);
			$hits += (int)($outcome === 'succeeded');
		}

		return ['total' => $total, 'errors' => $errors, 'hits' => $hits];
	}//end aggregateAuditRecords()

	/**
	 * Whether a record's moment is a date inside the window; an empty moment
	 * reads as now.
	 *
	 * @param string            $moment The moment as the record wrote it.
	 * @param DateTimeImmutable $window Lower time bound (inclusive).
	 *
	 * @return bool False for an unreadable moment, or one before the window.
	 */
	private static function isAtOrAfter(string $moment, DateTimeImmutable $window): bool {
		try {
			return new DateTimeImmutable($moment) >= $window;
		} catch (Throwable $e) {
			return false;
		}
	}//end isAtOrAfter()

	/**
	 * Aggregate brpLookupVerzoek records for cache-hit ratio and durations.
	 *
	 * @param iterable<mixed>   $verzoeken The brpLookupVerzoek records.
	 * @param DateTimeImmutable $window    Lower time bound (inclusive).
	 *
	 * @return array{totalVerzoek: int, cacheHits: int, durations: array<int, int>}
	 */
	private function aggregateVerzoeken(iterable $verzoeken, DateTimeImmutable $window): array {
		$aggregate = ['totalVerzoek' => 0, 'cacheHits' => 0, 'durations' => []];
		foreach ($verzoeken as $rec) {
			$arr = $this->recordToArray(rec: $rec);
			if (self::isAtOrAfter(moment: (string)($arr['requestMoment'] ?? ''), window: $window) === true) {
				$aggregate = self::withVerzoek(aggregate: $aggregate, verzoek: $arr);
			}
		}

		return $aggregate;
	}//end aggregateVerzoeken()

	/**
	 * Count one in-window lookup request into the aggregate.
	 *
	 * @param array{totalVerzoek: int, cacheHits: int, durations: array<int, int>} $aggregate The aggregate so far.
	 * @param array<string, mixed>                                                $verzoek   The request record.
	 *
	 * @return array{totalVerzoek: int, cacheHits: int, durations: array<int, int>} The aggregate with the request.
	 */
	private static function withVerzoek(array $aggregate, array $verzoek): array {
		$aggregate['totalVerzoek']++;
		if (($verzoek['responseInCache'] ?? false) === true) {
			$aggregate['cacheHits']++;
		}

		if (isset($verzoek['responseDurationMs']) === true) {
			$aggregate['durations'][] = (int)$verzoek['responseDurationMs'];
		}

		return $aggregate;
	}//end withVerzoek()

	/**
	 * Send an admin notification when the error rate breaches the alert threshold.
	 *
	 * @param array<string,mixed> $report Aggregated monitor report.
	 *
	 * @return void
	 */
	private function notifyAdmins(array $report): void {
		$admins = $this->groupManager->get('admin');
		if ($admins === null) {
			return;
		}

		foreach ($admins->getUsers() as $admin) {
			$this->notifyAdmin(uid: $admin->getUID(), report: $report);
		}
	}//end notifyAdmins()

	/**
	 * Send one admin the error-rate notification; a failure is logged.
	 *
	 * @param string              $uid    The admin's user id.
	 * @param array<string,mixed> $report Aggregated monitor report.
	 *
	 * @return void
	 */
	private function notifyAdmin(string $uid, array $report): void {
		try {
			$n = $this->notificationManager->createNotification();
			$n->setApp(AppIdentity::APP_ID)
				->setUser($uid)
				->setObject('brp-monitor', 'error-rate')
				->setSubject('brp_error_rate', self::alertParameters(report: $report))
				->setDateTime(new DateTime());
			$this->notificationManager->notify($n);
		} catch (Throwable $e) {
			$this->logger->warning('BRP monitor notify failed', ['admin' => $uid, 'error' => $e->getMessage()]);
		}
	}//end notifyAdmin()

	/**
	 * The subject parameters of the error-rate notification.
	 *
	 * @param array<string,mixed> $report Aggregated monitor report.
	 *
	 * @return array<string, string> The parameters.
	 */
	private static function alertParameters(array $report): array {
		return [
			'errorRate' => (string)$report['errorRate'],
			'totalLookups' => (string)$report['totalLookups'],
			'errorCount' => (string)$report['errorCount'],
		];
	}//end alertParameters()
}//end class
