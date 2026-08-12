<?php

/**
 * Pipelinq FallbackEmailJob.
 *
 * Daily job (REQ-FALLBACK-004) that scans for BerichtenboxMessage rows
 * sent ≥5 Dutch working days ago whose readAt is unset, and ships them
 * as fallback email via EmailFallbackSender. We use TimedJob with a
 * 24-hour interval rather than a cron schedule so the job survives
 * environments without an external cron source — the scheduler we
 * ride on is NC's built-in. Tenants who want a strict 06:00 UTC run
 * can wire an external cron to the occ trigger.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-fallback-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\BerichtenboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily 5-working-day fallback email job.
 */
class FallbackEmailJob extends TimedJob {
	/**
	 * Job interval in seconds (24 hours).
	 *
	 * @var int
	 */
	public const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param ContainerInterface $container DI container.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Run the daily fallback pass.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
	 */
	protected function run(mixed $argument): void {
		try {
			$service = $this->container->get(BerichtenboxService::class);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'FallbackEmailJob: BerichtenboxService unavailable.',
				['exception' => $e->getMessage()]
			);
			return;
		}

		try {
			$sent = $service->processFallbackQueue();
			$this->logger->info(
				'FallbackEmailJob: processed fallbacks.',
				['sent' => $sent]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'FallbackEmailJob run failed.',
				['exception' => $e->getMessage()]
			);
		}
	}//end run()
}//end class
