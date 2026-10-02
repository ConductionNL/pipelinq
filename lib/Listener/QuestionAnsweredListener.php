<?php

/**
 * Pipelinq Question Answered Listener
 *
 * Hears OpenRegister's update of a ticket and, when the save answered a
 * resident's portal question, has QuestionAnsweredNotice tell them. It runs
 * inside somebody else's save, so it never lets an exception out: a missed
 * notice is a nuisance, a failed save is a lost answer.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-questions-in-dutch/specs/dossier-questions/spec.md#requirement-the-answer-notice-says-the-question-was-answered
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Service\Portal\QuestionAnsweredNotice;
use OCA\Pipelinq\Service\SchemaMapService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells a resident their portal question was answered.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/portal-questions-in-dutch/specs/dossier-questions/spec.md#requirement-the-answer-notice-says-the-question-was-answered
 */
class QuestionAnsweredListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapService       $schemaMap Tells a ticket from any other object.
	 * @param QuestionAnsweredNotice $notice    Writes the resident's message.
	 * @param LoggerInterface        $logger    Records a notice that failed.
	 */
	public function __construct(
		private readonly SchemaMapService $schemaMap,
		private readonly QuestionAnsweredNotice $notice,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an update: tell the resident when it answered their question.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-questions-in-dutch/specs/dossier-questions/spec.md#requirement-the-answer-notice-says-the-question-was-answered
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		try {
			$old = $event->getOldObject();
			$new = $event->getNewObject();
			if ($old === null || $this->schemaMap->resolveEntityType(schemaId: (string)$new->getSchema()) !== 'ticket') {
				return;
			}

			$after = $new->getObject();
			if ($this->notice->isAnswered(before: $old->getObject(), after: $after) === true) {
				$this->notice->tell(ticket: $after, ticketId: (string)$new->getUuid());
			}
		} catch (Throwable $e) {
			$this->logger->warning('QuestionAnsweredListener: the answer notice was skipped', ['error' => $e->getMessage()]);
		}
	}//end handle()
}//end class
