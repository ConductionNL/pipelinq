<?php

/**
 * Pipelinq ExportFailureSwitch.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://pipelinq.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-an-export-job-switches-off-after-repeated-failed-runs-req-efs-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\NotificationService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Counts an export job's failed runs in a row and switches the job off at the
 * threshold, telling the administrators once.
 *
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-an-export-job-switches-off-after-repeated-failed-runs-req-efs-001
 */
class ExportFailureSwitch {
	/**
	 * The app config key holding the threshold.
	 *
	 * @var string
	 */
	public const THRESHOLD_KEY = 'export_failure_threshold';

	/**
	 * The threshold when none is set.
	 *
	 * @var int
	 */
	public const THRESHOLD_DEFAULT = 3;

	/**
	 * The lowest threshold an administrator may set.
	 *
	 * @var int
	 */
	public const THRESHOLD_MIN = 1;

	/**
	 * The highest threshold an administrator may set.
	 *
	 * @var int
	 */
	public const THRESHOLD_MAX = 10;

	/**
	 * The notification subject sent when a job is switched off.
	 *
	 * @var string
	 */
	public const SUBJECT = 'export_job_switched_off';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param IGroupManager $groupManager The group manager (the admin group).
	 * @param NotificationService $notifier The notification sender.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private NotificationService $notifier,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The number of failed runs in a row after which a job is switched off,
	 * kept within 1 to 10.
	 *
	 * @return int The threshold.
	 *
	 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-an-administrator-sets-the-threshold-req-efs-004
	 */
	public function threshold(): int {
		$value = $this->appConfig->getValueString(
			Application::APP_ID,
			self::THRESHOLD_KEY,
			(string)self::THRESHOLD_DEFAULT
		);
		if (self::isValidThreshold(value: $value) === false) {
			return self::THRESHOLD_DEFAULT;
		}

		return (int)$value;
	}//end threshold()

	/**
	 * Whether a value is a whole number from 1 to 10.
	 *
	 * @param mixed $value The value as entered.
	 *
	 * @return bool True when it is a valid threshold.
	 *
	 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-an-administrator-sets-the-threshold-req-efs-004
	 */
	public static function isValidThreshold(mixed $value): bool {
		$text = trim((string)$value);
		if (preg_match('/^\d{1,2}$/', $text) !== 1) {
			return false;
		}

		$number = (int)$text;

		return $number >= self::THRESHOLD_MIN && $number <= self::THRESHOLD_MAX;
	}//end isValidThreshold()

	/**
	 * Count a finished run on its job: a succeeded run resets the count, a
	 * failed run adds one, a partial run leaves it. An enabled job whose count
	 * reaches the threshold is switched off, with the reason and the moment.
	 *
	 * @param array<string, mixed> $job The job.
	 * @param string $status The run's final status.
	 *
	 * @return array<string, mixed> The job with its count and, when switched off, its off state.
	 *
	 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-an-export-job-switches-off-after-repeated-failed-runs-req-efs-001
	 */
	public function countRun(array $job, string $status): array {
		$failures = max(0, (int)($job['consecutiveFailures'] ?? 0));
		if ($status === 'succeeded') {
			$failures = 0;
		} elseif ($status === 'failed') {
			$failures++;
		}

		$job['consecutiveFailures'] = $failures;

		$threshold = $this->threshold();
		if (($job['enabled'] ?? false) === true && $failures >= $threshold) {
			$job['enabled']      = false;
			$job['nextRunAt']    = null;
			$job['pausedReason'] = 'Switched off after 1 failed run';
			if ($failures > 1) {
				$job['pausedReason'] = sprintf('Switched off after %d failed runs in a row', $failures);
			}

			$job['pausedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		}

		return $job;
	}//end countRun()

	/**
	 * Whether counting a run switched the job off.
	 *
	 * @param array<string, mixed> $before The job before the run was counted.
	 * @param array<string, mixed> $after The job after the run was counted.
	 *
	 * @return bool True when the job was on and is now off.
	 *
	 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-administrators-are-told-once-when-a-job-is-switched-off-req-efs-002
	 */
	public function switchedOff(array $before, array $after): bool {
		return ($before['enabled'] ?? false) === true && ($after['enabled'] ?? false) === false;
	}//end switchedOff()

	/**
	 * Clear the off state of a job switched back on: the count starts over and
	 * the reason goes.
	 *
	 * @param array<string, mixed> $job The job.
	 *
	 * @return array<string, mixed> The job without its off state.
	 *
	 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-the-bi-export-page-shows-a-switched-off-job-and-lets-an-administrator-switch-it-back-on-req-efs-003
	 */
	public function clear(array $job): array {
		$job['consecutiveFailures'] = 0;
		$job['pausedReason']        = null;
		$job['pausedAt']            = null;

		return $job;
	}//end clear()

	/**
	 * Tell every Nextcloud administrator that a job was switched off: its
	 * name, the failed runs in a row and the last error. A failure to notify
	 * is logged and never fails the run.
	 *
	 * @param array<string, mixed> $job The switched-off job.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-administrators-are-told-once-when-a-job-is-switched-off-req-efs-002
	 */
	public function notifyAdmins(array $job): void {
		$parameters = [
			'name'     => (string)($job['name'] ?? ''),
			'failures' => (int)($job['consecutiveFailures'] ?? 0),
			'error'    => (string)($job['lastRunError'] ?? ''),
		];

		try {
			$admins = ($this->groupManager->get('admin')?->getUsers() ?? []);
			foreach ($admins as $admin) {
				$this->notifier->sendNotification(
					userId: $admin->getUID(),
					subject: self::SUBJECT,
					parameters: $parameters,
					objectType: 'exportJob',
					objectId: (string)($job['id'] ?? $job['uuid'] ?? '')
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: could not tell the administrators an export job was switched off',
				['job' => $parameters['name'], 'error' => $e->getMessage()]
			);
		}
	}//end notifyAdmins()
}//end class
