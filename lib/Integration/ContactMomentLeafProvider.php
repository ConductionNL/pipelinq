<?php

/**
 * Pipelinq ContactMomentLeafProvider.
 *
 * The data half of the `pipelinq-contact-moments` leaf: the contract a host
 * app calls to list the contact moments filed on one of its objects, and to
 * append a new one. One contact moment schema exists in the fleet, pipelinq's,
 * and a case app reaches it through this provider rather than declaring a
 * second schema under the same global slug.
 *
 * Two rules the provider keeps, both of them ADR-066 decision 2:
 *
 *   1. It calls nothing in the consuming app. The host is an opaque uuid; the
 *      provider reads it only to decide whether the caller may see it.
 *   2. It refuses a caller who cannot read the host. OpenRegister's own RBAC
 *      answers that question: `find()` returns null for an object the acting
 *      user may not read, so the guard is the register's, not a second
 *      permission model of pipelinq's.
 *
 * @category Integration
 * @package  OCA\Pipelinq\Integration
 *
 * @author    Conduction <info@conduction.nl>
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
 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-contact-moments-are-a-data-provider-leaf-with-append-req-cmd-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Integration;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Pipelinq\Service\ContactMomentFilingService;
use OCA\Pipelinq\Service\PartyIndicatorService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * List and append the contact moments filed on a host object.
 *
 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-contact-moments-are-a-data-provider-leaf-with-append-req-cmd-003
 */
class ContactMomentLeafProvider {
	/**
	 * The leaf id a host declares in its schema's `linkedTypes`.
	 *
	 * @var string
	 */
	public const LEAF_ID = 'pipelinq-contact-moments';

	/**
	 * The render-surface id that draws the leaf's data.
	 *
	 * @var string
	 */
	public const PANEL_ID = 'pipelinq-contact-moments-panel';

	/**
	 * Default page size for a host's contact moments.
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT = 50;

	/**
	 * Hard ceiling on a page, per ADR-058: a leaf never scans a whole schema.
	 *
	 * @var int
	 */
	public const MAX_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param TicketService $ticketService Resolver for the unified `ticket` supertype.
	 * @param ContactMomentFilingService $filingService The case set a contact moment is filed on.
	 * @param PartyIndicatorService $indicatorService The party's standing indicators.
	 * @param IUserSession $userSession The acting user, stamped as the agent.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly TicketService $ticketService,
		private readonly ContactMomentFilingService $filingService,
		private readonly PartyIndicatorService $indicatorService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The contact moments filed on one host object, newest first.
	 *
	 * @param string $hostId The host object's uuid.
	 * @param int $limit Page size, capped at MAX_LIMIT.
	 * @param string $partyId The party whose standing indicators ride along, or
	 *   an empty string to answer without them.
	 *
	 * @return array<string, mixed> `status` plus either `contactMoments` or `error`.
	 *
	 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-contact-moments-are-a-data-provider-leaf-with-append-req-cmd-003
	 */
	public function list(string $hostId, int $limit = self::DEFAULT_LIMIT, string $partyId = ''): array {
		$hostId = trim($hostId);
		if ($hostId === '') {
			return ['status' => 400, 'error' => 'A host object id is required.'];
		}

		if ($this->canReadHost(hostId: $hostId) === false) {
			return ['status' => 403, 'error' => 'You may not read this object.'];
		}

		$limit = max(1, min($limit, self::MAX_LIMIT));

		// MEMBERSHIP, not equality: one contact moment filed on three cases is
		// one record, and each of the three has to see it. Filtering on the
		// single `caseReference` would show it only on the primary, and an
		// absent row on the other two looks exactly like a case with no
		// contact moments. Still bounded by `limit` per ADR-058.
		$rows = $this->ticketService->findByType(
			ticketType: TicketService::TYPE_CONTACTMOMENT,
			extraFilters: ['caseReferences' => $hostId],
			limit: $limit,
		);

		$moments = array_map(
			fn (mixed $row): array => $this->present(row: $row, hostId: $hostId),
			$rows
		);

		usort(
			$moments,
			static fn (array $a, array $b): int => strcmp((string)$b['occurredAt'], (string)$a['occurredAt'])
		);

		// The party's indicators travel with the panel, resolved live, so a KCC
		// agent taking a call reads "agressie-registratie" BEFORE they speak.
		// Nothing is copied onto a contact moment: this is a read.
		$indicators = [];
		if ($partyId !== '') {
			$indicators = $this->indicatorService->resolve(partyId: trim($partyId));
		}

		return [
			'status' => 200,
			'contactMoments' => $moments,
			'indicators' => $indicators,
		];
	}//end list()

