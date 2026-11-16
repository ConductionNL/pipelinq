<?php

/**
 * Pipelinq BerichtenboxJobPass.
 *
 * One fail-soft pass of a Berichtenbox timed job: resolve the
 * BerichtenboxService lazily, run the job's work on it, and log instead of
 * throwing when either step fails, so the background-job runner never sees
 * an exception.
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
 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 * @spec openspec/specs/berichtenbox/spec.md#REQ-FALLBACK-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Kernel\Contracts\BerichtenboxServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs one Berichtenbox job's work on the lazily resolved service.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
 */
final class BerichtenboxJobPass {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy resolves the BerichtenboxService).
	 * @param LoggerInterface    $logger    Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the service and hand it to $work. An unavailable service is a
	 * warning and a failing $work an error, both logged under the job's name.
	 *
	 * @param string                               $job  The job name the log lines start with.
	 * @param callable(BerichtenboxServiceInterface): void $work The job's work, including its own success log.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function run(string $job, callable $work): void {
		try {
			$service = $this->container->get(BerichtenboxServiceInterface::class);
		} catch (\Throwable $e) {
			$this->logger->warning(
				$job.': BerichtenboxService unavailable.',
				['exception' => $e->getMessage()]
			);
			return;
		}

		try {
			$work($service);
		} catch (\Throwable $e) {
			$this->logger->error(
				$job.' run failed.',
				['exception' => $e->getMessage()]
			);
		}
	}//end run()
}//end class
