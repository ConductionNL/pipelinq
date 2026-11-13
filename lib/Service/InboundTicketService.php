<?php

/**
 * Pipelinq inbound ticket intake.
 *
 * One intake for every channel (requests-inbound-to-queue D1): an inbound
 * WhatsApp message, SMS, mail or social message either joins the open ticket
 * of its thread or opens a new request ticket with status new and no
 * assignee. The Queue is the unassigned open tickets, so a new ticket is in
 * the Queue from the moment it is written.
 *
 * The thread is the ticket's `inboundThreadKey` (D2). A follow-up on a ticket
 * that waits for the customer moves it back to in progress; a thread whose
 * ticket is closed opens a new one.
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

use DateTimeInterface;
use InvalidArgumentException;
use OCA\Pipelinq\Util\CastingPayloadToArray;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Turns an inbound message into a ticket, or adds it to its thread's open ticket.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-inbound-message-becomes-a-ticket-in-the-queue-req-itq-001
 */
class InboundTicketService {
	use CastingPayloadToArray {
		toArray as private;
	}

	/**
	 * Statuses in which a ticket is no longer open for its thread.
	 *
	 * @var list<string>
	 */
	public const CLOSED_STATUSES = ['resolved', 'completed', 'rejected', 'converted', 'closed'];

	/**
	 * How many messages a ticket keeps in `channelMetadata.messages`.
	 *
	 * @var int
	 */
	public const KEPT_MESSAGES = 50;

	/**
	 * The longest message text kept on the ticket.
	 *
	 * @var int
	 */
	private const TEXT_LIMIT = 2000;

	/**
	 * The `channelMetadata` keys a message may carry for routing.
	 *
	 * @var list<string>
	 */
	private const METADATA_KEYS = ['fromAddress', 'fromDomain', 'fromName', 'mailbox', 'clientSegment', 'sourceLink'];

