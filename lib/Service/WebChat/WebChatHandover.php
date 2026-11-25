<?php

/**
 * A website chat handed to a person: one request ticket in the Queue, and what follows on it.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\WebChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebChat;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCA\Pipelinq\Service\InboundTicketService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Makes a chat a ticket through InboundTicketService (D5), so the ticket is in
 * the Queue at once and the chatWaiting rule notifies the team, and keeps that
 * ticket in step with the chat.
 *
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-the-chat-goes-to-a-colleague-through-the-queue-req-wch-004
 */
class WebChatHandover {

	/**
	 * Constructor.
	 *
	 * @param WebChatMessages        $messages The chat's messages and times.
	 * @param InboundTicketService   $inbound  The intake that makes a handed-over chat a ticket.
	 * @param TicketServiceInterface $tickets  The ticket a colleague takes.
	 * @param LoggerInterface        $logger   Where a failed ticket write is logged.
	 */
	public function __construct(
		private readonly WebChatMessages $messages,
		private readonly InboundTicketService $inbound,
		private readonly TicketServiceInterface $tickets,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Hand the chat to a person: one request ticket with the transcript, in the Queue (D5).
	 *
	 * A chat that already has a ticket gets no second one.
	 *
	 * @param array<string, mixed> $conversation   The conversation.
	 * @param string               $conversationId Its id.
	 * @param string               $email          An address to be answered at, '' for none.
	 *
	 * @return array<string, mixed> The conversation with its ticket.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.1
	 */
	public function handOver(array $conversation, string $conversationId, string $email): array {
		if ((string)($conversation['ticket'] ?? '') !== '') {
			return $conversation;
		}

		$messages = $this->messages->of(conversationId: $conversationId);
		$message  = [
			'channel'   => 'chat',
			'threadKey' => 'conversation:' . $conversationId,
			'title'     => $this->messages->firstQuestion(messages: $messages),
			'text'      => $this->messages->transcript(messages: $messages),
		];
		if ($email !== '') {
			$message['fromAddress'] = $email;
		}

		$ticket = $this->inbound->receive(message: $message);
		$conversation['ticket']       = $ticket['ticket'];
		$conversation['handedOverAt'] = $this->messages->now();
		return $conversation;
	}//end handOver()

	/**
	 * A visitor's message on a chat that is already a ticket: it is added to the ticket.
	 *
	 * @param string $conversationId The chat.
	 * @param string $ticketId       Its ticket.
	 * @param string $text           The message, '' for none.
	 * @param string $email          An address to be answered at, '' for none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.1
	 */
	public function followUp(string $conversationId, string $ticketId, string $text, string $email): void {
		$lines = array_values(array_filter([$text, $this->answerAtLine(email: $email)], static fn (string $line): bool => $line !== ''));
		if ($lines === []) {
			return;
		}

		$message = ['channel' => 'chat', 'threadKey' => 'conversation:' . $conversationId, 'text' => implode("\n", $lines)];
		if ($email === '') {
			$this->inbound->receive(message: $message);
			return;
		}

		$message['fromAddress'] = $email;
		$this->inbound->receive(message: $message);
		$this->updateTicket(ticketId: $ticketId, rbac: false, change: static function (array $ticket) use ($email): array {
			$metadata = [];
			if (is_array($ticket['channelMetadata'] ?? null) === true) {
				$metadata = $ticket['channelMetadata'];
			}

			$metadata['fromAddress']   = $email;
			$ticket['channelMetadata'] = $metadata;
			return $ticket;
		});
	}//end followUp()

	/**
	 * The colleague takes the chat's ticket: it is theirs and leaves the Queue.
	 *
	 * @param string $ticketId The ticket.
	 * @param string $userId   The colleague.
	 *
	 * @return void
	 *
	 * @throws WebChatRefusal When the ticket cannot be read or written.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public function assignTicket(string $ticketId, string $userId): void {
		$this->updateTicket(ticketId: $ticketId, rbac: true, change: static function (array $ticket) use ($userId): array {
			$ticket['assignee'] = $userId;
			$ticket['status']   = 'in_progress';
			return $ticket;
		});
	}//end assignTicket()

	/**
	 * The line that tells the ticket where to answer, or '' without an address.
	 *
	 * @param string $email An address to be answered at, '' for none.
	 *
	 * @return string
	 */
	private function answerAtLine(string $email): string {
		if ($email === '') {
			return '';
		}

		return 'Email address to answer at: ' . $email;
	}//end answerAtLine()

	/**
	 * Change a chat's ticket.
	 *
	 * @param string                                               $ticketId The ticket.
	 * @param bool                                                 $rbac     Whether the read goes through OpenRegister's checks.
	 * @param callable(array<string, mixed>): array<string, mixed> $change   The change.
	 *
	 * @return void
	 *
	 * @throws WebChatRefusal When the ticket cannot be read or written.
	 */
	private function updateTicket(string $ticketId, bool $rbac, callable $change): void {
		try {
			$found = $this->tickets->getObjectService()->find(
				id: $ticketId,
				options: new ObjectFindOptions(register: $this->tickets->getRegisterId(), schema: $this->tickets->getSchemaId()),
				_rbac: $rbac,
				_multitenancy: $rbac
			);
			if ($found === null) {
				throw new WebChatRefusal(message: 'The ticket of this chat is not there.', status: 404);
			}

			$ticket = $change($found->getObject());
			unset($ticket['@self'], $ticket['id'], $ticket['uuid']);
			$this->tickets->save(ticketType: (string)($ticket['ticketType'] ?? 'request'), payload: $ticket, uuid: $ticketId);
		} catch (WebChatRefusal $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->error('Pipelinq web chat: the ticket of a chat could not be changed', ['ticket' => $ticketId, 'exception' => $e->getMessage()]);
			throw new WebChatRefusal(message: 'The ticket of this chat could not be changed.', status: 503);
		}
	}//end updateTicket()
}//end class
