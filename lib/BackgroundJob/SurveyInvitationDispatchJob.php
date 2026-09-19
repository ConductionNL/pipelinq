<?php

/**
 * Pipelinq SurveyInvitationDispatchJob.
 *
 * Sends the invitations whose delay has elapsed.
 *
 * The delay exists because asking somebody how a call went while they are
 * still putting the phone down reads as an insult, so the decision to invite
 * and the act of inviting are separated: the dispatch service decides at the
 * moment the work completes, and this job sends when the wait is over.
 *
 * A hand-off that fails marks the invitation `failed` and moves on. It never
 * retries forever and never touches the interaction that produced it.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\Service\SurveyDispatchService;
use OCA\Pipelinq\Service\SurveyInvitationSender;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Send scheduled invitations whose delay has elapsed, and expire stale ones.
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
 */
class SurveyInvitationDispatchJob extends TimedJob {
	/**
	 * How often the job runs, in seconds.
	 *
	 * @var int
	 */
	private const INTERVAL = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param SurveyDispatchService $dispatchService Reads and writes invitations.
	 * @param SurveyInvitationSender $sender Hands an invitation to its channel.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SurveyDispatchService $dispatchService,
		private readonly SurveyInvitationSender $sender,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * The invitations this run should act on, and what it should do to each.
	 *
	 * Pure, so the whole decision is testable without a store or a mail
	 * transport: an invitation whose delay has not elapsed is left alone, one
	 * past its expiry is expired rather than sent, and the rest are sent.
	 *
	 * @param array<int, array<string, mixed>> $invitations The scheduled invitations.
	 * @param DateTimeImmutable $now The moment to judge at.
	 *
	 * @return array<int, array{invitation: array<string, mixed>, act: string}> The plan.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	public function plan(array $invitations, DateTimeImmutable $now): array {
		$plan = [];

		foreach ($invitations as $invitation) {
			if ((string)($invitation['status'] ?? '') !== 'scheduled') {
				continue;
			}

			if ($this->passed(value: ($invitation['expiresAt'] ?? ''), now: $now) === true) {
				$plan[] = ['invitation' => $invitation, 'act' => 'expire'];
				continue;
			}

			// An absent scheduledFor means due now: a rule with no delay
			// writes none, and an invitation nobody scheduled must not sit
			// unsent forever waiting for a date that will never arrive.
			$scheduledFor = trim((string)($invitation['scheduledFor'] ?? ''));
			if ($scheduledFor !== '' && $this->passed(value: $scheduledFor, now: $now) === false) {
				continue;
			}

			$plan[] = ['invitation' => $invitation, 'act' => 'send'];
		}

		return $plan;
	}//end plan()

	/**
	 * Run the job.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
	 */
	protected function run($argument): void {
		$now = new DateTimeImmutable();
		$scheduled = $this->dispatchService->read(filters: ['status' => 'scheduled']);

		foreach ($this->plan(invitations: $scheduled, now: $now) as $step) {
			$invitation = $step['invitation'];
			$uuid = (string)($invitation['id'] ?? $invitation['uuid'] ?? '');

			if ($step['act'] === 'expire') {
				$invitation['status'] = 'expired';
				$this->dispatchService->write(invitation: $invitation, uuid: $uuid);
				continue;
			}

			try {
				$sent = $this->sender->send(invitation: $invitation);
			} catch (Throwable $e) {
				$this->logger->error(
					'SurveyInvitationDispatchJob: the channel hand-off threw',
					['uuid' => $uuid, 'exception' => $e->getMessage()]
				);
				$sent = false;
			}

			if ($sent === true) {
				$invitation['status'] = 'sent';
				$invitation['sentAt'] = $now->format(DateTimeInterface::ATOM);
			} else {
				// `failed`, not a silent retry: an invitation that never went
				// out is a fact the response rate has to be able to see.
				$invitation['status'] = 'failed';
			}

			$this->dispatchService->write(invitation: $invitation, uuid: $uuid);
		}
	}//end run()

	/**
	 * Whether a stored instant has passed.
	 *
	 * @param mixed $value The stored instant.
	 * @param DateTimeImmutable $now The moment to judge at.
	 *
	 * @return bool True when the instant is in the past. An unusable or absent
	 *   value counts as passed for `scheduledFor` and as not passed for
	 *   `expiresAt`, which is why callers pass the right one.
	 */
	private function passed(mixed $value, DateTimeImmutable $now): bool {
		$value = trim((string)$value);
		if ($value === '') {
			return false;
		}

		try {
			return (new DateTimeImmutable($value) <= $now);
		} catch (Throwable $e) {
			return false;
		}
	}//end passed()
}//end class
