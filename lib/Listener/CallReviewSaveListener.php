<?php

/**
 * Pipelinq CallReviewSaveListener.
 *
 * Sets a call review's weighted total and, once every coaching point is
 * agreed, its agreed status, on every save from any screen.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\CallReview\CallReviewTotal;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Before a call review is stored, its `total` is computed from its scores and
 * its scorecard, and a shared review whose coaching points are all agreed is
 * stored as agreed. The client never sets either (design D3).
 *
 * @template-implements IEventListener<Event>
 */
class CallReviewSaveListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, to read the scorecard.
	 * @param IAppConfig             $appConfig     The app configuration.
	 * @param CallReviewTotal        $rules         The total and status rules.
	 * @param LoggerInterface        $logger        The logger.
	 *
	 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-a-reviewer-scores-a-call-and-writes-coaching-points-req-crv-002
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly CallReviewTotal $rules,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Set the total and the status on a call review about to be stored.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-a-reviewer-scores-a-call-and-writes-coaching-points-req-crv-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->apply(event: $event, entity: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->apply(event: $event, entity: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Set the total and the status when the object is a call review.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The event.
	 * @param ObjectEntityInterface                   $entity The object about to be stored.
	 *
	 * @return void
	 */
	private function apply(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntityInterface $entity): void {
		if ($this->isCallReview(schemaId: (string)$entity->getSchema()) === false) {
			return;
		}

		$review = array_merge((array)$entity->getObject(), (array)$event->getModifiedData());
		$changes = [
			'total'  => $this->rules->totalOf(
				criteria: $this->criteriaOf(scorecardId: (string)($review['scorecard'] ?? '')),
				scores: (array)($review['scores'] ?? [])
			),
			'status' => $this->rules->statusOf(
				status: (string)($review['status'] ?? 'draft'),
				coachingPoints: (array)($review['coachingPoints'] ?? [])
			),
		];
		$event->setModifiedData(array_merge((array)$event->getModifiedData(), $changes));
	}//end apply()


	/**
	 * Whether a schema id is the configured call review schema.
	 *
	 * @param string $schemaId The object's schema id.
	 *
	 * @return bool
	 */
	private function isCallReview(string $schemaId): bool {
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'callReview_schema', '');

		return $schema !== '' && $schemaId === $schema;
	}//end isCallReview()

	/**
	 * The criteria of a scorecard, or none when it cannot be read.
	 *
	 * @param string $scorecardId The scorecard's id.
	 *
	 * @return array<int, mixed>
	 */
	private function criteriaOf(string $scorecardId): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'callScorecard_schema', '');
		if ($scorecardId === '' || $register === '' || $schema === '') {
			return [];
		}

		try {
			$scorecard = $this->objectService->find(id: $scorecardId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: the scorecard of a call review could not be read', ['exception' => $e->getMessage()]);
			return [];
		}

		return (array)(($scorecard?->getObject() ?? [])['criteria'] ?? []);
	}//end criteriaOf()
}//end class
