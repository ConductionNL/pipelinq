<?php

/**
 * Pipelinq PhoneHistoryService.
 *
 * Finds the tickets and contact moments about a phone number, however the
 * number was typed.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\Contracts\PhoneHistoryServiceInterface;
use OCA\Pipelinq\Util\PayloadToArray;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The phone search over tickets (design D3).
 *
 * The query is normalised the way the screen pop normalises a caller, then
 * matched on the ticket's stored `phoneE164`. A ticket stored before that field
 * existed is still found by the last nine digits of its number, and is labelled
 * a possible match, because a number from another country can share that tail.
 * Every read goes through OpenRegister as the viewer, so a ticket the viewer
 * may not read is never in the result.
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.4
 */
class PhoneHistoryService implements PhoneHistoryServiceInterface {
	use PayloadToArray;

	/**
	 * Most tickets one search answers.
	 */
	public const MAX_RESULTS = 50;

	/**
	 * Recent tickets scanned for a number stored before `phoneE164` existed.
	 */
	public const TAIL_SCAN = 500;

	/**
	 * A ticket whose stored E.164 number equals the query's.
	 */
	public const MATCH_EXACT = 'exact';

	/**
	 * A ticket whose number only shares the query's last nine digits.
	 */
	public const MATCH_POSSIBLE = 'possible';

	/**
	 * Constructor.
	 *
	 * @param TicketService     $ticketService   The ticket schema and OpenRegister.
	 * @param PhoneNormaliser   $phoneNormaliser Normalises the query.
	 * @param TicketPhoneNumber $phoneNumber     Reads the number a stored ticket is about.
	 * @param LoggerInterface   $logger          PSR logger.
	 */
	public function __construct(
		private readonly TicketService $ticketService,
		private readonly PhoneNormaliser $phoneNormaliser,
		private readonly TicketPhoneNumber $phoneNumber,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The tickets about a number, newest first, at most MAX_RESULTS.
	 *
	 * @param string      $raw   The number as typed.
	 * @param string|null $orgId The organisation whose default country applies.
	 *
	 * @return array{number:string|null, tickets:array<int,array<string,mixed>>}
	 *         The query in E.164 (null when it does not normalise) and the
	 *         tickets, each with `_phoneMatch` set to exact or possible.
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.4
	 */
	public function findTickets(string $raw, ?string $orgId = null): array {
		$e164 = $this->phoneNormaliser->normaliseForOrg($raw, $orgId)['e164'];
		if ($e164 === null || $this->ticketService->isConfigured() === false) {
			return ['number' => $e164, 'tickets' => []];
		}

		$found = [];
		foreach ($this->read(filters: [TicketPhoneNumber::FIELD => $e164], limit: self::MAX_RESULTS) as $ticket) {
			$found[$this->key(ticket: $ticket)] = array_merge($ticket, ['_phoneMatch' => self::MATCH_EXACT]);
		}

		$tail = PhoneNeedles::tail(number: $e164);
		foreach ($this->read(filters: [], limit: self::TAIL_SCAN) as $ticket) {
			$key = $this->key(ticket: $ticket);
			if (isset($found[$key]) === true || $tail === null) {
				continue;
			}

			$stored = $this->phoneNumber->rawNumber(ticket: $ticket);
			if ($stored !== null && PhoneNeedles::tail(number: $stored) === $tail) {
				$found[$key] = array_merge($ticket, ['_phoneMatch' => self::MATCH_POSSIBLE]);
			}
		}

		$tickets = array_values($found);
		usort($tickets, fn (array $a, array $b): int => strcmp($this->when(ticket: $b), $this->when(ticket: $a)));

		return ['number' => $e164, 'tickets' => array_slice($tickets, 0, self::MAX_RESULTS)];
	}//end findTickets()

	/**
	 * Tickets matching the filters, newest first, read as the viewer.
	 *
	 * @param array<string,mixed> $filters Extra filters.
	 * @param int                 $limit   Most rows to read.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function read(array $filters, int $limit): array {
		try {
			$rows = $this->ticketService->getObjectService()->findAll(
				[
					'filters' => array_merge(
						['register' => $this->ticketService->getRegisterId(), 'schema' => $this->ticketService->getSchemaId()],
						$filters
					),
					'sort' => ['occurredAt' => 'desc'],
					'limit' => $limit,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Phone history: reading tickets failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$tickets = [];
		foreach ($rows as $row) {
			$ticket = $this->toArray(value: $row);
			if ($ticket !== []) {
				$tickets[] = $ticket;
			}
		}

		return $tickets;
	}//end read()

	/**
	 * The ticket's id, or its position-free fingerprint when it has none.
	 *
	 * @param array<string,mixed> $ticket The ticket.
	 *
	 * @return string
	 */
	private function key(array $ticket): string {
		$id = (string) ($ticket['id'] ?? ($ticket['uuid'] ?? ''));
		if ($id !== '') {
			return $id;
		}

		return md5((string) json_encode($ticket));
	}//end key()

	/**
	 * When the ticket happened, for the newest-first order.
	 *
	 * @param array<string,mixed> $ticket The ticket.
	 *
	 * @return string
	 */
	private function when(array $ticket): string {
		$self    = ($ticket['@self'] ?? []);
		$created = null;
		if (is_array($self) === true) {
			$created = ($self['created'] ?? null);
		}

		foreach ([($ticket['occurredAt'] ?? null), ($ticket['started_at'] ?? null), $created] as $value) {
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return '';
	}//end when()
}//end class
