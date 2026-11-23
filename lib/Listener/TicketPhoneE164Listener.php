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
use OCA\Pipelinq\Kernel\Contracts\TicketPhoneNumberInterface;
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
	 * @param TicketPhoneNumberInterface $phoneNumber Reads and normalises the ticket's number.
	 * @param LoggerInterface   $logger      PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly TicketPhoneNumberInterface $phoneNumber,
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
			$this->normaliseTicketPhone(event: $event, entity: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->normaliseTicketPhone(event: $event, entity: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Store the ticket's phone number in E.164 on the event when it changed.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The creating or updating event.
	 * @param mixed $entity The object being created, or the new object being updated.
	 *
	 * @return void
	 */
	private function normaliseTicketPhone(ObjectCreatingEvent|ObjectUpdatingEvent $event, $entity): void {
		if ($this->isTicket(entity: $entity) === false) {
			return;
		}

		$ticket = array_merge($entity->getObject(), $event->getModifiedData());

		$e164 = $this->changedPhone(ticket: $ticket);
		if ($e164 === null) {
			return;
		}

		// Merge, never replace: another listener may already have modified the data.
		$event->setModifiedData(array_merge($event->getModifiedData(), [TicketPhoneNumberInterface::FIELD => $e164]));
	}//end normaliseTicketPhone()

	/**
	 * Whether the entity is a ticket of the configured ticket schema.
	 *
	 * @param mixed $entity The entity.
	 *
	 * @return bool True for a ticket.
	 */
	private function isTicket($entity): bool {
		$ticketSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'ticket_schema', '');
		return ($ticketSchema === '' || (string) $entity->getSchema() !== $ticketSchema) === false;
	}//end isTicket()

	/**
	 * The ticket's phone number in E.164 when it differs from the stored one; null otherwise (logged when it fails).
	 *
	 * @param array<string, mixed> $ticket The ticket data.
	 *
	 * @return string|null The new E.164 number.
	 */
	private function changedPhone(array $ticket): string|null {
		try {
			$e164 = $this->phoneNumber->e164(ticket: $ticket);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: the phone number of a ticket could not be normalised',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		if ($e164 === null || ($ticket[TicketPhoneNumberInterface::FIELD] ?? null) === $e164) {
			return null;
		}

		return $e164;
	}//end changedPhone()
}//end class
