<?php

/**
 * Pipelinq TicketReferenceCreatingListener.
 *
 * Gives every new request and complaint its reference number ("PQ-2026-0402")
 * before it is stored. The ticket form, website forms, the portal, the API
 * and imports all create tickets; OpenRegister's pre-save event covers them
 * in one place. A ticket that arrives with a reference keeps it.
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
 * @spec openspec/changes/ticket-leaf-closed-reference-assignee/specs/ticket-leaf/spec.md#requirement-a-ticket-carries-a-reference-number
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\TicketLeafProviderInterface;
use OCA\Pipelinq\Service\TicketReferenceSequence;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps the reference number on a ticket that is being created.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/ticket-leaf-closed-reference-assignee/specs/ticket-leaf/spec.md#requirement-a-ticket-carries-a-reference-number
 */
class TicketReferenceCreatingListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig              $appConfig App config, source of the ticket schema id.
	 * @param TicketReferenceSequence $sequence  Allocates the number.
	 * @param LoggerInterface         $logger    PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly TicketReferenceSequence $sequence,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object-creating event.
	 *
	 * Only requests and complaints of the ticket schema without a reference are
	 * touched. A number that cannot be allocated never blocks the save: the
	 * ticket is stored without one.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ticket-leaf-closed-reference-assignee/specs/ticket-leaf/spec.md#requirement-a-ticket-carries-a-reference-number
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false) {
			return;
		}

		$entity = $this->ticketEntity(event: $event);
		if ($entity === null) {
			return;
		}

		$ticket = array_merge($entity->getObject(), $event->getModifiedData());
		if ($this->needsReference(ticket: $ticket) === false) {
			return;
		}

		$reference = $this->nextReference();
		if ($reference === null) {
			return;
		}

		// Merge, never replace: another listener may already have modified the data.
		$event->setModifiedData(array_merge($event->getModifiedData(), ['reference' => $reference]));
	}//end handle()

	/**
	 * The object being created when it is a ticket of the configured ticket schema, else null.
	 *
	 * @param ObjectCreatingEvent $event The event.
	 *
	 * @return mixed The ticket entity, or null.
	 */
	private function ticketEntity(ObjectCreatingEvent $event) {
		$ticketSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'ticket_schema', '');
		$entity = $event->getObject();
		if ($ticketSchema === '' || (string)$entity->getSchema() !== $ticketSchema) {
			return null;
		}

		return $entity;
	}//end ticketEntity()

	/**
	 * Whether the ticket is of a numbered type and has no reference yet.
	 *
	 * @param array<string, mixed> $ticket The ticket data.
	 *
	 * @return bool True when a reference must be drawn.
	 */
	private function needsReference(array $ticket): bool {
		return (in_array((string)($ticket['ticketType'] ?? ''), TicketLeafProviderInterface::TICKET_TYPES, true) === false
			|| trim((string)($ticket['reference'] ?? '')) !== '') === false;
	}//end needsReference()

	/**
	 * Draw the next reference number; null (logged) when the sequence fails.
	 *
	 * @return mixed The reference, or null.
	 */
	private function nextReference() {
		try {
			return $this->sequence->next();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: no reference number for a new ticket',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end nextReference()
}//end class
