<?php

/**
 * Pipelinq DetractorFollowUpService.
 *
 * Closes the loop: an unhappy answer becomes somebody's task, with a name on
 * it, rather than a number on a dashboard.
 *
 * THE CLASSIFICATION IS WRITTEN ONTO THE RESPONSE, AND THE NOTIFICATION IS A
 * SCHEMA RULE. The response's `classification` transitions to `detractor` and
 * the x-openregister-notifications rule on the schema fires (ADR-031). No
 * imperative dispatch lives in this class, deliberately: a notification sent
 * from app code is invisible to the engine that is supposed to own them, and
 * cannot be configured or silenced by an administrator.
 *
 * AN OWNERLESS CLIENT STILL REACHES SOMEBODY. The assignee falls back to the
 * configured default, because a follow-up task assigned to nobody is a
 * follow-up that does not happen, and it looks exactly like one that did.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-detractor-closed-loop-follow-up
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Classify a response and raise the follow-up a detractor earns.
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-detractor-closed-loop-follow-up
 */
class DetractorFollowUpService {
	/**
	 * The app-config key holding the rating threshold at or below which a
	 * 1 to 5 answer counts as a detractor.
	 *
	 * @var string
	 */
	public const THRESHOLD_KEY = 'survey_detractor_rating_threshold';

	/**
	 * The app-config key holding the assignee for an ownerless client.
	 *
	 * @var string
	 */
	public const DEFAULT_ASSIGNEE_KEY = 'survey_default_assignee';

	/**
	 * The highest NPS answer that still counts as a detractor, per Bain.
	 *
	 * @var int
	 */
	public const NPS_DETRACTOR_CEILING = 6;

	/**
	 * The lowest NPS answer that counts as a promoter, per Bain.
	 *
	 * @var int
	 */
	public const NPS_PROMOTER_FLOOR = 9;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the threshold and default assignee.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The rating at or below which an answer counts as a detractor.
	 *
	 * @return int The threshold.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-detractor-closed-loop-follow-up
	 */
	public function ratingThreshold(): int {
		$configured = (int)$this->appConfig->getValueString(Application::APP_ID, self::THRESHOLD_KEY, '2');

		if ($configured > 0) {
			return $configured;
		}

		return 2;
	}//end ratingThreshold()

	/**
	 * Which band a response falls in.
	 *
	 * A low rating counts as a detractor even when the NPS answer does not,
	 * because somebody who scored the service 1 out of 5 is unhappy whatever
	 * they said about recommending it.
	 *
	 * @param array<string, mixed> $response The response.
	 *
	 * @return string One of detractor, passive, promoter.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-detractor-closed-loop-follow-up
	 */
	public function classify(array $response): string {
		$nps = ($response['npsScore'] ?? null);
		if (is_numeric($nps) === true && (int)$nps <= self::NPS_DETRACTOR_CEILING) {
			return 'detractor';
		}

		$rating = ($response['averageRating'] ?? null);
		if (is_numeric($rating) === true && (float)$rating > 0.0
			&& (float)$rating <= (float)$this->ratingThreshold()
		) {
			return 'detractor';
		}

		if (is_numeric($nps) === true && (int)$nps >= self::NPS_PROMOTER_FLOOR) {
			return 'promoter';
		}

		return 'passive';
	}//end classify()

	/**
	 * Who owns the follow-up for a response.
	 *
	 * @param array<string, mixed> $client The client the response rolls up to.
	 *
	 * @return string The assignee's user id, or '' when nothing is configured.
	 */
	public function assigneeFor(array $client): string {
		$owner = trim((string)($client['owner'] ?? $client['ownerId'] ?? $client['assignee'] ?? ''));
		if ($owner !== '') {
			return $owner;
		}

		return trim(
			$this->appConfig->getValueString(Application::APP_ID, self::DEFAULT_ASSIGNEE_KEY, '')
		);
	}//end assigneeFor()

	/**
	 * The follow-up task a detractor response earns, or null for the rest.
	 *
	 * Pure: it decides and returns. The write is separate so the decision can
	 * be tested without a store, and so a promoter producing no task is an
	 * assertion rather than an absence somebody has to notice.
	 *
	 * @param array<string, mixed> $response The response.
	 * @param array<string, mixed> $client The client it rolls up to.
	 * @param DateTimeImmutable|null $now The moment to stamp.
	 *
	 * @return array<string, mixed>|null The task, or null.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-detractor-closed-loop-follow-up
	 */
	public function followUpTaskFor(array $response, array $client, ?DateTimeImmutable $now = null): ?array {
		if ($this->classify(response: $response) !== 'detractor') {
			return null;
		}

		$now = ($now ?? new DateTimeImmutable());

		return [
			'type' => 'followUp',
			'subject' => 'Follow up an unhappy response',
			'description' => trim((string)($response['verbatim'] ?? '')),
			'status' => 'open',
			'priority' => 'high',
			'deadline' => $now->modify('+2 days')->format(DateTimeInterface::ATOM),
			'assigneeUserId' => $this->assigneeFor(client: $client),
			'clientId' => trim((string)($response['clientRef'] ?? '')),
			// The response itself, so somebody picking the task up reads what
			// was actually said rather than a score.
			'surveyResponseId' => trim((string)($response['id'] ?? $response['uuid'] ?? '')),
		];
	}//end followUpTaskFor()

	/**
	 * Classify a response, raise its follow-up, and write both back.
	 *
	 * The classification is written LAST, so the notification rule fires on a
	 * response that already names its assignee and its task. A notification
	 * that arrives before the task exists sends somebody to an empty queue.
	 *
	 * @param array<string, mixed> $response The response.
	 * @param array<string, mixed> $client The client it rolls up to.
	 *
	 * @return array<string, mixed> The response as it was written.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-detractor-closed-loop-follow-up
	 */
	public function process(array $response, array $client): array {
		$classification = $this->classify(response: $response);
		$task = $this->followUpTaskFor(response: $response, client: $client);

		if ($task !== null) {
			$response['followUpAssignee'] = $task['assigneeUserId'];

			$taskId = $this->writeTask(task: $task);
			if ($taskId !== '') {
				$response['followUpTaskRef'] = $taskId;
			}
		}

		$response['classification'] = $classification;
		$this->writeResponse(response: $response);

		return $response;
	}//end process()

	/**
	 * Write the follow-up task.
	 *
	 * @param array<string, mixed> $task The task.
	 *
	 * @return string The task's uuid, or '' when it could not be written.
	 */
	private function writeTask(array $task): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
		if ($register === '' || $schema === '') {
			$this->logger->warning('DetractorFollowUpService: the task surface is not configured');

			return '';
		}

		try {
			$saved = $this->objectService->saveObject(
				object: $task,
				register: $register,
				schema: $schema,
			);

			return (string)($saved->getUuid() ?? '');
		} catch (Throwable $e) {
			$this->logger->error(
				'DetractorFollowUpService: the follow-up task could not be written',
				['exception' => $e->getMessage()]
			);

			return '';
		}
	}//end writeTask()

	/**
	 * Write the classified response back.
	 *
	 * @param array<string, mixed> $response The response.
	 *
	 * @return void
	 */
	private function writeResponse(array $response): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'surveyResponse_schema', '');
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$this->objectService->saveObject(
				object: $response,
				register: $register,
				schema: $schema,
				uuid: (string)($response['id'] ?? $response['uuid'] ?? ''),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'DetractorFollowUpService: the classified response could not be saved',
				['exception' => $e->getMessage()]
			);
		}
	}//end writeResponse()
}//end class
