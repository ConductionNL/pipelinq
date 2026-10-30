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
 * @spec openspec/specs/ticket-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Integration;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read the open tickets of parties by their master entity.
 *
 * Both reads go through OpenRegister's object service as the calling user, so
 * a ticket or client the caller may not read is not returned.
 *
 * @spec openspec/specs/ticket-leaf/spec.md
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
	 * The status filters a read may ask for: the open tickets, the closed
	 * ones, or both.
	 *
	 * @var array<int, string>
	 */
	public const STATUS_FILTERS = ['open', 'closed', 'all'];

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
	 * @param IUserManager $userManager Names the assignee.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly IUserManager $userManager,
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
	 * @spec openspec/specs/ticket-leaf/spec.md
	 */
	public function openByMasterEntity(array $refs): array {
		return $this->byMasterEntity(refs: $refs, status: 'open');
	}//end openByMasterEntity()

	/**
	 * The tickets of the clients that map to each master entity, open, closed
	 * or both, so a host app can list a party's handled tickets beside its
	 * open ones.
	 *
	 * @param array<int, mixed> $refs The master entity references.
	 * @param string $status `open`, `closed` or `all`.
	 *
	 * @return array<string, mixed> `status` plus either `tickets` (a list per reference, every
	 *   asked reference present) or `error`.
	 *
	 * @spec openspec/changes/ticket-leaf-closed-reference-assignee/specs/ticket-leaf/spec.md#requirement-a-host-app-can-read-a-partys-closed-tickets-too
	 */
	public function byMasterEntity(array $refs, string $status = 'open'): array {
		if (in_array($status, self::STATUS_FILTERS, true) === false) {
			return ['status' => 400, 'error' => 'Unknown ticket status filter.'];
		}

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

		$refOfClient = $this->refOfClients(register: $register, schema: $clientSchema, refs: $refs);

		return [
			'status' => 200,
			'tickets' => $this->ticketsOf(
				refs: $refs,
				refOfClient: $refOfClient,
				filters: ['register' => $register, 'schema' => $ticketSchema],
				status: $status
			),
		];
	}//end byMasterEntity()

	/**
	 * The tickets of the clients, grouped by their master entity, every
	 * reference present.
	 *
	 * @param array<int, string>    $refs        The master entity references.
	 * @param array<string, string> $refOfClient Master entity per client id.
	 * @param array<string, string> $filters     The register and ticket schema.
	 * @param string                $status      `open`, `closed` or `all`.
	 *
	 * @return array<string, array<int, array<string, string>>> Tickets per reference.
	 */
	private function ticketsOf(array $refs, array $refOfClient, array $filters, string $status): array {
		$tickets = array_fill_keys($refs, []);
		if ($refOfClient === []) {
			return $tickets;
		}

		$filters['client'] = ['in' => array_keys($refOfClient)];
		$filters['ticketType'] = ['in' => self::TICKET_TYPES];
		if ($status === 'open') {
			$filters['status'] = ['notIn' => self::CLOSED_STATUSES];
		}

		if ($status === 'closed') {
			$filters['status'] = ['in' => self::CLOSED_STATUSES];
		}

		foreach ($this->read(filters: $filters) as $ticket) {
			$ref = ($refOfClient[(string)($ticket['client'] ?? '')] ?? '');
			if ($ref !== '' && $this->matches(ticket: $ticket, status: $status) === true) {
				$tickets[$ref][] = $this->present(ticket: $ticket);
			}
		}

		return $tickets;
	}//end ticketsOf()

	/**
	 * Whether a ticket the store answered fits the asked status filter, so a
	 * store that ignores the status filter still answers only what was asked.
	 *
	 * @param array<string, mixed> $ticket The stored ticket.
	 * @param string $status `open`, `closed` or `all`.
	 *
	 * @return bool True when it fits.
	 */
	private function matches(array $ticket, string $status): bool {
		if ($status === 'all') {
			return true;
		}

		$closed = in_array((string)($ticket['status'] ?? ''), self::CLOSED_STATUSES, true);
		if ($status === 'closed') {
			return $closed;
		}

		return $closed === false;
	}//end matches()

	/**
	 * The clients that map to one master entity: what a host app's link to
	 * that party's ticket list filters on. None when the reference is empty,
	 * unknown, or the ticket surface is not configured.
	 *
	 * @param string $ref The master entity reference.
	 *
	 * @return array<int, string> The client ids.
	 *
	 * @spec openspec/changes/tickets-list-by-party/specs/ticket-leaf/spec.md#requirement-a-host-app-can-link-to-one-partys-ticket-list
	 */
	public function clientIdsOf(string $ref): array {
		$ref = trim($ref);
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$clientSchema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($ref === '' || $register === '' || $clientSchema === '') {
			return [];
		}

		return array_keys($this->refOfClients(register: $register, schema: $clientSchema, refs: [$ref]));
	}//end clientIdsOf()

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
		$assignee = (string)($ticket['assignee'] ?? '');

		return [
			'id' => (string)($ticket['id'] ?? $ticket['uuid'] ?? ''),
			'reference' => (string)($ticket['reference'] ?? ''),
			'title' => (string)($ticket['title'] ?? ''),
			'ticketType' => (string)($ticket['ticketType'] ?? ''),
			'status' => (string)($ticket['status'] ?? ''),
			'assignee' => $assignee,
			'assigneeName' => $this->nameOf(uid: $assignee),
			'slaDeadline' => (string)($ticket['slaDeadline'] ?? ''),
			'resolvedAt' => (string)($ticket['resolvedAt'] ?? ''),
		];
	}//end present()

	/**
	 * The display name of a Nextcloud user, the uid when there is none, '' for no user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return string The name.
	 */
	private function nameOf(string $uid): string {
		if ($uid === '') {
			return '';
		}

		$name = $this->userManager->getDisplayName($uid);
		if ($name === null || $name === '') {
			return $uid;
		}

		return $name;
	}//end nameOf()

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
