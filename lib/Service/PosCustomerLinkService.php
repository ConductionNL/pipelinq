<?php

/**
 * Pipelinq PosCustomerLinkService.
 *
 * Server-authoritative service that attaches a pipelinq contact (the
 * "customer") to a POS transaction, fetches the customer's transaction
 * history at the register, captures marketing-consent opt-in, and syncs
 * that consent onto the linked contact while respecting the doNotContact
 * privacy flag.
 *
 * Per ADR-004 and the [[reference_contact-is-nextcloud-entity]] convention,
 * this service never invents a separate customer schema — it reuses the
 * existing pipelinq `contact` schema (synced via ContactSyncService to the
 * Nextcloud addressbook through `contactsUid`). All lookups, history reads
 * and consent writes flow through the OR ObjectService magic table.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-002
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-003
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-004
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-005
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-006
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Pipelinq contact lookup, attachment, history and consent sync for the POS.
 *
 * The service is intentionally a thin orchestration layer over OR's
 * ObjectService — every storage call goes through the documented OR API
 * (`find` / `findAll` / `saveObject`); no custom SQL, no foreign-app
 * controller calls, and no service-token HTTP loop back into ourselves.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Aggregates the legitimate
 *  collaborators a POS-customer service needs: OR container, app config,
 *  PosTransactionService (for transaction fetch + persist), logger.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The public surface is the
 *  six checkout-flow primitives (searchCustomers, getCustomer, attachCustomer,
 *  detachCustomer, getCustomerHistory, syncConsent) plus the on-account
 *  validator — all single-purpose and unit-tested.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class is intentionally
 *  cohesive: six checkout primitives + four admin-config readers + small
 *  decoration / persistence helpers. Splitting it would scatter one
 *  transactional concern (POS ↔ contact link) across several classes
 *  without reducing real complexity.
 *
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001
 */
class PosCustomerLinkService {
	/**
	 * Default search-result limit when the caller does not specify one.
	 *
	 * @var int
	 */
	public const DEFAULT_SEARCH_LIMIT = 20;

	/**
	 * Default purchase-history depth (configurable via admin settings).
	 *
	 * @var int
	 */
	public const DEFAULT_HISTORY_LIMIT = 10;

	/**
	 * Hard upper bounds defensively applied to caller-supplied limits.
	 *
	 * @var int
	 */
	private const MAX_SEARCH_LIMIT = 100;

	/**
	 * Hard upper bound on history depth (prevents register-slow scans).
	 *
	 * @var int
	 */
	private const MAX_HISTORY_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (OR lookup).
	 * @param IAppConfig $appConfig The app config (register + schema IDs + admin settings).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Search the pipelinq contact register for customers matching a query.
	 *
	 * Filters by admin-configurable search fields (name, email, phone). The
	 * query is matched as a case-insensitive substring against each enabled
	 * field; the union of matches is returned. Results are decorated with
	 * `doNotContact` (privacy flag) and `marketingConsent` so the UI can
	 * surface the indicator described in REQ-PCL-007 Scenario 1.
	 *
	 * @param string $query The search query (>= 2 chars).
	 * @param int $limit Max results (defaults to DEFAULT_SEARCH_LIMIT).
	 *
	 * @return array<int, array<string, mixed>> Decorated contact rows.
	 *
	 * @throws OCSBadRequestException When the query is too short.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-006
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-007
	 */
	public function searchCustomers(string $query, int $limit = self::DEFAULT_SEARCH_LIMIT): array {
		$needle = trim($query);
		if (mb_strlen($needle) < 2) {
			throw new OCSBadRequestException('Zoekopdracht moet minimaal 2 tekens zijn.');
		}

		$cap = max(1, min(self::MAX_SEARCH_LIMIT, $limit));

		[$register, $schema] = $this->configContact();
		$fields = $this->enabledSearchFields();

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $schema],
					'limit' => 200,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: contact search failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$matches = [];
		$haystack = mb_strtolower($needle);
		if (is_array($rows) === false) {
			$rows = [];
		}

		$rows = array_values($rows);

