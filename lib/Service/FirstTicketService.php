<?php

/**
 * Pipelinq FirstTicketService.
 *
 * The one ticket a handler should take first today: of the open tickets
 * assigned to them, the most overdue, then the highest priority, then the
 * oldest. The dashboard's "First, today" card names it.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://pipelinq.nl
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Picks the first ticket of a handler's day.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
class FirstTicketService {
	/**
	 * The ticket types a handler is assigned.
	 *
	 * @var array<int, string>
	 */
	private const TICKET_TYPES = [
		TicketService::TYPE_REQUEST,
		TicketService::TYPE_COMPLAINT,
		TicketService::TYPE_CONTACTMOMENT,
	];

	/**
	 * Most tickets read per type.
	 *
	 * @var int
	 */
	private const FETCH_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param TicketService $ticketService Reads tickets by type.
	 * @param ObjectServiceInterface $objectService Reads the ticket's client.
	 * @param IAppConfig $appConfig Holds the register and client schema.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly TicketService $ticketService,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The first open ticket assigned to the user, or null when there is none.
	 *
	 * @param string $userId The handler.
	 * @param DateTimeImmutable|null $now Now; the current time when null.
	 *
	 * @return array<string, mixed>|null {id, title, reference, ticketType, priority, channel, client, clientName, waitingDays, overdueDays}
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function firstFor(string $userId, ?DateTimeImmutable $now = null): ?array {
		$nowTs = ($now ?? new DateTimeImmutable())->getTimestamp();

		$tickets = [];
		foreach (self::TICKET_TYPES as $ticketType) {
			foreach ($this->ticketService->findByType(ticketType: $ticketType, extraFilters: ['assignee' => $userId], limit: self::FETCH_LIMIT) as $result) {
				$ticket = $this->toArray(object: $result);
				if (in_array((string) ($ticket['status'] ?? ''), WorklistService::TERMINAL_TICKET_STATUSES, true) === false) {
					$tickets[] = $ticket;
				}
			}
		}

		if ($tickets === []) {
			return null;
		}

		usort($tickets, fn (array $a, array $b): int => $this->compare(a: $a, b: $b, nowTs: $nowTs));

		return $this->row(ticket: $tickets[0], nowTs: $nowTs);
	}//end firstFor()

	/**
	 * Order: most overdue first (a ticket past its deadline before one that is
	 * not), then the highest priority, then the oldest.
	 *
	 * @param array<string, mixed> $a A ticket.
	 * @param array<string, mixed> $b Another ticket.
	 * @param int $nowTs Now.
	 *
	 * @return int The comparison.
	 */
	private function compare(array $a, array $b, int $nowTs): int {
		$overdue = ($this->overdueSeconds(ticket: $b, nowTs: $nowTs) <=> $this->overdueSeconds(ticket: $a, nowTs: $nowTs));
		if ($overdue !== 0) {
			return $overdue;
		}

		$rankA = (WorklistService::PRIORITY_ORDER[(string) ($a['priority'] ?? '')] ?? WorklistService::PRIORITY_ORDER['normal']);
		$rankB = (WorklistService::PRIORITY_ORDER[(string) ($b['priority'] ?? '')] ?? WorklistService::PRIORITY_ORDER['normal']);
		if ($rankA !== $rankB) {
			return ($rankA <=> $rankB);
		}

		return ($this->createdAt(ticket: $a) <=> $this->createdAt(ticket: $b));
	}//end compare()

	/**
	 * How long a ticket is past its deadline, in seconds; 0 when it is not.
	 *
	 * @param array<string, mixed> $ticket The ticket.
	 * @param int $nowTs Now.
	 *
	 * @return int
	 */
	private function overdueSeconds(array $ticket, int $nowTs): int {
		$deadline = $this->timestamp(value: ($ticket['slaDeadline'] ?? null));
		if ($deadline === null || $deadline >= $nowTs) {
			return 0;
		}

		return ($nowTs - $deadline);
	}//end overdueSeconds()

	/**
	 * When the ticket came in: occurredAt, else the record's creation time.
	 *
	 * @param array<string, mixed> $ticket The ticket.
	 *
	 * @return int A timestamp; PHP_INT_MAX when unknown, so it sorts last.
	 */
	private function createdAt(array $ticket): int {
		$self = [];
		if (is_array($ticket['@self'] ?? null) === true) {
			$self = $ticket['@self'];
		}

		return ($this->timestamp(value: ($ticket['occurredAt'] ?? null)) ?? $this->timestamp(value: ($self['created'] ?? null)) ?? PHP_INT_MAX);
	}//end createdAt()

	/**
	 * The card's row: the ticket's fields plus the client's name and whole days
	 * waiting and overdue.
	 *
	 * @param array<string, mixed> $ticket The ticket.
	 * @param int $nowTs Now.
	 *
	 * @return array<string, mixed>
	 */
	private function row(array $ticket, int $nowTs): array {
		$created = $this->createdAt(ticket: $ticket);
		$clientId = (string) ($ticket['client'] ?? '');
		$waitingDays = 0;
		if ($created !== PHP_INT_MAX) {
			$waitingDays = max(0, intdiv($nowTs - $created, 86400));
		}

		return [
			'id'          => (string) ($ticket['id'] ?? ''),
			'title'       => (string) ($ticket['title'] ?? ''),
			'reference'   => (string) ($ticket['reference'] ?? ''),
			'ticketType'  => (string) ($ticket['ticketType'] ?? ''),
			'priority'    => (string) ($ticket['priority'] ?? ''),
			'channel'     => (string) ($ticket['channel'] ?? ''),
			'client'      => $clientId,
			'clientName'  => $this->clientName(clientId: $clientId),
			'waitingDays' => $waitingDays,
			'overdueDays' => intdiv($this->overdueSeconds(ticket: $ticket, nowTs: $nowTs), 86400),
		];
	}//end row()

	/**
	 * The client's name as the user may read it, '' when unknown.
	 *
	 * @param string $clientId The client id.
	 *
	 * @return string
	 */
	private function clientName(string $clientId): string {
		if ($clientId === '') {
			return '';
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		try {
			$client = $this->objectService->find(id: $clientId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->debug('Pipelinq: client not read for the first ticket', ['exception' => $e->getMessage()]);
			return '';
		}

		if ($client === null) {
			return '';
		}

		return (string) ($this->toArray(object: $client)['name'] ?? '');
	}//end clientName()

	/**
	 * A date-time as a timestamp, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null
	 */
	private function timestamp(mixed $value): ?int {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		$stamp = strtotime($value);
		if ($stamp === false) {
			return null;
		}

		return $stamp;
	}//end timestamp()

	/**
	 * An OpenRegister entity or array as an array.
	 *
	 * @param mixed $object The entity.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$data = $object->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end toArray()
}//end class
