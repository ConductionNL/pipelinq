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
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Integration\TicketLeafProvider;
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

		$ticketSchema = $this->appConfig->getValueString(Application::APP_ID, 'ticket_schema', '');
		$entity = $event->getObject();
		if ($ticketSchema === '' || (string)$entity->getSchema() !== $ticketSchema) {
			return;
		}

		$ticket = array_merge($entity->getObject(), $event->getModifiedData());
		if (in_array((string)($ticket['ticketType'] ?? ''), TicketLeafProvider::TICKET_TYPES, true) === false
			|| trim((string)($ticket['reference'] ?? '')) !== ''
		) {
			return;
		}

		try {
			$reference = $this->sequence->next();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: no reference number for a new ticket',
				['exception' => $e->getMessage()]
			);
			return;
		}

		// Merge, never replace: another listener may already have modified the data.
		$event->setModifiedData(array_merge($event->getModifiedData(), ['reference' => $reference]));
	}//end handle()
}//end class
