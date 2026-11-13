<?php

/**
 * Pipelinq BrpHealthCheckJob.
 *
 * Daily background job that inspects the mTLS client-certificate expiry. When the
 * certificate expires within configurable thresholds (30/14/7 days) a Nextcloud
 * notification is sent to admins and the result is cached for the admin BRP-Monitor tile.
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
use OCA\Pipelinq\Kernel\Contracts\HaalCentraalClientInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Once-per-day client-certificate health check.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003-02
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-010-03
 */
class BrpHealthCheckJob extends TimedJob {
	/**
	 * Run interval (24h).
	 */
	private const DEFAULT_INTERVAL_SECONDS = 86400;

	/**
	 * Warn threshold in days.
	 */
	private const WARN_THRESHOLD_DAYS = 30;

	/**
	 * Critical threshold in days.
	 */
	private const CRITICAL_THRESHOLD_DAYS = 7;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param IAppConfig $appConfig App config.
	 * @param IGroupManager $groupManager Group manager (for admin enumeration).
	 * @param INotificationManager $notificationManager Nextcloud notification manager.
	 * @param HaalCentraalClientInterface $client HaalCentraal client (cert reader).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private INotificationManager $notificationManager,
		private HaalCentraalClientInterface $client,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(
			seconds: $this->appConfig->getValueInt(
				AppIdentity::APP_ID,
				'brp.health_check_interval_seconds',
				self::DEFAULT_INTERVAL_SECONDS
			)
		);
	}//end __construct()

	/**
	 * Run the health check.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
	 */
	protected function run(mixed $argument): void {
		try {
			$this->checkCertificate();
		} catch (Throwable $e) {
			$this->logger->error('BRP health check failed', ['error' => $e->getMessage()]);
		}
	}//end run()

	/**
	 * Record the certificate's health, and tell the admins when it is not ok.
	 *
	 * @return void
	 */
	private function checkCertificate(): void {
		$expiry = $this->client->getCertificateExpiry();
		if ($expiry === null) {
			$this->logger->info('BRP cert health check: cert not configured');
			$this->saveStatus(status: ['expiry' => null, 'status' => 'unconfigured', 'checkedAt' => $this->nowIso()]);
			return;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$daysLeft = (int)floor(($expiry->getTimestamp() - $now->getTimestamp()) / 86400);
		$status = self::statusFor(daysLeft: $daysLeft);

		$this->saveStatus(
			status: [
				'expiry' => $expiry->format(DATE_ATOM),
				'daysLeft' => $daysLeft,
				'status' => $status,
				'checkedAt' => $this->nowIso(),
			]
		);

		if ($status !== 'ok') {
			$this->notifyAdmins(daysLeft: $daysLeft, status: $status, expiry: $expiry);
		}
	}//end checkCertificate()

	/**
	 * The status for a number of days left: `critical` at 7 or fewer, `warning` at 30 or fewer, else `ok`.
	 *
	 * @param int $daysLeft Whole days left, rounded down.
	 *
	 * @return string
	 */
	private static function statusFor(int $daysLeft): string {
		$status = 'ok';
		if ($daysLeft <= self::CRITICAL_THRESHOLD_DAYS) {
			$status = 'critical';
		} elseif ($daysLeft <= self::WARN_THRESHOLD_DAYS) {
			$status = 'warning';
		}

		return $status;
	}//end statusFor()

	/**
	 * Persist the health-check snapshot for the admin tile.
	 *
	 * @param array<string,mixed> $status The health-check snapshot to persist.
	 *
	 * @return void
	 */
	private function saveStatus(array $status): void {
		$this->appConfig->setValueString(
			AppIdentity::APP_ID,
			'brp.cert_health',
			json_encode($status, JSON_THROW_ON_ERROR)
		);
	}//end saveStatus()

	/**
	 * Send an admin Nextcloud notification.
	 *
	 * @param int $daysLeft Days remaining.
	 * @param string $status warning|critical.
	 * @param DateTimeImmutable $expiry Expiry date.
	 *
	 * @return void
	 */
	private function notifyAdmins(int $daysLeft, string $status, DateTimeImmutable $expiry): void {
		$admins = $this->groupManager->get('admin');
		if ($admins === null) {
			return;
		}

		foreach ($admins->getUsers() as $admin) {
			try {
				$n = $this->notificationManager->createNotification();
				$n->setApp(AppIdentity::APP_ID)
					->setUser($admin->getUID())
					->setObject('brp-cert', $status)
					->setSubject(
						'brp_cert_' . $status,
						[
							'daysLeft' => $daysLeft,
							'expiry' => $expiry->format('Y-m-d'),
						]
					)
					->setDateTime(new DateTime());
				$this->notificationManager->notify($n);
			} catch (Throwable $e) {
				$this->logger->warning('BRP cert notify failed', ['admin' => $admin->getUID(), 'error' => $e->getMessage()]);
			}
		}
	}//end notifyAdmins()

	/**
	 * ISO 8601 UTC now.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
	}//end nowIso()
}//end class