	/**
	 * Append one contact moment to a host object.
	 *
	 * @param string $hostId The host object's uuid, written as `caseReference`.
	 * @param array<string, mixed> $payload Subject, channel, direction, outcome, summary.
	 *
	 * @return array<string, mixed> `status` plus either `contactMoment` or `error`.
	 *
	 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-contact-moments-are-a-data-provider-leaf-with-append-req-cmd-003
	 */
	public function create(string $hostId, array $payload): array {
		$hostId = trim($hostId);
		if ($hostId === '') {
			return ['status' => 400, 'error' => 'A host object id is required.'];
		}

		// The read guard is the write guard: a caller who may not see the host
		// may not file anything against it either. Checked BEFORE any field
		// validation, so a refused caller learns nothing about the payload the
		// surface would have accepted.
		if ($this->canReadHost(hostId: $hostId) === false) {
			return ['status' => 403, 'error' => 'You may not read this object.'];
		}

		$partyId = trim((string)($payload['client'] ?? $payload['partyId'] ?? ''));

		$refusal = $this->outboundRefusal(partyId: $partyId, payload: $payload);
		if ($refusal !== null) {
			return $refusal;
		}

		$ticket = $this->ticketFrom(hostId: $hostId, payload: $payload, partyId: $partyId);

		if ($ticket['title'] === '') {
			return ['status' => 400, 'error' => 'A contact moment requires a subject.'];
		}

		if ($ticket['channel'] === '') {
			return ['status' => 400, 'error' => 'A contact moment requires a channel.'];
		}

		try {
			$saved = $this->ticketService->save(
				ticketType: TicketService::TYPE_CONTACTMOMENT,
				payload: $ticket,
			);
		} catch (InvalidArgumentException $e) {
			// The facet guard in TicketService, which names the field it missed.
			return ['status' => 400, 'error' => $e->getMessage()];
		} catch (Throwable $e) {
			$this->logger->error(
				'ContactMomentLeafProvider: failed to append a contact moment',
				['host' => $hostId, 'exception' => $e->getMessage()]
			);

			return ['status' => 500, 'error' => 'The contact moment could not be saved.'];
		}//end try

		return ['status' => 201, 'contactMoment' => $this->present(row: $saved, hostId: $hostId)];
	}//end create()

	/**
	 * The refusal an outbound append meets, or null when it may go ahead.
	 *
	 * An outbound append against a blocking indicator is refused at the point
	 * of writing, naming the indicator. Pipelinq answers the question here
	 * because the panel is pipelinq's own surface; it still places no listener
	 * on anybody else's send path.
	 *
	 * @param string $partyId The party the moment names, or an empty string.
	 * @param array<string, mixed> $payload The append payload.
	 *
	 * @return array<string, mixed>|null The 409 answer, or null.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-pipelinq-shall-answer-whether-an-indicator-blocks-an-act-and-shall-not-intercept-it-req-pfi-004
	 */
	private function outboundRefusal(string $partyId, array $payload): ?array {
		if ($partyId === '' || trim((string)($payload['direction'] ?? '')) !== 'outbound') {
			return null;
		}

		$answer = $this->indicatorService->isBlocked(partyId: $partyId, act: 'send');
		if ($answer['blocked'] === false) {
			return null;
		}

		$labels = implode(', ', array_column($answer['indicators'], 'label'));

		return [
			'status' => 409,
			'error' => "Outbound contact with this party is blocked by {$labels}.",
			'indicators' => $answer['indicators'],
		];
	}//end outboundRefusal()

