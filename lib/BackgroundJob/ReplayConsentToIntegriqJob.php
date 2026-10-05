<?php

/**
 * Pipelinq ReplayConsentToIntegriqJob.
 *
 * After the cutover, a wish integriq did not take is written to pipelinq's
 * own store so no STOP is lost. This job hands those records to integriq when
 * it answers again. Each carries its legacy ref, so a replay writes once.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\ConsentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every ten minutes, replay fallback consent records to integriq.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
 */
class ReplayConsentToIntegriqJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory    $time    The clock.
	 * @param ConsentService  $consent Replays the records.
	 * @param LoggerInterface $logger  Logs what was left.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ConsentService $consent,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 600);
	}//end __construct()

	/**
	 * Replay.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
	 */
	protected function run($argument): void {
		unset($argument);
		$counts = $this->consent->replayPending();
		if ($counts['failed'] > 0) {
			$this->logger->warning('ReplayConsentToIntegriqJob: integriq still refuses some consent records', $counts);
		}
	}//end run()
}//end class
