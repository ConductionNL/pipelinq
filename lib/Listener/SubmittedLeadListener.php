<?php

/**
 * Gives a lead a form submitted its contact, its client and its touchpoint.
 *
 * Decision 179: a form submit creates the lead in the visitor's request, so
 * what used to happen in a relay after the submit happens here, on the
 * lead's own events. Before the lead is stored it gets its contact and
 * client, so it is complete from its first save. Once it is stored, the
 * `submit` touchpoint is appended with the lead's real id.
 *
 * A failure never blocks the lead: the visitor's submission is the thing
 * that must not be lost, and a missing contact can be linked by hand.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\SubmittedLeadIntake;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lead created by a form: people before the save, touchpoint after it.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */
class SubmittedLeadListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig          $appConfig App config, source of the lead schema id.
	 * @param SubmittedLeadIntake $intake    Matches the people and logs the touchpoint.
	 * @param LoggerInterface     $logger    PSR logger.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly SubmittedLeadIntake $intake,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a lead being created, or having been created.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @listener-placement inline correctness — the touchpoint carries the
	 * submission's nonce, and that row is what makes a redelivered submit
	 * recognisable. Written later, a retry inside the gap would get a second
	 * contact match and a second touchpoint. One bounded insert, no outbound call.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$leadSchema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		$entity = $event->getObject();
		if ($leadSchema === '' || (string)$entity->getSchema() !== $leadSchema) {
			return;
		}

		try {
			if ($event instanceof ObjectCreatingEvent) {
				$patch = $this->intake->partiesFor(lead: array_merge($entity->getObject(), $event->getModifiedData()));
				if ($patch !== []) {
					// Merge, never replace: another listener may already have modified the data.
					$event->setModifiedData(array_merge($event->getModifiedData(), $patch));
				}

				return;
			}

			$this->intake->recordTouchpoint(leadId: (string)$entity->getUuid(), lead: $entity->getObject());
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: a submitted lead could not get its contact or touchpoint',
				['lead' => (string)$entity->getUuid(), 'reason' => $e->getMessage()]
			);
		}
	}//end handle()
}//end class
