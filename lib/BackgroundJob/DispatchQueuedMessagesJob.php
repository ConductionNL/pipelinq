<?php

/**
 * Pipelinq DispatchQueuedMessagesJob.
 *
 * Five-minute timed job that drains the queued BerichtenboxMessages
 * (REQ-OUTBOUND-001 + REQ-RETRY-012). Per-row failures are caught
 * inside BerichtenboxService::dispatchQueuedMessages() so a single
 * bad row never blocks the run.
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-outbound-001
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-retry-012
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\BerichtenboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * 5-minute dispatcher for queued Berichtenbox messages.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
 */
class DispatchQueuedMessagesJob extends TimedJob {
	/**
	 * Job interval in seconds (5 minutes).
	 *
	 * @var int
	 */
	public const INTERVAL_SECONDS = 300;

	/**
	 * Maximum rows per run.
	 *
	 * @var int
	 */
	public const BATCH_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param ContainerInterface $container DI container (lazy resolves the
	 *                                      BerichtenboxService).
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
	 * Run the dispatch cycle.
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
				'DispatchQueuedMessagesJob: BerichtenboxService unavailable.',
				['exception' => $e->getMessage()]
			);
			return;
		}

		try {
			$count = $service->dispatchQueuedMessages(self::BATCH_LIMIT);
			$this->logger->info(
				'DispatchQueuedMessagesJob processed messages.',
				['count' => $count]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'DispatchQueuedMessagesJob run failed.',
				['exception' => $e->getMessage()]
			);
		}
	}//end run()
}//end class
