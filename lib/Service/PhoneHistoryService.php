<?php

/**
 * Pipelinq PhoneHistoryService.
 *
 * Finds tickets and contact moments by the phone number they are about.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Listener\TicketPhoneListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The query is normalised with the screen pop's normaliser and matched on the
 * ticket's stored `phoneE164`, read as the viewer, so a ticket the viewer may
 * not read is never returned.
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */
class PhoneHistoryService {
	/**
	 * The most tickets one search returns.
	 */
	public const MAX_RESULTS = 50;

	/**
	 * How many tickets the back-fill reads per page.
	 */
	private const BATCH_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param TicketService       $tickets    The ticket service.
	 * @param PhoneNormaliser     $normaliser The phone normaliser the screen pop uses.
	 * @param TicketPhoneListener $phone      The rule that picks a ticket's number.
	 * @param LoggerInterface     $logger     The logger.
	 */
	public function __construct(
		private readonly TicketService $tickets,
		private readonly PhoneNormaliser $normaliser,
		private readonly TicketPhoneListener $phone,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The tickets about a phone number, newest first.
	 *
	 * @param string      $raw   The number as typed or as the platform sent it.
	 * @param string|null $orgId The organisation, for its default country.
	 *
	 * @return array{number: string|null, results: array<int, array<string, mixed>>} The
	 *         normalised number (null when the text is no phone number) and the tickets.
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
	 */
	public function findTickets(string $raw, ?string $orgId = null): array {
		$e164 = $this->normaliser->normaliseForOrg($raw, $orgId)['e164'];
		if ($e164 === null || $this->tickets->isConfigured() === false) {
			return ['number' => $e164, 'results' => []];
		}

		try {
			$rows = $this->tickets->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $this->tickets->getRegisterId(),
						'schema' => $this->tickets->getSchemaId(),
						'phoneE164' => $e164,
					],
					'sort' => ['occurredAt' => 'desc'],
					'limit' => self::MAX_RESULTS,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: phone search failed', ['exception' => $e->getMessage()]);
			return ['number' => $e164, 'results' => []];
		}

		$results = [];
		foreach ($rows as $row) {
			$ticket = $this->toArray(object: $row);
			if (($ticket['phoneE164'] ?? null) !== $e164) {
				continue;
			}

			$results[] = $this->summary(ticket: $ticket);
		}

		return ['number' => $e164, 'results' => $results];
	}//end findTickets()

	/**
	 * Store `phoneE164` on every ticket that carries a number and lacks it.
	 *
	 * Idempotent: a ticket whose stored value already matches is skipped.
	 *
	 * @return array{updated: int, skipped: int} The counts.
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
	 */
	public function backfillTicketPhones(): array {
		$counts = ['updated' => 0, 'skipped' => 0];
		if ($this->tickets->isConfigured() === false) {
			return $counts;
		}

		$objectService = $this->tickets->getObjectService();
		$register = $this->tickets->getRegisterId();
		$schema = $this->tickets->getSchemaId();

		$offset = 0;
		do {
			$rows = $objectService->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $schema],
					'limit' => self::BATCH_SIZE,
					'offset' => $offset,
				],
				_rbac: false,
				_multitenancy: false
			);

			foreach ($rows as $row) {
				[$ticket, $uuid] = $this->dataAndId(object: $row);
				$e164 = $this->phone->numberOf(ticket: $ticket);
				if ($e164 === null || $uuid === '' || ($ticket['phoneE164'] ?? null) === $e164) {
					$counts['skipped']++;
					continue;
				}

				try {
					$objectService->runAsSystem(
						static fn () => $objectService->saveObject(
							object: array_merge($ticket, ['phoneE164' => $e164]),
							register: $register,
							schema: $schema,
							uuid: $uuid
						)
					);
					$counts['updated']++;
				} catch (Throwable $e) {
					$this->logger->warning(
						'Pipelinq: phone back-fill skipped a ticket',
						['id' => $uuid, 'exception' => $e->getMessage()]
					);
					$counts['skipped']++;
				}
			}//end foreach

			$offset += self::BATCH_SIZE;
		} while (count($rows) === self::BATCH_SIZE);

		return $counts;
	}//end backfillTicketPhones()

	/**
	 * What the phone search shows of one ticket.
	 *
	 * @param array<string, mixed> $ticket The ticket data.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function summary(array $ticket): array {
		return [
			'id' => (string)($ticket['id'] ?? ($ticket['uuid'] ?? '')),
			'title' => (string)($ticket['title'] ?? ''),
			'ticketType' => (string)($ticket['ticketType'] ?? ''),
			'status' => (string)($ticket['status'] ?? ''),
			'channel' => (string)($ticket['channel'] ?? ''),
			'direction' => (string)($ticket['direction'] ?? ''),
			'assignee' => (string)($ticket['assignee'] ?? ''),
			'reference' => (string)($ticket['reference'] ?? ''),
			'occurredAt' => (string)($ticket['occurredAt'] ?? ($ticket['started_at'] ?? ($ticket['@self']['created'] ?? ''))),
		];
	}//end summary()

	/**
	 * The stored data of an OpenRegister object and its id, for a write back.
	 *
	 * @param mixed $object The object (entity or array).
	 *
	 * @return array{0: array<string, mixed>, 1: string} The data and the id.
	 */
	private function dataAndId(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'getObject') === true && method_exists($object, 'getUuid') === true) {
			return [($object->getObject() ?? []), (string)$object->getUuid()];
		}

		$data = $this->toArray(object: $object);
		$uuid = (string)($data['id'] ?? ($data['uuid'] ?? ''));
		unset($data['@self'], $data['id'], $data['uuid']);

		return [$data, $uuid];
	}//end dataAndId()

	/**
	 * An OpenRegister object (entity or array) as plain data with its id.
	 *
	 * @param mixed $object The object.
	 *
	 * @return array<string, mixed> The data.
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
