<?php

/**
 * Pipelinq PortalRequestService.
 *
 * The portal's request surface over the unified `ticket` schema (ticketType
 * `request`): list the customer's own requests (scoped by their linked
 * contact; the ticket's internal notes are never shown), read one request's
 * customer-safe detail, submit a new request (category must be
 * customer-exposed, ≤25 MB attachments, ≤5 submissions/hour), and add a
 * customer reply that unpauses an `awaiting_customer` ticket. A portal
 * user can only ever see or act on a request tied to their own contact (or one
 * delegated under `submit-requests`) — never another customer's (ADR-005,
 * REQ-004 / REQ-006).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\Event\PortalRequestSubmittedEvent;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Per-customer request list / submit / reply facade.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Aggregates the collaborators a
 *  request surface needs (reader, scope resolver, audit, dispatcher, time).
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class is the one cohesive
 *  request surface (list + detail + submit + reply + their per-customer scoping,
 *  validation and presentation helpers); splitting it would scatter a single
 *  concern across classes without reducing real complexity.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalRequestService {
	/**
	 * Main-register schema a portal request lives in. The `request` schema was
	 * folded into the unified `ticket` on 12 July 2026; a request is a ticket
	 * with ticketType `request` (pipelinq#2038).
	 *
	 * @var string
	 */
	private const SCHEMA = 'ticket';

	/**
	 * The ticketType a portal request carries.
	 *
	 * @var string
	 */
	private const TICKET_TYPE = 'request';

	/**
	 * Ticket status while the handler waits for the customer: the only status
	 * in which the portal offers a reply.
	 *
	 * @var string
	 */
	private const STATUS_AWAITING_CUSTOMER = 'awaiting_customer';

	/**
	 * Ticket status a customer reply moves a waiting ticket back to.
	 *
	 * @var string
	 */
	private const STATUS_IN_PROGRESS = 'in_progress';

	/**
	 * Maximum attachment size in bytes (25 MB).
	 *
	 * @var int
	 */
	private const MAX_ATTACHMENT_BYTES = (25 * 1024 * 1024);

	/**
	 * Maximum submissions per account per window.
	 *
	 * @var int
	 */
	private const RATE_LIMIT = 5;

	/**
	 * Rate-limit window in minutes.
	 *
	 * @var int
	 */
	private const RATE_WINDOW_MINUTES = 60;

	/**
	 * Constructor.
	 *
	 * @param MainRegisterReader $reader The main-register reader/writer.
	 * @param PortalScopeResolver $scope The scope resolver.
	 * @param PortalAuditService $audit The audit service.
	 * @param IEventDispatcher $dispatcher The event dispatcher (SLA hook).
	 * @param ITimeFactory $time The time factory.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private MainRegisterReader $reader,
		private PortalScopeResolver $scope,
		private PortalAuditService $audit,
		private IEventDispatcher $dispatcher,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What the resident portal shows of a request ticket the caller already
	 * read, for the back-office preview on TicketDetail.
	 *
	 * It is `presentDetail()` unchanged, so the preview cannot drift from the
	 * portal. A complaint or interaction ticket is not served by the portal
	 * and has no preview (null).
	 *
	 * @param array<string, mixed> $ticket             The ticket, as read by the caller.
	 * @param bool                 $exposeAssigneeName Whether the portal shows the handler's name.
	 *
	 * @return array<string, mixed>|null The resident's view, or null for a non-request.
	 *
	 * @spec openspec/specs/resident-view-preview/spec.md#requirement-a-request-ticket-previews-the-residents-view-req-rvp-001
	 */
	public function previewDetail(array $ticket, bool $exposeAssigneeName): ?array {
		if ($this->isRequest(ticket: $ticket) === false) {
			return null;
		}

		return $this->presentDetail(request: $ticket, exposeAssigneeName: $exposeAssigneeName);
	}//end previewDetail()

	/**
	 * List the account's own + delegated-submit requests, newest-first,
	 * paginated. Notes are summarised out of the list (detail only).
	 *
	 * @param array<string, mixed> $account The authenticated account.
	 * @param int $page The 1-based page.
	 * @param int $perPage The page size.
	 *
	 * @return array{total: int, page: int, perPage: int, items: array<int, array<string, mixed>>}
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function getForAccount(array $account, int $page = 1, int $perPage = 10): array {
		$page = max(1, $page);
		$perPage = min(100, max(1, $perPage));

		$resolved = $this->scope->resolve($account, 'submit-requests');
		$rows = [];
		foreach ($this->reader->findAll(self::SCHEMA, ['ticketType' => self::TICKET_TYPE]) as $request) {
			if ($this->visibleTo(resolved: $resolved, request: $request) === false) {
				continue;
			}

			$rows[] = $this->presentSummary(request: $request);
		}

		usort(
			$rows,
			static fn (array $left, array $right): int => strcmp((string)$right['date'], (string)$left['date'])
		);

		$total = count($rows);
		return [
			'total' => $total,
			'page' => $page,
			'perPage' => $perPage,
			'items' => array_slice($rows, (($page - 1) * $perPage), $perPage),
		];
	}//end getForAccount()

	/**
	 * Read one request's customer-safe detail (internal notes stripped), or
	 * null when the request is not visible to the account.
	 *
	 * @param array<string, mixed> $account The authenticated account.
	 * @param string $requestId The request id.
	 * @param bool $exposeAssigneeName Whether the tenant exposes assignees.
	 *
	 * @return array<string, mixed>|null The detail, or null.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function getDetailForAccount(array $account, string $requestId, bool $exposeAssigneeName): ?array {
		$request = $this->reader->find(self::SCHEMA, $requestId);
		if ($request === null || $this->isRequest(ticket: $request) === false) {
			return null;
		}

		$resolved = $this->scope->resolve($account, 'submit-requests');
		if ($this->visibleTo(resolved: $resolved, request: $request) === false) {
			return null;
		}

		return $this->presentDetail(request: $request, exposeAssigneeName: $exposeAssigneeName);
	}//end getDetailForAccount()

	/**
	 * Submit a new request from the portal.
	 *
	 * @param array<string, mixed> $account The authenticated account.
	 * @param string $tenantId The tenant id.
	 * @param string $subject The request subject.
	 * @param string $body The request body.
	 * @param array<int, array<string,mixed>> $attachments Attachment descriptors ({id, size}).
	 * @param string $categoryId The chosen category id.
	 *
	 * @return array<string, mixed> The created request summary + ETA.
	 *
	 * @throws PortalException On validation / rate-limit failure.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function submit(
		array $account,
		string $tenantId,
		string $subject,
		string $body,
		array $attachments,
		string $categoryId,
	): array {
		$subject = trim($subject);
		$body = trim($body);
		if ($subject === '' || $body === '') {
			throw new PortalException(Http::STATUS_BAD_REQUEST, 'missingFields', 'Onderwerp en bericht zijn verplicht.');
		}

		$accountId = (string)$this->idOf(object: $account);
		$this->assertWithinRateLimit(accountId: $accountId);
		$this->assertAttachmentsWithinLimit(attachments: $attachments);
		$this->assertCategoryExposed(categoryId: $categoryId);

		$contactId = $this->firstId(value: ($account['linkedContactId'] ?? null));
		$clientId = $this->firstId(value: ($account['linkedOrganisationId'] ?? null));

		$attachmentIds = [];
		foreach ($attachments as $attachment) {
			if (isset($attachment['id']) === true) {
				$attachmentIds[] = (string)$attachment['id'];
			}
		}

		$record = [
			'ticketType' => self::TICKET_TYPE,
			'title' => $subject,
			'description' => $body,
			'contact' => $contactId,
			'client' => $clientId,
			'category' => $categoryId,
			'status' => 'new',
			'channel' => 'portal',
			'reporterAccountId' => $accountId,
			'occurredAt' => $this->time->getDateTime()->format(DATE_ATOM),
			'attachmentIds' => $attachmentIds,
		];

		$saved = $this->reader->save(self::SCHEMA, $record);
		$requestId = (string)$this->idOf(object: $saved);

		$this->dispatchSubmitted(requestId: $requestId, tenantId: $tenantId);
		$this->audit->log(
			$accountId,
			$tenantId,
			'request-submit',
			'success',
			[
				'targetObjectType' => 'request',
				'targetObjectId' => $requestId,
			]
		);

		return [
			'requestId' => $requestId,
			'status' => 'new',
			'estimatedResponseTime' => '24 uur',
		];
	}//end submit()

	/**
	 * Add a customer reply note to a visible request, unpausing it when it was
	 * awaiting the customer.
	 *
	 * @param array<string, mixed> $account The authenticated account.
	 * @param string $tenantId The tenant id.
	 * @param string $requestId The request id.
	 * @param string $message The reply text.
	 *
	 * @return array<string, mixed> The updated request detail.
	 *
	 * @throws PortalException When the request is not visible or message empty.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function addReply(array $account, string $tenantId, string $requestId, string $message): array {
		$message = trim($message);
		if ($message === '') {
			throw new PortalException(Http::STATUS_BAD_REQUEST, 'missingFields', 'Bericht is verplicht.');
		}

		$request = $this->reader->find(self::SCHEMA, $requestId);
		$resolved = $this->scope->resolve($account, 'submit-requests');
		if ($request === null
			|| $this->isRequest(ticket: $request) === false
			|| $this->visibleTo(resolved: $resolved, request: $request) === false
		) {
			throw new PortalException(Http::STATUS_NOT_FOUND, 'notFound', 'Niet gevonden.');
		}

		// Replies go to their own list. The ticket's `notes` is an internal
		// string and is never read or written here.
		$replies = [];
		if (is_array($request['portalReplies'] ?? null) === true) {
			$replies = array_values($request['portalReplies']);
		}

		$replies[] = [
			'message' => $message,
			'createdAt' => $this->time->getDateTime()->format(DATE_ATOM),
		];
		$request['portalReplies'] = $replies;

		if (($request['status'] ?? null) === self::STATUS_AWAITING_CUSTOMER) {
			$request['status'] = self::STATUS_IN_PROGRESS;
		}

		$saved = $this->reader->save(self::SCHEMA, $request, $requestId);
		$this->audit->log(
			(string)$this->idOf(object: $account),
			$tenantId,
			'request-reply',
			'success',
			[
				'targetObjectType' => 'request',
				'targetObjectId' => $requestId,
			]
		);

		return $this->presentDetail(request: $saved, exposeAssigneeName: false);
	}//end addReply()

	/**
	 * Whether a request is visible to the resolved scope (own contact/client or
	 * delegated under submit-requests).
	 *
	 * @param array<string, mixed> $resolved The resolved scope.
	 * @param array<string, mixed> $request The request object.
	 *
	 * @return bool True when visible.
	 */
	private function visibleTo(array $resolved, array $request): bool {
		$contactId = $this->readId(value: ($request['contact'] ?? null));
		$clientId = $this->readId(value: ($request['client'] ?? null));
		return $this->scope->classify(resolved: $resolved, contactId: $contactId, clientId: $clientId) !== false;
	}//end visibleTo()

	/**
	 * Enforce the per-account submission rate limit.
	 *
	 * @param string $accountId The account id.
	 *
	 * @return void
	 *
	 * @throws PortalException When the limit is exceeded.
	 */
	private function assertWithinRateLimit(string $accountId): void {
		$cutoff = $this->time->getTime() - (self::RATE_WINDOW_MINUTES * 60);
		$recent = 0;
		$filters = ['ticketType' => self::TICKET_TYPE, 'reporterAccountId' => $accountId];
		foreach ($this->reader->findAll(self::SCHEMA, $filters) as $request) {
			$timestamp = strtotime((string)($request['occurredAt'] ?? ''));
			if ($timestamp !== false && $timestamp >= $cutoff) {
				$recent++;
			}
		}

		if ($recent >= self::RATE_LIMIT) {
			throw new PortalException(
				Http::STATUS_TOO_MANY_REQUESTS,
				'rateLimited',
				'Wacht alstublieft 60 minuten voordat u een nieuw verzoek indient.'
			);
		}
	}//end assertWithinRateLimit()

	/**
	 * Reject any attachment over the size limit.
	 *
	 * @param array<int, array<string, mixed>> $attachments The attachment descriptors.
	 *
	 * @return void
	 *
	 * @throws PortalException When a file is too large.
	 */
	private function assertAttachmentsWithinLimit(array $attachments): void {
		foreach ($attachments as $attachment) {
			$size = (int)($attachment['size'] ?? 0);
			if ($size > self::MAX_ATTACHMENT_BYTES) {
				throw new PortalException(
					Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
					'fileTooLarge',
					sprintf(
						'Bestand mag maximaal 25 MB zijn. Dit bestand is %d MB.',
						(int)ceil($size / 1024 / 1024)
					)
				);
			}
		}
	}//end assertAttachmentsWithinLimit()

	/**
	 * Reject a category that is not customer-exposed.
	 *
	 * A category is acceptable when no category catalogue is configured (open
	 * intake) or when the catalogue marks it exposeToCustomer: true. An
	 * internal-only category is rejected with 422.
	 *
	 * @param string $categoryId The chosen category id.
	 *
	 * @return void
	 *
	 * @throws PortalException When the category is internal-only.
	 */
	private function assertCategoryExposed(string $categoryId): void {
		if ($this->reader->hasSchema(schemaKey: 'requestCategory') === false) {
			return;
		}

		$category = $this->reader->find('requestCategory', $categoryId);
		if ($category === null || ($category['exposeToCustomer'] ?? false) !== true) {
			throw new PortalException(
				Http::STATUS_UNPROCESSABLE_ENTITY,
				'categoryNotAvailable',
				'Deze categorie is niet beschikbaar.'
			);
		}
	}//end assertCategoryExposed()

	/**
	 * Dispatch a generic request-submitted event for the SLA engine to pick up.
	 *
	 * The event is emitted through the shared dispatcher so the SLA engine /
	 * omnichannel inbox can react without the portal depending on them; a
	 * missing listener is a no-op.
	 *
	 * @param string $requestId The created request id.
	 * @param string $tenantId The tenant id.
	 *
	 * @return void
	 */
	private function dispatchSubmitted(string $requestId, string $tenantId): void {
		try {
			$this->dispatcher->dispatchTyped(
				new PortalRequestSubmittedEvent(requestId: $requestId, tenantId: $tenantId)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq portal: SLA dispatch failed',
				['requestId' => $requestId, 'exception' => $e->getMessage()]
			);
		}
	}//end dispatchSubmitted()

	/**
	 * Summarise a request for the list view.
	 *
	 * @param array<string, mixed> $request The request object.
	 *
	 * @return array<string, mixed> The summary row.
	 */
	private function presentSummary(array $request): array {
		return [
			'id' => $this->idOf(object: $request),
			'number' => ($request['caseReference'] ?? $request['reference'] ?? null),
			'subject' => ($request['title'] ?? null),
			'category' => $this->readId(value: ($request['category'] ?? null)),
			'status' => ($request['status'] ?? null),
			'date' => ($request['occurredAt'] ?? null),
		];
	}//end presentSummary()

	/**
	 * Present a request's customer-safe detail (internal notes removed; assignee
	 * hidden unless the tenant exposes it).
	 *
	 * @param array<string, mixed> $request The request object.
	 * @param bool $exposeAssigneeName Whether to expose assignee.
	 *
	 * @return array<string, mixed> The detail.
	 */
	private function presentDetail(array $request, bool $exposeAssigneeName): array {
		$detail = $this->presentSummary(request: $request);
		$detail['body'] = ($request['description'] ?? null);
		$detail['notes'] = $this->customerNotes(request: $request);
		$detail['canReply'] = ($request['status'] ?? null) === self::STATUS_AWAITING_CUSTOMER;

		$detail['assigneeHidden'] = true;
		if ($exposeAssigneeName === true) {
			$detail['assignee'] = ($request['assignee'] ?? null);
			unset($detail['assigneeHidden']);
		}

		return $detail;
	}//end presentDetail()

	/**
	 * What a resident reads of the conversation, oldest first: the handler's
	 * message to the customer (`customerMessage`) and the resident's own
	 * portal replies (`portalReplies`). The ticket's `notes` string is
	 * internal and is never shown.
	 *
	 * @param array<string, mixed> $request The request ticket.
	 *
	 * @return array<int, array<string, mixed>> The customer-visible notes.
	 */
	private function customerNotes(array $request): array {
		$kept = [];
		$handlerMessage = trim((string)($request['customerMessage'] ?? ''));
		if ($handlerMessage !== '') {
			$kept[] = [
				'author' => 'handler',
				'message' => $handlerMessage,
				'createdAt' => ($request['@self']['updated'] ?? null),
			];
		}

		$replies = [];
		if (is_array($request['portalReplies'] ?? null) === true) {
			$replies = $request['portalReplies'];
		}

		foreach ($replies as $reply) {
			if (is_array($reply) === true && trim((string)($reply['message'] ?? '')) !== '') {
				$kept[] = [
					'author' => 'customer',
					'message' => $reply['message'],
					'createdAt' => ($reply['createdAt'] ?? null),
				];
			}
		}

		usort(
			$kept,
			static fn (array $left, array $right): int => strcmp((string)$left['createdAt'], (string)$right['createdAt'])
		);

		return $kept;
	}//end customerNotes()

	/**
	 * Whether a ticket is a request (and not a complaint or a logged interaction).
	 *
	 * @param array<string, mixed> $ticket The ticket.
	 *
	 * @return bool True for a request ticket.
	 */
	private function isRequest(array $ticket): bool {
		return ($ticket['ticketType'] ?? null) === self::TICKET_TYPE;
	}//end isRequest()

	/**
	 * Read a possibly-nested id value into a string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The id.
	 */
	private function readId(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_array($value) === true) {
			$id = ($value['id'] ?? $value['uuid'] ?? null);
			if ($id === null) {
				return null;
			}

			return (string)$id;
		}

		return (string)$value;
	}//end readId()

	/**
	 * Read the first id from a scalar or list id value.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The first id.
	 */
	private function firstId(mixed $value): ?string {
		if (is_array($value) === true) {
			foreach ($value as $entry) {
				$id = $this->readId(value: $entry);
				if ($id !== null) {
					return $id;
				}
			}

			return null;
		}

		return $this->readId(value: $value);
	}//end firstId()

	/**
	 * Extract the stable id from a portal/main object array.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string|null The id.
	 */
	private function idOf(array $object): ?string {
		$self = ($object['@self'] ?? null);
		if (is_array($self) === true) {
			$id = ($self['id'] ?? $self['uuid'] ?? null);
			if ($id !== null) {
				return (string)$id;
			}
		}

		$id = ($object['id'] ?? $object['uuid'] ?? null);
		if ($id === null) {
			return null;
		}

		return (string)$id;
	}//end idOf()
}//end class
