<?php

/**
 * Pipelinq: a channel adapter opens a ticket for an inbound message.
 *
 * Shared by WhatsAppAdapter and SmsAdapter (requests-inbound-to-queue D3): a
 * known contact's message, once stored, goes onto its conversation's open
 * ticket or opens one in the Queue. The message is stored already, so a
 * failed intake is logged and never fails the webhook.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-inbound-message-becomes-a-ticket-in-the-queue-req-itq-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Throwable;

/**
 * Hands a stored inbound message to the ticket intake. Needs `$this->container` and `$this->logger`.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-inbound-message-becomes-a-ticket-in-the-queue-req-itq-001
 */
trait OpensInboundTickets {

	/**
	 * Put a known contact's message on its conversation's ticket, or open one.
	 *
	 * @param string $channel        The channel (whatsapp, sms).
	 * @param string $conversationId The conversation the message is on; '' for an unknown number.
	 * @param string $contactId      The contact.
	 * @param string $body           The message text.
	 * @param string $from           The sender's number.
	 *
	 * @return string The ticket id, or '' when there is no conversation, no intake, or it failed.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-inbound-message-becomes-a-ticket-in-the-queue-req-itq-001
	 */
	private function openInboundTicket(string $channel, string $conversationId, string $contactId, string $body, string $from): string {
		if ($conversationId === '') {
			return '';
		}

		try {
			$intake = $this->container->get(InboundTicketService::class);
		} catch (Throwable $e) {
			$this->logger->warning('Inbound ' . $channel . ' message: no ticket intake', ['exception' => $e->getMessage()]);
			return '';
		}

		return $intake->tryReceive(
			message: [
				'channel'     => $channel,
				'threadKey'   => 'conversation:' . $conversationId,
				'contact'     => $contactId,
				'text'        => $body,
				'fromAddress' => $from,
			]
		);
	}//end openInboundTicket()
}//end trait
