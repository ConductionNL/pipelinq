<?php

/**
 * Pipelinq RemoveBrpMonitorState.
 *
 * Upgrade repair step for brp-monitor-moves-to-integriq: the BRP monitor moved
 * to integriq, so pipelinq's two monitor jobs are unregistered from oc_jobs
 * (removing a `<job>` entry from info.xml leaves its row behind) and the two
 * reports they kept in app config are cleared. Idempotent.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-pipelinq-does-not-monitor-the-brp-connection
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Unregister the BRP monitor jobs and clear their stored reports.
 *
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-pipelinq-does-not-monitor-the-brp-connection
 */
class RemoveBrpMonitorState implements IRepairStep {
	/**
	 * Fully-qualified class names of the removed monitor jobs.
	 *
	 * @var array<int, string>
	 */
	public const RETIRED_JOBS = [
		'OCA\\Pipelinq\\BackgroundJob\\BrpHealthCheckJob',
		'OCA\\Pipelinq\\BackgroundJob\\BrpMonitorJob',
	];

	/**
	 * The app-config keys the monitor jobs wrote.
	 *
	 * @var array<int, string>
	 */
	public const RETIRED_KEYS = [
		'brp.monitor_report',
		'brp.cert_health',
	];

	/**
	 * Constructor.
	 *
	 * @param IJobList        $jobList   The Nextcloud background-job list.
	 * @param IAppConfig      $appConfig The app config holding the reports.
	 * @param LoggerInterface $logger    The logger.
	 */
	public function __construct(
		private readonly IJobList $jobList,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The repair step name.
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-pipelinq-does-not-monitor-the-brp-connection
	 */
	public function getName(): string {
		return 'Remove the BRP monitor jobs and reports (the monitor moved to integriq)';
	}//end getName()

	/**
	 * Run the repair step: unregister each job, then clear each report.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-pipelinq-does-not-monitor-the-brp-connection
	 */
	public function run(IOutput $output): void {
		foreach (self::RETIRED_JOBS as $jobClass) {
			try {
				// The job classes are deleted, so they are passed by bare class name (see phpstan.neon).
				$this->jobList->remove($jobClass);
				$output->info('Unregistered BRP monitor job: ' . $jobClass);
			} catch (\Throwable $e) {
				$this->warn(output: $output, subject: $jobClass, error: $e);
			}
		}

		foreach (self::RETIRED_KEYS as $key) {
			try {
				$this->appConfig->deleteKey(AppIdentity::APP_ID, $key);
				$output->info('Cleared BRP monitor report: ' . $key);
			} catch (\Throwable $e) {
				$this->warn(output: $output, subject: $key, error: $e);
			}
		}
	}//end run()

	/**
	 * Report a part that could not be removed; the others still run.
	 *
	 * @param IOutput    $output  The output interface.
	 * @param string     $subject The job class or config key.
	 * @param \Throwable $error   What went wrong.
	 *
	 * @return void
	 */
	private function warn(IOutput $output, string $subject, \Throwable $error): void {
		$output->warning('Failed to remove ' . $subject . ': ' . $error->getMessage());
		$this->logger->warning(
			'Pipelinq: failed to remove BRP monitor state',
			['subject' => $subject, 'exception' => $error->getMessage()]
		);
	}//end warn()
}//end class
