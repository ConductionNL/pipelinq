<?php

/**
 * Pipelinq MailingListService.
 *
 * Owns the `mailingList` object: the opt-in container a person joins, as
 * opposed to a Segment, which is a saved query the tenant runs over people
 * it already holds. The list carries the sender identity and the postal
 * footer every mailing to it must print, and its `optInMode` decides how
 * someone may get on it.
 *
 * Every OpenRegister call passes `_rbac: false` and `_multitenancy: false`.
 * The public subscribe, confirm and unsubscribe endpoints run with no
 * session at all, so a call left on the defaults resolves to Anonymous and
 * silently returns nothing rather than failing.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;

/**
 * MailingListService — CRUD and validation for mailing lists.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
 */
class MailingListService {
	/**
	 * Opt-in mode requiring a confirmed link before a mailing is sent.
	 *
	 * @var string
	 */
	public const OPT_IN_DOUBLE = 'double';

	/**
	 * Opt-in mode additionally permitting an existing customer to be
	 * imported with a recorded ground.
	 *
	 * @var string
	 */
	public const OPT_IN_SOFT = 'soft';

	/**
	 * Default MailingList schema slug, matching the register fragment.
	 *
	 * @var string
	 */
	private const DEFAULT_MAILING_LIST_SCHEMA_SLUG = 'mailingList';

	/**
	 * Opt-in modes a list may declare.
	 *
	 * @var array<int, string>
	 */
	private const OPT_IN_MODES = [self::OPT_IN_DOUBLE, self::OPT_IN_SOFT];

	/**
	 * Statuses a list may hold.
	 *
	 * @var array<int, string>
	 */
	private const STATUSES = ['active', 'paused', 'archived'];

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped, session-free object access.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function __construct(
		private ListObjectStore $store,
	) {
	}//end __construct()

	/**
	 * List mailing lists with a pagination envelope.
	 *
	 * @param int $page 1-based page number (clamped to >= 1).
	 * @param int $limit Page size (clamped 1..100).
	 *
	 * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function listMailingLists(int $page, int $limit): array {
		$page = max(1, $page);
		$limit = min(100, max(1, $limit));
		$all = $this->loadAll();
		$total = count($all);

		return [
			'data' => array_slice($all, (($page - 1) * $limit), $limit),
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => $total,
				'pages' => $this->computePages(total: $total, limit: $limit),
			],
		];
	}//end listMailingLists()

	/**
	 * Fetch one mailing list by UUID or slug.
	 *
	 * @param string $listId MailingList UUID or slug.
	 *
	 * @return array<string, mixed>|null The list payload, or null when it
	 *                                   does not exist.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function getMailingListById(string $listId): ?array {
		return $this->store->find(schemaSlug: $this->getMailingListSchemaSlug(), id: $listId);
	}//end getMailingListById()

	/**
	 * Create a mailing list after validation.
	 *
	 * `createdBy` is stamped from the authenticated user id; a
	 * client-supplied value is ignored (ADR-005).
	 *
	 * @param array<string, mixed> $payload Inbound payload.
	 * @param string $createdByUid Authenticated user id.
	 *
	 * @return array{list?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function createMailingList(array $payload, string $createdByUid): array {
		$error = $this->validatePayload(payload: $payload);
		if ($error !== null) {
			return ['error' => $error];
		}

		$now = $this->nowIso();
		$record = [
			'name' => trim((string)$payload['name']),
			'description' => (string)($payload['description'] ?? ''),
			'optInMode' => $this->normaliseOptInMode(value: ($payload['optInMode'] ?? '')),
			'senderName' => trim((string)$payload['senderName']),
			'senderEmail' => trim((string)$payload['senderEmail']),
			'replyTo' => trim((string)($payload['replyTo'] ?? '')),
			'publicSignup' => (bool)($payload['publicSignup'] ?? false),
			'footerAddress' => trim((string)$payload['footerAddress']),
			'status' => $this->normaliseStatus(value: ($payload['status'] ?? '')),
			'createdBy' => $createdByUid,
			'createdAt' => $now,
			'updatedAt' => $now,
		];

		$saved = $this->save(payload: $record);
		if ($saved === null) {
			return ['error' => 'The mailing list could not be saved'];
		}

		return ['list' => $saved];
	}//end createMailingList()

	/**
	 * Patch a mailing list's editable fields.
	 *
	 * Identity fields (`createdBy`, `createdAt`) are never taken from the
	 * request. An unknown key is dropped rather than merged.
	 *
	 * @param string $listId MailingList UUID or slug.
	 * @param array<string, mixed> $patch Fields to change.
	 *
	 * @return array{list?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function updateMailingList(string $listId, array $patch): array {
		$existing = $this->getMailingListById(listId: $listId);
		if ($existing === null) {
			return ['error' => 'Not found'];
		}

		$merged = $existing;
		foreach (['name', 'description', 'senderName', 'senderEmail', 'replyTo', 'footerAddress'] as $key) {
			if (isset($patch[$key]) === true) {
				$merged[$key] = trim((string)$patch[$key]);
			}
		}

		if (isset($patch['publicSignup']) === true) {
			$merged['publicSignup'] = (bool)$patch['publicSignup'];
		}

		if (isset($patch['optInMode']) === true) {
			$merged['optInMode'] = $this->normaliseOptInMode(value: $patch['optInMode']);
		}

		if (isset($patch['status']) === true) {
			$merged['status'] = $this->normaliseStatus(value: $patch['status']);
		}

		$error = $this->validatePayload(payload: $merged);
		if ($error !== null) {
			return ['error' => $error];
		}

		$merged['updatedAt'] = $this->nowIso();

		$saved = $this->save(payload: $merged, id: $this->extractObjectId(payload: $existing));
		if ($saved === null) {
			return ['error' => 'The mailing list could not be saved'];
		}

		return ['list' => $saved];
	}//end updateMailingList()

	/**
	 * Whether a list currently accepts public signup.
	 *
	 * An archived or paused list refuses, so a stale embed snippet on an
	 * old page cannot keep filling a retired list.
	 *
	 * @param array<string, mixed>|null $list The list payload.
	 *
	 * @return bool True when the public subscribe endpoint may accept.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function acceptsPublicSignup(?array $list): bool {
		if ($list === null) {
			return false;
		}

		if ((bool)($list['publicSignup'] ?? false) === false) {
			return false;
		}

		return (string)($list['status'] ?? 'active') === 'active';
	}//end acceptsPublicSignup()

	/**
	 * Project a list down to what a subscriber may see.
	 *
	 * The confirmation and preference pages render this. It carries no
	 * sender address, no footer and no internal identifiers beyond the id
	 * the page already holds in its token.
	 *
	 * @param array<string, mixed> $list The full list payload.
	 *
	 * @return array{id: string, name: string, description: string}
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function publicProjection(array $list): array {
		return [
			'id' => $this->extractObjectId(payload: $list),
			'name' => (string)($list['name'] ?? ''),
			'description' => (string)($list['description'] ?? ''),
		];
	}//end publicProjection()

	/**
	 * Reject a payload that cannot be stored as a mailing list.
	 *
	 * Returns a human-readable message naming the first missing or
	 * unusable field, or null when the payload is complete. The postal
	 * footer and the sender address are required because every mailing to
	 * the list has to carry them, and a list that cannot produce a
	 * compliant footer should never accept a subscriber.
	 *
	 * @param array<string, mixed> $payload The payload to check.
	 *
	 * @return string|null Error message, or null when valid.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function validatePayload(array $payload): ?string {
		if (trim((string)($payload['name'] ?? '')) === '') {
			return 'A mailing list needs a name';
		}

		if (trim((string)($payload['senderName'] ?? '')) === '') {
			return 'A mailing list needs a sender name';
		}

		$senderEmail = trim((string)($payload['senderEmail'] ?? ''));
		if (filter_var($senderEmail, FILTER_VALIDATE_EMAIL) === false) {
			return 'A mailing list needs a valid sender address';
		}

		$replyTo = trim((string)($payload['replyTo'] ?? ''));
		if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
			return 'The reply-to address is not a valid email address';
		}

		if (trim((string)($payload['footerAddress'] ?? '')) === '') {
			return 'A mailing list needs a postal address for the footer';
		}

		return null;
	}//end validatePayload()

	/**
	 * Resolve the MailingList schema slug from app config.
	 *
	 * @return string Schema slug.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function getMailingListSchemaSlug(): string {
		return $this->store->schemaSlug(
			configKey: 'mailing_list_schema',
			default: self::DEFAULT_MAILING_LIST_SCHEMA_SLUG,
		);
	}//end getMailingListSchemaSlug()

	/**
	 * Normalise an inbound opt-in mode, defaulting to double.
	 *
	 * Double opt-in is the default because it is the only mode that is
	 * lawful for a stranger, and a typo must not silently downgrade a list
	 * to the softer one.
	 *
	 * @param mixed $value The inbound value.
	 *
	 * @return string One of the declared modes.
	 */
	private function normaliseOptInMode(mixed $value): string {
		$mode = strtolower(trim((string)$value));
		if (in_array($mode, self::OPT_IN_MODES, true) === true) {
			return $mode;
		}

		return self::OPT_IN_DOUBLE;
	}//end normaliseOptInMode()

