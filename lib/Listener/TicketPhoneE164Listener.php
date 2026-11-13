<?php

/**
 * Pipelinq TicketPhoneE164Listener.
 *
 * Stores the number a ticket is about in E.164 on every ticket save.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
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
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\TicketPhoneNumber;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sets `phoneE164` on a ticket that is being created or updated.
 *
 * One listener rather than a line in each writer, because tickets are written
 * by the CTI webhook, by the contact moment form through OpenRegister's API and
 * by a website enquiry's binding, and only the save itself sees all three
 * (design D2). A number that does not normalise leaves the field as it is, and
 * a failure never blocks the save.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.2
 */
class TicketPhoneE164Listener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig        $appConfig   App config, source of the ticket schema id.
	 * @param TicketPhoneNumber $phoneNumber Reads and normalises the ticket's number.
	 * @param LoggerInterface   $logger      PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly TicketPhoneNumber $phoneNumber,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object-creating or object-updating event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.2
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$entity = $event->getObject();
		} else if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
		} else {
			return;
		}

		$ticketSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'ticket_schema', '');
		if ($ticketSchema === '' || (string) $entity->getSchema() !== $ticketSchema) {
			return;
		}

		$ticket = array_merge($entity->getObject(), $event->getModifiedData());

		try {
			$e164 = $this->phoneNumber->e164(ticket: $ticket);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: the phone number of a ticket could not be normalised',
				['exception' => $e->getMessage()]
			);
			return;
		}

		if ($e164 === null || ($ticket[TicketPhoneNumber::FIELD] ?? null) === $e164) {
			return;
		}

		// Merge, never replace: another listener may already have modified the data.
		$event->setModifiedData(array_merge($event->getModifiedData(), [TicketPhoneNumber::FIELD => $e164]));
	}//end handle()
}//end class