	/**
	 * The ticket an append payload becomes.
	 *
	 * @param string $hostId The host object's uuid.
	 * @param array<string, mixed> $payload The append payload.
	 * @param string $partyId The party the moment names, or an empty string.
	 *
	 * @return array<string, mixed> The ticket, ready for TicketService::save().
	 *
	 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-contact-moments-are-a-data-provider-leaf-with-append-req-cmd-003
	 */
	private function ticketFrom(string $hostId, array $payload, string $partyId): array {
		$ticket = [
			'title' => trim((string)($payload['title'] ?? $payload['subject'] ?? '')),
			'channel' => trim((string)($payload['channel'] ?? '')),
			'direction' => trim((string)($payload['direction'] ?? '')),
			'caseReference' => $hostId,
			'caseReferences' => [$hostId],
			'primaryCaseReference' => $hostId,
			'assignee' => $this->actingUserId(),
			'occurredAt' => $this->occurredAt(payload: $payload),
		];

		foreach (['outcome' => 'outcome', 'summary' => 'description', 'description' => 'description'] as $from => $to) {
			$value = trim((string)($payload[$from] ?? ''));
			if ($value !== '') {
				$ticket[$to] = $value;
			}
		}

		if ($partyId !== '') {
			// Only when named: an empty string on a uuid property fails the
			// schema, and a contact moment on a case need not name a party.
			$ticket['client'] = $partyId;
		}

		return $ticket;
	}//end ticketFrom()

	/**
	 * Whether the acting user may read the host object.
	 *
	 * RBAC stays ON: `find()` answers null both for an object that does not
	 * exist and for one the caller may not see, and the provider treats the two
	 * the same on purpose. Telling them apart would tell an unauthorised caller
	 * that the object exists.
	 *
	 * @param string $hostId The host object's uuid.
	 *
	 * @return bool True when the object resolves for this caller.
	 */
	private function canReadHost(string $hostId): bool {
		try {
			return $this->ticketService->getObjectService()->find(id: $hostId) !== null;
		} catch (Throwable $e) {
			$this->logger->debug(
				'ContactMomentLeafProvider: host lookup failed, refusing',
				['host' => $hostId, 'exception' => $e->getMessage()]
			);

			return false;
		}
	}//end canReadHost()

	/**
	 * The acting user's id, or '' when there is no session.
	 *
	 * @return string The user id.
	 */
	private function actingUserId(): string {
		return ($this->userSession->getUser()?->getUID() ?? '');
	}//end actingUserId()

	/**
	 * When the contact happened: what the caller said, else now.
	 *
	 * @param array<string, mixed> $payload The caller's payload.
	 *
	 * @return string An ISO-8601 instant.
	 */
	private function occurredAt(array $payload): string {
		$given = trim((string)($payload['occurredAt'] ?? ''));
		if ($given !== '') {
			return $given;
		}

		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}//end occurredAt()

	/**
	 * Present one stored ticket as a contact moment row.
	 *
	 * @param mixed $row The stored ticket.
	 * @param string $hostId The case this row is being rendered on, so the
	 *   shared marker can name the OTHER cases rather than this one.
	 *
	 * @return array<string, mixed> The leaf row.
	 */
	private function present(mixed $row, string $hostId = ''): array {
		$data = [];
		if (is_array($row) === true) {
			$data = $row;
		} elseif (($row instanceof \JsonSerializable) === true) {
			$serialised = $row->jsonSerialize();
			if (is_array($serialised) === true) {
				$data = $serialised;
			}
		}

		// A case the reader may not see is counted, not named. The permission
		// question is OpenRegister's, asked once per other case.
		$marker = $this->filingService->sharedMarker(
			moment: $data,
			hostId: $hostId,
			mayRead: fn (string $reference): bool => $this->canReadHost(hostId: $reference),
		);

		return [
			'id' => (string)($data['id'] ?? $data['uuid'] ?? ''),
			'subject' => (string)($data['title'] ?? ''),
			'channel' => (string)($data['channel'] ?? ''),
			'direction' => (string)($data['direction'] ?? ''),
			'agent' => (string)($data['assignee'] ?? ''),
			'occurredAt' => (string)($data['occurredAt'] ?? ''),
			'outcome' => (string)($data['outcome'] ?? ''),
			'summary' => (string)($data['description'] ?? ''),
			'primaryCase' => $this->filingService->primaryOf(moment: $data),
			'shared' => $marker['shared'],
			'alsoOnCases' => $marker['alsoOnCases'],
			'alsoOnHiddenCount' => $marker['hiddenCount'],
		];
	}//end present()
}//end class