	/**
	 * Normalise an inbound status, defaulting to active.
	 *
	 * @param mixed $value The inbound value.
	 *
	 * @return string One of the declared statuses.
	 */
	private function normaliseStatus(mixed $value): string {
		$status = strtolower(trim((string)$value));
		if (in_array($status, self::STATUSES, true) === true) {
			return $status;
		}

		return 'active';
	}//end normaliseStatus()

	/**
	 * Load every mailing list through ObjectService.
	 *
	 * @return array<int, array<string, mixed>> Plain payloads.
	 */
	private function loadAll(): array {
		return $this->store->findAll(schemaSlug: $this->getMailingListSchemaSlug());
	}//end loadAll()

	/**
	 * Persist a mailing list through ObjectService.
	 *
	 * @param array<string, mixed> $payload The payload to store.
	 * @param string|null $id Existing id when updating.
	 *
	 * @return array<string, mixed>|null Saved row, or null on failure.
	 */
	private function save(array $payload, ?string $id = null): ?array {
		return $this->store->save(
			schemaSlug: $this->getMailingListSchemaSlug(),
			payload: $payload,
			id: $id,
		);
	}//end save()

	/**
	 * Compute the page count from a total and a page size.
	 *
	 * @param int $total Total row count.
	 * @param int $limit Page size.
	 *
	 * @return int Page count, 0 when the total is 0.
	 */
	private function computePages(int $total, int $limit): int {
		if ($total <= 0 || $limit <= 0) {
			return 0;
		}

		return (int)ceil($total / $limit);
	}//end computePages()

	/**
	 * Current UTC timestamp in the format the schemas declare.
	 *
	 * @return string ISO-8601 timestamp.
	 */
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}//end nowIso()



	/**
	 * Extract the canonical id from an OpenRegister entity payload.
	 *
	 * @param array<string, mixed> $payload Entity payload.
	 *
	 * @return string Identifier, or an empty string.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function extractObjectId(array $payload): string {
		return $this->store->idOf(payload: $payload);
	}//end extractObjectId()
}//end class