	/**
	 * Constructor.
	 *
	 * @param TicketService   $tickets     The ticket register.
	 * @param ITimeFactory    $timeFactory The time a message is recorded at.
	 * @param LoggerInterface $logger      Where a failed intake is logged.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-inbound-message-becomes-a-ticket-in-the-queue-req-itq-001
	 */
	public function __construct(
		private readonly TicketService $tickets,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Receive one inbound message.
	 *
	 * @param array<string, mixed> $message `channel` and `threadKey` (required), `title`, `text`,
	 *                                      `client`, `contact`, and the routing facts
	 *                                      `fromAddress`, `fromDomain`, `fromName`, `mailbox`,
	 *                                      `clientSegment`, `sourceLink`.
	 *
	 * @return array{ticket: string, created: bool, status: string} The ticket the message is on.
	 *
	 * @throws InvalidArgumentException When the channel or the thread key is missing.
	 * @throws RuntimeException When the open ticket cannot be looked up or the ticket cannot be written.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-a-follow-up-stays-on-its-ticket-req-itq-002
	 */
	public function receive(array $message): array {
		$channel   = trim((string) ($message['channel'] ?? ''));
		$threadKey = trim((string) ($message['threadKey'] ?? ''));
		if ($channel === '' || $threadKey === '') {
			throw new InvalidArgumentException('An inbound message needs a channel and a thread key.');
		}

		$entry = $this->entry(message: $message);
		$open  = $this->openTicketFor(threadKey: $threadKey);
		if ($open !== null) {
			return $this->addToTicket(ticket: $open, entry: $entry);
		}

		return $this->openTicket(channel: $channel, threadKey: $threadKey, message: $message, entry: $entry);
	}//end receive()

	/**
	 * Receive a message without ever failing the caller.
	 *
	 * For the channel webhooks (D3): the message is already stored, so a
	 * failed intake is logged and the webhook still answers received, and the
	 * provider does not deliver a message twice.
	 *
	 * @param array<string, mixed> $message The message, as receive() takes it.
	 *
	 * @return string The ticket the message is on, or '' when the intake failed.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-inbound-message-becomes-a-ticket-in-the-queue-req-itq-001
	 */
	public function tryReceive(array $message): string {
		try {
			return $this->receive(message: $message)['ticket'];
		} catch (Throwable $e) {
			$this->logger->error(
				'InboundTicketService: the inbound message was stored but did not reach a ticket',
				['channel' => (string) ($message['channel'] ?? ''), 'threadKey' => (string) ($message['threadKey'] ?? ''), 'exception' => $e->getMessage()]
			);
			return '';
		}
	}//end tryReceive()

	/**
	 * The open ticket of a thread, or null when the thread has none.
	 *
	 * A failed lookup throws rather than answering "none": answering none
	 * would open a second ticket for a thread that already has one. The
	 * lookup reads past RBAC, as the webhook's provider lookup does: the
	 * intake writes as the messaging account, which may create and update
	 * tickets but not list them, and a lookup it could not see through would
	 * open a new ticket for every follow-up. The row stays in-process.
	 *
	 * @param string $threadKey The thread key.
	 *
	 * @return array<string, mixed>|null The ticket.
	 *
	 * @throws RuntimeException When the lookup fails.
	 */
	private function openTicketFor(string $threadKey): ?array {
		try {
			$found = $this->tickets->getObjectService()->findAll(
				[
					'filters' => [
						'register'         => $this->tickets->getRegisterId(),
						'schema'           => $this->tickets->getSchemaId(),
						'inboundThreadKey' => $threadKey,
					],
					'limit'   => 20,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			throw new RuntimeException('The open ticket of thread ' . $threadKey . ' could not be looked up: ' . $e->getMessage(), 0, $e);
		}

		foreach ($found as $object) {
			$ticket = $this->toArray(object: $object);
			if (($ticket['inboundThreadKey'] ?? null) !== $threadKey) {
				continue;
			}

			if (in_array((string) ($ticket['status'] ?? 'new'), self::CLOSED_STATUSES, true) === false) {
				return $ticket;
			}
		}

		return null;
	}//end openTicketFor()

	/**
	 * Add a message to an open ticket; a ticket that waited for the customer goes back to work.
	 *
	 * @param array<string, mixed> $ticket The open ticket.
	 * @param array<string, string> $entry The message entry.
	 *
	 * @return array{ticket: string, created: bool, status: string} The ticket.
	 */
	private function addToTicket(array $ticket, array $entry): array {
		$uuid     = (string) ($ticket['id'] ?? ($ticket['uuid'] ?? ''));
		$metadata = $this->metadataOf(ticket: $ticket);
		$messages = $metadata['messages'] ?? [];
		if (is_array($messages) === false) {
			$messages = [];
		}

		$messages[]              = $entry;
		$metadata['messages']    = array_slice(array_values($messages), -self::KEPT_MESSAGES);
		$metadata['lastMessageAt'] = $entry['at'];
		$ticket['channelMetadata'] = $metadata;
		if (($ticket['status'] ?? null) === 'awaiting_customer') {
			$ticket['status'] = 'in_progress';
		}

		unset($ticket['@self'], $ticket['id'], $ticket['uuid']);
		$this->tickets->save(ticketType: (string) ($ticket['ticketType'] ?? 'request'), payload: $ticket, uuid: $uuid);

		return ['ticket' => $uuid, 'created' => false, 'status' => (string) ($ticket['status'] ?? 'new')];
	}//end addToTicket()

	/**
	 * Open a new request ticket for a thread: status new, no assignee, normal priority.
	 *
	 * @param string               $channel   The channel.
	 * @param string               $threadKey The thread key.
	 * @param array<string, mixed> $message   The message.
	 * @param array<string, string> $entry    The message entry.
	 *
	 * @return array{ticket: string, created: bool, status: string} The ticket.
	 */
	private function openTicket(string $channel, string $threadKey, array $message, array $entry): array {
		$metadata = ['messages' => [$entry], 'lastMessageAt' => $entry['at']];
		foreach (self::METADATA_KEYS as $key) {
			$value = trim((string) ($message[$key] ?? ''));
			if ($value !== '') {
				$metadata[$key] = $value;
			}
		}

		$payload = [
			'title'            => $this->title(message: $message, channel: $channel),
			'description'      => $entry['text'],
			'status'           => 'new',
			'priority'         => 'normal',
			'channel'          => $channel,
			'inboundThreadKey' => $threadKey,
			'channelMetadata'  => $metadata,
		];
		foreach (['client', 'contact'] as $party) {
			$value = trim((string) ($message[$party] ?? ''));
			if ($value !== '') {
				$payload[$party] = $value;
			}
		}

		$saved  = $this->tickets->save(ticketType: 'request', payload: $payload);
		$stored = $this->toArray(object: $saved);

		return ['ticket' => (string) ($stored['id'] ?? ($stored['uuid'] ?? '')), 'created' => true, 'status' => 'new'];
	}//end openTicket()

	/**
	 * The message as it is kept on the ticket.
	 *
	 * @param array<string, mixed> $message The message.
	 *
	 * @return array{at: string, text: string, from: string} The entry.
	 */
	private function entry(array $message): array {
		return [
			'at'   => $this->timeFactory->getDateTime()->format(DateTimeInterface::ATOM),
			'text' => mb_substr(trim((string) ($message['text'] ?? '')), 0, self::TEXT_LIMIT, 'UTF-8'),
			'from' => trim((string) ($message['fromName'] ?? ($message['fromAddress'] ?? ''))),
		];
	}//end entry()

	/**
	 * The ticket title: the message's own (a mail subject), else the start of the text.
	 *
	 * @param array<string, mixed> $message The message.
	 * @param string               $channel The channel.
	 *
	 * @return string The title.
	 */
	private function title(array $message, string $channel): string {
		$title = trim((string) ($message['title'] ?? ''));
		if ($title === '') {
			$title = trim((string) preg_replace('/\s+/u', ' ', (string) ($message['text'] ?? '')));
		}

		if ($title === '') {
			return 'Message via ' . $channel;
		}

		if (mb_strlen($title, 'UTF-8') > 80) {
			return rtrim(mb_substr($title, 0, 79, 'UTF-8')) . '…';
		}

		return $title;
	}//end title()

	/**
	 * A ticket's channel metadata as an array.
	 *
	 * @param array<string, mixed> $ticket The ticket.
	 *
	 * @return array<string, mixed> The metadata.
	 */
	private function metadataOf(array $ticket): array {
		$metadata = ($ticket['channelMetadata'] ?? []);
		if (is_array($metadata) === false) {
			return [];
		}

		return $metadata;
	}//end metadataOf()
}//end class
