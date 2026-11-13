<?php

/**
 * Pipelinq CallTranscriptionJob.
 *
 * Transcribes one recorded call, queued when its recording is attached.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\CallReview\CallTranscriptionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A queued job, so a long call's transcription never holds up the webhook
 * that attached its recording (design D1).
 *
 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.3
 */
class CallTranscriptionJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time          Time factory.
	 * @param CallTranscriptionService $transcription Transcribes the call.
	 * @param LoggerInterface          $logger        PSR logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly CallTranscriptionService $transcription,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Transcribe the ticket the argument names.
	 *
	 * @param mixed $argument `['ticketId' => <uuid>]`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.3
	 */
	protected function run($argument): void {
		$ticketId = '';
		if (is_array($argument) === true) {
			$ticketId = (string) ($argument['ticketId'] ?? '');
		}

		if ($ticketId === '') {
			return;
		}

		try {
			$status = $this->transcription->transcribe(ticketId: $ticketId);
		} catch (Throwable $e) {
			$this->logger->error('CallTranscriptionJob: transcription failed', ['ticket' => $ticketId, 'exception' => $e->getMessage()]);
			return;
		}

		$this->logger->info('CallTranscriptionJob: call transcribed', ['ticket' => $ticketId, 'status' => $status]);
	}//end run()
}//end class
