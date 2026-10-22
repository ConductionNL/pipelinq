<?php

/**
 * Pipelinq TicketLeafProvider.
 *
 * The open tickets of the parties a host app knows by master entity. A host
 * app (dossiq's organisation list and page) does not hold pipelinq's client
 * uuids; it holds the master entity its own party record shares with the
 * pipelinq client (`masterEntityRef`), so that is the key this leaf answers on.
 *
 * @category Integration
 * @package  OCA\Pipelinq\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/ticket-leaf-by-master-entity/specs/ticket-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Integration;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read the open tickets of parties by their master entity.
 *
 * Both reads go through OpenRegister's object service as the calling user, so
 * a ticket or client the caller may not read is not returned.
 *
 * @spec openspec/changes/ticket-leaf-by-master-entity/specs/ticket-leaf/spec.md
 */
class TicketLeafProvider {
	/**
	 * The statuses in which a ticket is no longer open.
	 *
	 * @var array<int, string>
	 */
	public const CLOSED_STATUSES = ['closed', 'completed', 'converted', 'rejected', 'resolved'];

	/**
	 * The ticket subtypes that count as tickets; an interaction is a contact moment.
	 *
	 * @var array<int, string>
	 */
	public const TICKET_TYPES = [TicketService::TYPE_REQUEST, TicketService::TYPE_COMPLAINT];

	/**
	 * The most master entities one read may name, per ADR-058.
	 *
	 * @var int
	 */
	public const MAX_REFS = 200;

	/**
	 * The most clients or tickets one read returns, per ADR-058.
	 *
	 * @var int
	 */
	public const MAX_ROWS = 1000;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The open tickets of the clients that map to each master entity.
	 *
	 * @param array<int, mixed> $refs The master entity references.
	 *
	 * @return array<string, mixed> `status` plus either `tickets` (a list per reference, every
	 *   asked reference present) or `error`.
	 *
	 * @spec openspec/changes/ticket-leaf-by-master-entity/specs/ticket-leaf/spec.md
	 */
	public function openByMasterEntity(array $refs): array {
		$refs = array_values(
			array_unique(
				array_filter(
					array_map('trim', array_filter($refs, 'is_string')),
					static fn (string $ref): bool => $ref !== ''
				)
			)
		);
		if (count($refs) > self::MAX_REFS) {
			return ['status' => 400, 'error' => 'Too many master entities in one read.'];
		}

		if ($refs === []) {
			return ['status' => 200, 'tickets' => []];
		}

		$ids = [];
		foreach (['register', 'client_schema', 'ticket_schema'] as $key) {
			$ids[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		}

		if (in_array('', $ids, true) === true) {
			return ['status' => 503, 'error' => 'The ticket surface is not configured.'];
		}

		[$register, $clientSchema, $ticketSchema] = array_values($ids);

		$tickets = array_fill_keys($refs, []);
		$refOfClient = $this->refOfClients(register: $register, schema: $clientSchema, refs: $refs);
		if ($refOfClient === []) {
			return ['status' => 200, 'tickets' => $tickets];
		}

		$rows = $this->read(
			filters: [
				'register' => $register,
				'schema' => $ticketSchema,
				'client' => ['in' => array_keys($refOfClient)],
				'ticketType' => ['in' => self::TICKET_TYPES],
				'status' => ['notIn' => self::CLOSED_STATUSES],
			]
		);
		foreach ($rows as $ticket) {
			$ref = ($refOfClient[(string)($ticket['client'] ?? '')] ?? '');
			if ($ref !== '' && in_array((string)($ticket['status'] ?? ''), self::CLOSED_STATUSES, true) === false) {
				$tickets[$ref][] = $this->present(ticket: $ticket);
			}
		}

		return ['status' => 200, 'tickets' => $tickets];
	}//end openByMasterEntity()

	/**
	 * The master entity of each client that maps to one of the references, by client id.
	 *
	 * @param string $register The register id.
	 * @param string $schema The client schema id.
	 * @param array<int, string> $refs The master entity references.
	 *
	 * @return array<string, string> Master entity per client id.
	 */
	private function refOfClients(string $register, string $schema, array $refs): array {
		$refOfClient = [];
		foreach ($this->read(filters: ['register' => $register, 'schema' => $schema, 'masterEntityRef' => ['in' => $refs]]) as $client) {
			$id = (string)($client['id'] ?? $client['uuid'] ?? '');
			$ref = (string)($client['masterEntityRef'] ?? '');
			if ($id !== '' && in_array($ref, $refs, true) === true) {
				$refOfClient[$id] = $ref;
			}
		}

		return $refOfClient;
	}//end refOfClients()

	/**
	 * The fields of a ticket a host app shows.
	 *
	 * @param array<string, mixed> $ticket The stored ticket.
	 *
	 * @return array<string, string> The leaf row.
	 */
	private function present(array $ticket): array {
		return [
			'id' => (string)($ticket['id'] ?? $ticket['uuid'] ?? ''),
			'title' => (string)($ticket['title'] ?? ''),
			'ticketType' => (string)($ticket['ticketType'] ?? ''),
			'status' => (string)($ticket['status'] ?? ''),
			'assignee' => (string)($ticket['assignee'] ?? ''),
			'slaDeadline' => (string)($ticket['slaDeadline'] ?? ''),
		];
	}//end present()

	/**
	 * Read objects as arrays; a failed read is logged and reads as none.
	 *
	 * @param array<string, mixed> $filters The OpenRegister filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function read(array $filters): array {
		try {
			$rows = $this->objectService->findAll(['filters' => $filters, 'limit' => self::MAX_ROWS]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TicketLeafProvider: a read failed',
				['schema' => ($filters['schema'] ?? ''), 'exception' => $e->getMessage()]
			);

			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			$data = $row;
			if (($row instanceof \JsonSerializable) === true) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === true) {
				$out[] = $data;
			}
		}

		return $out;
	}//end read()
}//end class