		foreach ($rows as $row) {
			$data = $this->toArray(object: $row);
			if ($this->rowMatches(data: $data, query: $haystack, fields: $fields) === false) {
				continue;
			}

			$matches[] = $this->decorateContact(data: $data);
			if (count($matches) >= $cap) {
				break;
			}
		}

		$this->logger->info(
			'Pipelinq: POS customer search',
			[
				'query' => $needle,
				'count' => count($matches),
				'fields' => $fields,
			]
		);

		return $matches;
	}//end searchCustomers()

	/**
	 * Fetch a single contact by UUID, decorated with privacy flags.
	 *
	 * @param string $contactUuid The contact UUID.
	 *
	 * @return array<string, mixed> The decorated contact data.
	 *
	 * @throws OCSNotFoundException When the contact does not exist in the pipelinq register.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-002
	 */
	public function getCustomer(string $contactUuid): array {
		[$register, $schema] = $this->configContact();
		$data = $this->fetchObject(id: $contactUuid, register: $register, schema: $schema);
		if ($data === null) {
			throw new OCSNotFoundException('Klant niet gevonden.');
		}

		return $this->decorateContact(data: $data);
	}//end getCustomer()

	/**
	 * Attach a customer (and optionally capture marketing consent) to a draft transaction.
	 *
	 * Validates the contact exists, that the transaction is mutable (draft /
	 * parked — the lifecycle owns the rest), and writes the customer +
	 * marketingConsent fields. Consent sync to the linked contact is fired
	 * fire-and-forget after the transaction save so a consent-PATCH failure
	 * never reverts the attachment.
	 *
	 * @param string $transactionId The POS transaction UUID.
	 * @param string $contactUuid The contact UUID to attach.
	 * @param bool $marketingConsent Whether the cashier captured opt-in.
	 *
	 * @return array<string, mixed> The updated transaction.
	 *
	 * @throws OCSNotFoundException When the transaction or contact is missing.
	 * @throws OCSBadRequestException When the transaction is not in a mutable state.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) marketingConsent is the
	 *  GDPR opt-in state captured by the same UI action that attaches the
	 *  customer; splitting it into a setter would force the controller to
	 *  make two round-trips for one logical write.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-002
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-004
	 */
	public function attachCustomer(
		string $transactionId,
		string $contactUuid,
		bool $marketingConsent = false,
	): array {
		$contact = $this->getCustomer(contactUuid: $contactUuid);
		$transaction = $this->fetchTransaction(id: $transactionId);

		$status = (string)($transaction['status'] ?? 'draft');
		if (in_array($status, ['draft', 'parked'], true) === false) {
			throw new OCSBadRequestException('Klant kan alleen aan een open transactie gekoppeld worden.');
		}

		$transaction['customer'] = $contactUuid;
		$transaction['marketingConsent'] = $marketingConsent;

		$syncStatus = '';
		if ($marketingConsent === true && $this->isSyncEnabled() === true) {
			$syncStatus = $this->syncConsent(contact: $contact, consent: true);
		}

		$transaction['consentSyncStatus'] = $syncStatus;

		$saved = $this->saveTransaction(id: $transactionId, transaction: $transaction);

		$this->logger->info(
			'Pipelinq: POS customer attached',
			[
				'transactionId' => $transactionId,
				'customer' => $contactUuid,
				'consent' => $marketingConsent,
				'consentSyncState' => $syncStatus,
			]
		);

		return $saved;
	}//end attachCustomer()

	/**
	 * Detach a customer from a draft / parked transaction.
	 *
	 * The corresponding contact is NOT mutated — detach is a local
	 * transaction-level operation only. Marketing consent stored on the
	 * transaction is reset to false because consent was given for that
	 * specific sale; the previously-captured value on the contact (if any)
	 * is preserved.
	 *
	 * @param string $transactionId The POS transaction UUID.
	 *
	 * @return array<string, mixed> The updated transaction.
	 *
	 * @throws OCSNotFoundException When the transaction is missing.
	 * @throws OCSBadRequestException When the transaction is not mutable.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-002
	 */
	public function detachCustomer(string $transactionId): array {
		$transaction = $this->fetchTransaction(id: $transactionId);

		$status = (string)($transaction['status'] ?? 'draft');
		if (in_array($status, ['draft', 'parked'], true) === false) {
			throw new OCSBadRequestException('Klant kan alleen van een open transactie ontkoppeld worden.');
		}

		$transaction['customer'] = null;
		$transaction['marketingConsent'] = false;
		$transaction['consentSyncStatus'] = '';

		return $this->saveTransaction(id: $transactionId, transaction: $transaction);
	}//end detachCustomer()

	/**
	 * Fetch the purchase history (most recent confirmed / settled / refunded
	 * transactions) for a customer.
	 *
	 * Drafts and parked carts are excluded because they are not real sales.
	 * The list is sorted descending by createdAt and capped at the
	 * admin-configured history depth (default 10, max 50).
	 *
	 * @param string $contactUuid The contact UUID.
	 * @param int $limit Max history rows (defaults to admin setting).
	 *
	 * @return array<int, array<string, mixed>> The history rows.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-003
	 */
	public function getCustomerHistory(string $contactUuid, int $limit = self::DEFAULT_HISTORY_LIMIT): array {
		$effectiveLimit = $this->historyDepth();
		if ($limit > 0) {
			$effectiveLimit = $limit;
		}

		$cap = max(1, min(self::MAX_HISTORY_LIMIT, $effectiveLimit));

		[$register, $schema] = $this->configTransaction();

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'customer' => $contactUuid,
					],
					'limit' => 200,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: POS customer history fetch failed',
				[
					'exception' => $e->getMessage(),
					'customer' => $contactUuid,
				]
			);
			return [];
		}//end try

		if (is_array($rows) === false) {
			$rows = [];
		}

		$rows = array_values($rows);

		$history = [];
		foreach ($rows as $row) {
			$data = $this->toArray(object: $row);
			$status = (string)($data['status'] ?? '');
			if (in_array($status, ['draft', 'parked'], true) === true) {
				continue;
			}

			$history[] = $this->summariseTransaction(data: $data);
		}

		usort(
			$history,
			static fn (array $a, array $b): int => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''))
		);

		return array_slice($history, 0, $cap);
	}//end getCustomerHistory()

	/**
	 * Sync the marketing-consent flag onto the linked pipelinq contact.
	 *
	 * Respects `doNotContact`: when the contact carries the privacy flag we
	 * skip the write and return 'skipped'. Failure is caught and returned as
	 * 'failed' so the calling transaction save can still commit (POS is the
	 * authoritative ledger).
	 *
	 * @param array<string, mixed> $contact The decorated contact data.
	 * @param bool $consent The consent value to write.
	 *
	 * @return string One of 'success', 'skipped', 'failed'.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-004
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-007
	 */
	public function syncConsent(array $contact, bool $consent): string {
		$contactUuid = (string)($contact['id'] ?? $contact['uuid'] ?? '');
		if ($contactUuid === '') {
			return 'failed';
		}

		if (($contact['doNotContact'] ?? false) === true) {
			$this->logger->info(
				'Pipelinq: marketing-consent sync skipped (doNotContact set)',
				['contact' => $contactUuid]
			);
			return 'skipped';
		}

		[$register, $schema] = $this->configContact();

		$payload = $contact;
		unset($payload['@self'], $payload['doNotContactBadge']);
		$payload['marketingConsent'] = $consent;

		try {
			$this->getObjectService()->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $contactUuid
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: marketing-consent sync failed',
				[
					'contact' => $contactUuid,
					'exception' => $e->getMessage(),
				]
			);
			return 'failed';
		}

		$this->logger->info(
			'Pipelinq: marketing-consent synced to contact',
			[
				'contact' => $contactUuid,
				'consent' => $consent,
			]
		);

		return 'success';
	}//end syncConsent()

	/**
	 * Validate that an on-account transaction carries a linked customer.
	 *
	 * Called by the controller before settle (and by the on-account guard at
	 * confirm-time when settle = onAccount). Raises a 422 when the invariant
	 * is violated; otherwise returns silently.
	 *
	 * @param array<string, mixed> $transaction The transaction data.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException When tenderType is 'onAccount' and no customer is set.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-005
	 */
	public function assertOnAccountHasCustomer(array $transaction): void {
		$tender = (string)($transaction['tenderType'] ?? '');
		$customer = (string)($transaction['customer'] ?? '');

		if ($tender !== 'onAccount') {
			return;
		}

		// Admin can disable the invariant (REQ-PCL-006); when the toggle is
		// off the cashier can still ring up on-account sales without a linked
		// customer (e.g. for legacy / paper-ledger debtor flows).
		if ($this->requiresCustomerForOnAccount() === false) {
			return;
		}

		if ($customer === '') {
			throw new OCSBadRequestException(
				"Klant is verplicht voor 'op rekening' transacties."
			);
		}
	}//end assertOnAccountHasCustomer()

	/**
	 * Whether a contact row matches the search query across the enabled fields.
	 *
	 * @param array<string, mixed> $data The contact data.
	 * @param string $query The lower-cased query (already trimmed).
	 * @param array<int, string> $fields The enabled fields.
	 *
	 * @return bool Whether the row matches.
	 */
	private function rowMatches(array $data, string $query, array $fields): bool {
		foreach ($fields as $field) {
			$value = mb_strtolower((string)($data[$field] ?? ''));
			if ($value !== '' && str_contains($value, $query) === true) {
				return true;
			}
		}

		return false;
	}//end rowMatches()

	/**
	 * Decorate a contact row with the UI-facing flags (id, doNotContact, etc.).
	 *
	 * @param array<string, mixed> $data The raw contact data.
	 *
	 * @return array<string, mixed> The decorated row.
	 */
	private function decorateContact(array $data): array {
		$self = [];
		if (is_array($data['@self'] ?? null) === true) {
			$self = $data['@self'];
		}

		$doNotContact = (bool)($data['doNotContact'] ?? false);
		$badge = '';
		if ($doNotContact === true) {
			$badge = 'Niet benaderen';
		}

		return [
			'id' => (string)($data['id'] ?? $data['uuid'] ?? ($self['id'] ?? '')),
			'name' => (string)($data['name'] ?? ''),
			'email' => (string)($data['email'] ?? ''),
			'phone' => (string)($data['phone'] ?? ''),
			'role' => (string)($data['role'] ?? ''),
			'client' => (string)($data['client'] ?? ''),
			'contactsUid' => (string)($data['contactsUid'] ?? ''),
			'marketingConsent' => (bool)($data['marketingConsent'] ?? false),
			'doNotContact' => $doNotContact,
			'doNotContactBadge' => $badge,
		];
	}//end decorateContact()

	/**
	 * Summarise a transaction into the history-panel shape used by the UI.
	 *
	 * @param array<string, mixed> $data The raw transaction data.
	 *
	 * @return array<string, mixed> The summarised history row.
	 */
	private function summariseTransaction(array $data): array {
		$self = [];
		if (is_array($data['@self'] ?? null) === true) {
			$self = $data['@self'];
		}

		return [
			'id' => (string)($data['id'] ?? $data['uuid'] ?? ($self['id'] ?? '')),
			'reference' => (string)($data['reference'] ?? ''),
			'createdAt' => (string)($data['confirmedAt'] ?? $data['settledAt'] ?? ($self['created'] ?? '')),
			'total' => (float)($data['total'] ?? 0),
			'totalTax' => (float)($data['totalTax'] ?? 0),
			'tenderType' => (string)($data['tenderType'] ?? 'cash'),
			'status' => (string)($data['status'] ?? ''),
			'itemCount' => (int)($data['itemCount'] ?? 0),
		];
	}//end summariseTransaction()

	/**
	 * Admin-configurable search fields for the lookup modal.
	 *
	 * Reads `customerSearchFields` from app config (comma-separated). Defaults
	 * to name + email + phone when unset or empty.
	 *
	 * @return array<int, string> The enabled fields.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-006
	 */
	public function enabledSearchFields(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'customerSearchFields', 'name,email,phone');
		$parts = array_filter(array_map('trim', explode(',', $raw)));

		$allowed = ['name', 'email', 'phone'];
		$filtered = array_values(array_intersect($parts, $allowed));

		if (count($filtered) === 0) {
			return $allowed;
		}

		return $filtered;
	}//end enabledSearchFields()

	/**
	 * Admin-configurable history depth.
	 *
	 * Reads `customerHistoryDepth` from app config. Defaults to 10. Capped at
	 * MAX_HISTORY_LIMIT defensively.
	 *
	 * @return int The history depth.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-006
	 */
	public function historyDepth(): int {
		$value = (int)$this->appConfig->getValueString(
			Application::APP_ID,
			'customerHistoryDepth',
			(string)self::DEFAULT_HISTORY_LIMIT
		);
		if ($value <= 0) {
			return self::DEFAULT_HISTORY_LIMIT;
		}

		return min(self::MAX_HISTORY_LIMIT, $value);
	}//end historyDepth()

	/**
	 * Whether the admin has enabled consent sync to pipelinq contacts.
	 *
	 * Defaults to true. When false, the consent is recorded on the transaction
	 * but the contact is not mutated (transaction-local consent).
	 *
	 * @return bool Whether sync is enabled.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-006
	 */
	public function isSyncEnabled(): bool {
		$value = $this->appConfig->getValueString(Application::APP_ID, 'enablePipelinqSync', 'true');
		return strtolower($value) !== 'false';
	}//end isSyncEnabled()

	/**
	 * Whether on-account tenders require a linked customer.
	 *
	 * Default: true. Surfaces as REQ-PCL-006 Scenario 1 admin toggle.
	 *
	 * @return bool Whether the invariant is enforced.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-006
	 */
	public function requiresCustomerForOnAccount(): bool {
		$value = $this->appConfig->getValueString(Application::APP_ID, 'requireCustomerForOnAccount', 'true');
		return strtolower($value) !== 'false';
	}//end requiresCustomerForOnAccount()

	/**
	 * Fetch a transaction by UUID via OR ObjectService.
	 *
	 * @param string $id The transaction UUID.
	 *
	 * @return array<string, mixed> The transaction data.
	 *
	 * @throws OCSNotFoundException When the transaction is missing.
	 */
	private function fetchTransaction(string $id): array {
		[$register, $schema] = $this->configTransaction();
		$data = $this->fetchObject(id: $id, register: $register, schema: $schema);
		if ($data === null) {
			throw new OCSNotFoundException('Transactie niet gevonden.');
		}

		return $data;
	}//end fetchTransaction()

	/**
	 * Persist a transaction via OR ObjectService.
	 *
	 * @param string $id The transaction UUID.
	 * @param array<string, mixed> $transaction The transaction payload.
	 *
	 * @return array<string, mixed> The saved transaction.
	 */
	private function saveTransaction(string $id, array $transaction): array {
		[$register, $schema] = $this->configTransaction();

		unset($transaction['@self']);

		$saved = $this->getObjectService()->saveObject(
			object: $transaction,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}//end saveTransaction()

	/**
	 * Try to fetch a single OR object; return null on absence / error.
	 *
	 * @param string $id The object UUID.
	 * @param string $register The register ID.
	 * @param string $schema The schema ID.
	 *
	 * @return array<string, mixed>|null The object data, or null on miss.
	 */
	private function fetchObject(string $id, string $register, string $schema): ?array {
		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}//end fetchObject()

	/**
	 * Resolve the (register, contact-schema) pair from app config.
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @throws OCSNotFoundException When config is missing.
	 */
	private function configContact(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Contact register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}//end configContact()

	/**
	 * Resolve the (register, posTransaction-schema) pair from app config.
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @throws OCSNotFoundException When config is missing.
	 */
	private function configTransaction(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'posTransaction_schema', '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}//end configTransaction()

	/**
	 * Normalise an OR entity into a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The plain array.
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}//end toArray()

	/**
	 * Resolve the OR ObjectService from the container.
	 *
	 * @return object The OR ObjectService.
	 *
	 * @throws RuntimeException When OR is unavailable.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
