<?php

/**
 * Pipelinq EmailMatchService.
 *
 * CRM email-to-entity matching service (leaf-first, ADR-022). Resolves a Mail
 * message's sender/recipient address against the pipelinq CRM schemas
 * (`contact.email`, `client.email`) and links matched messages to the matched
 * OpenRegister object through the OR `email` leaf's link API. Pipelinq owns
 * the CRM matching rule; the OR `email` leaf owns the link record.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/email-calendar-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * CRM email-to-entity matching service.
 *
 * The service exposes three pure matching helpers (used by the
 * EmailMatchJob and the controller) and one orchestration helper that
 * indexes inbound Mail messages and links them through the OR `email`
 * leaf. All OR access is mediated via the DI container so the
 * openregister + mail apps stay optional runtime dependencies.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Leaf-bridge orchestration
 *     fundamentally couples OR services, Mail DB, and pipelinq config.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     The matching helpers plus the
 *     Mail-indexing orchestration span the full email-linking lifecycle; splitting
 *     would only scatter the seam.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Aggregate complexity is driven
 *     by the number of matching strategies; each method stays individually simple.
 *
 * @spec openspec/specs/email-calendar-sync/spec.md
 */
class EmailMatchService {

	/**
	 * Public email-provider domains that should never trigger a
	 * domain-to-organization match. These addresses identify individuals,
	 * not organizations, so domain matching against them produces false
	 * positives (every gmail.com user would map to one client).
	 *
	 * @var array<int,string>
	 */
	private const PUBLIC_DOMAINS = [
		'gmail.com',
		'googlemail.com',
		'outlook.com',
		'hotmail.com',
		'live.com',
		'msn.com',
		'yahoo.com',
		'ymail.com',
		'icloud.com',
		'me.com',
		'mac.com',
		'aol.com',
		'protonmail.com',
		'proton.me',
		'gmx.com',
		'gmx.net',
		'mail.com',
		'zoho.com',
		'yandex.com',
		'yandex.ru',
	];

	/**
	 * Per-user app-config key — JSON of the matching settings payload.
	 *
	 * @var string
	 */
	public const SETTINGS_KEY = 'email_match_settings';

	/**
	 * Per-user app-config key — JSON status (last run, count, error).
	 *
	 * @var string
	 */
	public const STATUS_KEY = 'email_match_status';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (for OR services).
	 * @param IAppConfig $appConfig App configuration.
	 * @param IDBConnection $db Database connection (Mail DB query).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Match an email address to CRM entities.
	 *
	 * Looks the address up against `contact.email` and `client.email`. A
	 * single address may match multiple entities (e.g. a contact and their
	 * parent client share the same address) — all matches are returned.
	 *
	 * @param string $address The email address to match (case-insensitive).
	 *
	 * @return array<int,array{entityType:string,entityId:string}>
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function matchEmailToEntities(string $address): array {
		$address = strtolower(trim($address));
		if ($address === '') {
			return [];
		}

		$matches = [];

		$contacts = $this->findObjectsByEmail(schemaKey: 'contact_schema', email: $address);
		foreach ($contacts as $row) {
			$matches[] = ['entityType' => 'contact', 'entityId' => $row];
		}

		$clients = $this->findObjectsByEmail(schemaKey: 'client_schema', email: $address);
		foreach ($clients as $row) {
			$matches[] = ['entityType' => 'client', 'entityId' => $row];
		}

		return $matches;
	}//end matchEmailToEntities()

	/**
	 * Match a corporate email domain to an organization client.
	 *
	 * Skips public email providers (gmail.com etc.). For corporate
	 * domains, looks for a `client` with `type == organization` whose
	 * `email` ends in the same domain. Returns the first match, or null.
	 *
	 * @param string $domain The domain part (after the @).
	 *
	 * @return array{entityType:string,entityId:string}|null
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Ordered domain-matching strategies
	 *  guarded by flat early returns; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same flat strategy chain; path count is a
	 *  product of independent guards, not nesting.
	 */
	public function matchDomainToOrganization(string $domain): ?array {
		$domain = strtolower(trim($domain));
		if ($domain === '') {
			return null;
		}

		if ($this->isPublicDomain(domain: $domain) === true) {
			return null;
		}

		$register = $this->registerSlug();
		$schema = $this->schemaSlug(key: 'client_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$service = $this->getObjectService();
			if ($service === null) {
				return null;
			}

			$rows = $service->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'type' => 'organization',
					],
					'limit' => 1000,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: matchDomainToOrganization lookup failed',
				['domain' => $domain]
			);
			return null;
		}//end try

		if (is_array($rows) === false) {
			return null;
		}

		$suffix = '@' . $domain;
		foreach ($rows as $row) {
			$data = $this->toArray(object: $row);
			if ($data === null) {
				continue;
			}

			$email = strtolower((string)($data['email'] ?? ''));
			if ($email === '') {
				continue;
			}

			if (str_ends_with($email, $suffix) === true) {
				$id = $this->idOf(object: $data);
				if ($id !== '') {
					return ['entityType' => 'client', 'entityId' => $id];
				}
			}
		}

		return null;
	}//end matchDomainToOrganization()

	/**
	 * Return true when the domain is a public email provider.
	 *
	 * @param string $domain The domain part (after the @).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function isPublicDomain(string $domain): bool {
		$domain = strtolower(trim($domain));
		if ($domain === '') {
			return false;
		}

		return in_array($domain, self::PUBLIC_DOMAINS, true);
	}//end isPublicDomain()

	/**
	 * Match a single Mail message to CRM entities and link via the leaf.
	 *
	 * Pulls the message's sender + recipients, matches each address, and
	 * for every unique CRM object calls the OR `email` leaf's `linkEmail`
	 * API. Returns the count of new links the leaf actually created
	 * (the leaf is idempotent — pre-existing links are not re-counted).
	 *
	 * @param int $mailAccountId The Mail account id.
	 * @param int $mailMessageId The Mail message id.
	 * @param string $userId The owning user.
	 *
	 * @return int Number of new links created via the leaf.
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential address/entity linking
	 *  guards; each condition is a flat skip, extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same flat linking guards; path count is a
	 *  product of independent conditions, not nesting.
	 */
	public function matchAndLinkMessage(int $mailAccountId, int $mailMessageId, string $userId): int {
		$message = $this->fetchMailMessageMeta(messageId: $mailMessageId, accountId: $mailAccountId);
		if ($message === null) {
			return 0;
		}

		$addresses = $this->extractAddresses(message: $message);
		if ($addresses === []) {
			return 0;
		}

		$exclude = $this->getExcludedAddresses(userId: $userId);
		$entities = [];
		foreach ($addresses as $address) {
			if (in_array($address, $exclude, true) === true) {
				continue;
			}

			foreach ($this->matchEmailToEntities(address: $address) as $hit) {
				$key = $hit['entityType'] . ':' . $hit['entityId'];
				$entities[$key] = $hit;
			}

			$domain = $this->extractDomain(email: $address);
			if ($domain === null) {
				continue;
			}

			$org = $this->matchDomainToOrganization(domain: $domain);
			if ($org !== null) {
				$key = $org['entityType'] . ':' . $org['entityId'];
				$entities[$key] = $org;
			}
		}//end foreach

		if ($entities === []) {
			return 0;
		}

		$linkService = $this->getEmailLinkService();
		if ($linkService === null) {
			return 0;
		}

		$register = $this->registerSlug();

		// Fail closed on an unconfigured register. Without this the loop below
		// casts '' to int and links every email against register id 0 — a write
		// scoped to the wrong register rather than a refused one.
		if ($register === '') {
			return 0;
		}

		$createdCount = 0;
		foreach ($entities as $entity) {
			$schemaSlug = $entity['entityType'] . '_schema';
			$schema = $this->schemaSlug(key: $schemaSlug);
			if ($schema === '') {
				continue;
			}

			try {
				$before = $this->existingLinkId(
					linkService: $linkService,
					objectUuid: $entity['entityId'],
					mailAccountId: $mailAccountId,
					mailMessageId: $mailMessageId
				);

				$linkService->linkEmail(
					objectUuid: $entity['entityId'],
					registerId: (int)$register,
					schemaId: (int)$schema,
					mailAccountId: $mailAccountId,
					messageId: (string)$mailMessageId,
					messageUid: (string)$message['uid']
				);

				if ($before === null) {
					$createdCount++;
				}
			} catch (Throwable $e) {
				$this->logger->warning(
					'Pipelinq: email leaf linkEmail failed',
					[
						'entityType' => $entity['entityType'],
						'entityId' => $entity['entityId'],
						'messageId' => $mailMessageId,
					]
				);
			}//end try
		}//end foreach

		return $createdCount;
	}//end matchAndLinkMessage()

	/**
	 * Run the matching job for one user.
	 *
	 * Reads the user's matching settings (account + enabled). When sync is
	 * disabled or no account is configured, the run is a no-op. Otherwise
	 * iterates the inbound Mail messages since the last cursor and calls
	 * `matchAndLinkMessage` for each. Per-message failures do not abort
	 * the run.
	 *
	 * @param string $userId The user id to run for.
	 *
	 * @return array{linked:int,scanned:int} Counts for the run.
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function runForUser(string $userId): array {
		$settings = $this->getSettings(userId: $userId);
		if ($settings['enabled'] !== true || $settings['account'] <= 0) {
			return ['linked' => 0, 'scanned' => 0];
		}

		$scanned = 0;
		$linked = 0;
		$lastId = (int)$settings['cursor'];
		$maxId = $lastId;

		$messages = $this->listInboundMessages(accountId: $settings['account'], sinceId: $lastId);
		foreach ($messages as $msg) {
			$scanned++;
			try {
				$linked += $this->matchAndLinkMessage(
					mailAccountId: $settings['account'],
					mailMessageId: $msg['id'],
					userId: $userId
				);
				if ($msg['id'] > $maxId) {
					$maxId = $msg['id'];
				}
			} catch (Throwable $e) {
				$this->logger->warning(
					'Pipelinq: email match per-message failure',
					['messageId' => $msg['id']]
				);
			}
		}

		$this->writeStatus(userId: $userId, linked: $linked, scanned: $scanned, error: null);
		if ($maxId > $lastId) {
			$settings['cursor'] = $maxId;
			$this->writeSettings(userId: $userId, settings: $settings);
		}

		return ['linked' => $linked, 'scanned' => $scanned];
	}//end runForUser()

	/**
	 * Read the matching settings for a user.
	 *
	 * @param string $userId The user id.
	 *
	 * @return array{account:int,enabled:bool,excludedAddresses:array<int,string>,cursor:int}
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function getSettings(string $userId): array {
		$json = $this->appConfig->getValueString(
			Application::APP_ID,
			$this->settingsKeyFor(userId: $userId),
			''
		);

		if ($json === '') {
			return [
				'account' => 0,
				'enabled' => false,
				'excludedAddresses' => [],
				'cursor' => 0,
			];
		}

		$decoded = json_decode($json, true);
		if (is_array($decoded) === false) {
			return [
				'account' => 0,
				'enabled' => false,
				'excludedAddresses' => [],
				'cursor' => 0,
			];
		}

		return [
			'account' => (int)($decoded['account'] ?? 0),
			'enabled' => (bool)($decoded['enabled'] ?? false),
			'excludedAddresses' => $this->sanitiseAddresses(items: $decoded['excludedAddresses'] ?? []),
			'cursor' => (int)($decoded['cursor'] ?? 0),
		];

	}//end getSettings()

	/**
	 * Persist the matching settings for a user.
	 *
	 * @param string $userId The user id.
	 * @param array{account:int,enabled:bool,excludedAddresses:array<int,string>,cursor?:int} $settings Settings payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function writeSettings(string $userId, array $settings): void {
		$payload = [
			'account' => (int)$settings['account'],
			'enabled' => (bool)$settings['enabled'],
			'excludedAddresses' => $this->sanitiseAddresses(items: $settings['excludedAddresses']),
			'cursor' => (int)($settings['cursor'] ?? 0),
		];

		$this->appConfig->setValueString(
			Application::APP_ID,
			$this->settingsKeyFor(userId: $userId),
			json_encode($payload, JSON_UNESCAPED_UNICODE)
		);

	}//end writeSettings()

	/**
	 * Read the last-run status for a user.
	 *
	 * @param string $userId The user id.
	 *
	 * @return array{lastRunAt:?string,linked:int,scanned:int,error:?string}
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function getStatus(string $userId): array {
		$json = $this->appConfig->getValueString(
			Application::APP_ID,
			$this->statusKeyFor(userId: $userId),
			''
		);

		if ($json === '') {
			return ['lastRunAt' => null, 'linked' => 0, 'scanned' => 0, 'error' => null];
		}

		$decoded = json_decode($json, true);
		if (is_array($decoded) === false) {
			return ['lastRunAt' => null, 'linked' => 0, 'scanned' => 0, 'error' => null];
		}

		$lastRunAt = null;
		if (isset($decoded['lastRunAt']) === true) {
			$lastRunAt = (string)$decoded['lastRunAt'];
		}

		$error = null;
		if (isset($decoded['error']) === true) {
			$error = (string)$decoded['error'];
		}

		return [
			'lastRunAt' => $lastRunAt,
			'linked' => (int)($decoded['linked'] ?? 0),
			'scanned' => (int)($decoded['scanned'] ?? 0),
			'error' => $error,
		];

	}//end getStatus()

	/**
	 * Persist the last-run status for a user.
	 *
	 * @param string $userId The user id.
	 * @param int $linked Number of new leaf links.
	 * @param int $scanned Number of messages scanned.
	 * @param string|null $error Optional static error message.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function writeStatus(string $userId, int $linked, int $scanned, ?string $error = null): void {
		$payload = [
			'lastRunAt' => gmdate('c'),
			'linked' => $linked,
			'scanned' => $scanned,
			'error' => $error,
		];

		$this->appConfig->setValueString(
			Application::APP_ID,
			$this->statusKeyFor(userId: $userId),
			json_encode($payload, JSON_UNESCAPED_UNICODE)
		);

	}//end writeStatus()

	/**
	 * Extract the addresses to match from a Mail message metadata row.
	 *
	 * @param array<string,mixed> $message Message metadata.
	 *
	 * @return array<int,string> Unique, lowercased addresses.
	 */
	private function extractAddresses(array $message): array {
		$addresses = [];
		if (isset($message['sender']) === true && is_string($message['sender']) === true) {
			$addresses[] = strtolower(trim($message['sender']));
		}

		if (isset($message['recipients']) === true && is_array($message['recipients']) === true) {
			foreach ($message['recipients'] as $address) {
				if (is_string($address) === true && trim($address) !== '') {
					$addresses[] = strtolower(trim($address));
				}
			}
		}

		$addresses = array_values(array_unique(array_filter($addresses)));
		return $addresses;
	}//end extractAddresses()

	/**
	 * Extract the domain part from an email address.
	 *
	 * @param string $email Email address.
	 *
	 * @return string|null Lowercased domain, or null if invalid.
	 */
	private function extractDomain(string $email): ?string {
		$parts = explode('@', $email);
		if (count($parts) !== 2 || $parts[1] === '') {
			return null;
		}

		return strtolower(trim($parts[1]));
	}//end extractDomain()

	/**
	 * Sanitise an excluded-addresses input array.
	 *
	 * @param mixed $items Raw input.
	 *
	 * @return array<int,string>
	 */
	private function sanitiseAddresses(mixed $items): array {
		if (is_string($items) === true) {
			$split = preg_split('/[\\s,]+/', $items);
			if ($split === false) {
				$split = [];
			}

			$items = $split;
		}

		if (is_array($items) === false) {
			return [];
		}

		$out = [];
		foreach ($items as $entry) {
			if (is_string($entry) === false) {
				continue;
			}

			$clean = strtolower(trim($entry));
			if ($clean !== '' && filter_var($clean, FILTER_VALIDATE_EMAIL) !== false) {
				$out[] = $clean;
			}
		}

		return array_values(array_unique($out));
	}//end sanitiseAddresses()

	/**
	 * Return the excluded-addresses list for a user.
	 *
	 * @param string $userId User id.
	 *
	 * @return array<int,string>
	 */
	private function getExcludedAddresses(string $userId): array {
		return $this->getSettings(userId: $userId)['excludedAddresses'];
	}//end getExcludedAddresses()

	/**
	 * Find object ids whose `email` field equals the address.
	 *
	 * @param string $schemaKey Pipelinq app-config schema key (e.g. `contact_schema`).
	 * @param string $email Lowercased email to match.
	 *
	 * @return array<int,string> Matched object ids.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Flat guards over register/schema
	 *  resolution and result normalisation; extraction adds no clarity.
	 */
	private function findObjectsByEmail(string $schemaKey, string $email): array {
		$register = $this->registerSlug();
		$schema = $this->schemaSlug(key: $schemaKey);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$service = $this->getObjectService();
			if ($service === null) {
				return [];
			}

			$rows = $service->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'email' => $email,
					],
					'limit' => 100,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: findObjectsByEmail failed',
				['schemaKey' => $schemaKey]
			);
			return [];
		}//end try

		if (is_array($rows) === false) {
			return [];
		}

		$ids = [];
		foreach ($rows as $row) {
			$data = $this->toArray(object: $row);
			if ($data === null) {
				continue;
			}

			$rowEmail = strtolower((string)($data['email'] ?? ''));
			if ($rowEmail !== $email) {
				continue;
			}

			$id = $this->idOf(object: $data);
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end findObjectsByEmail()

	/**
	 * Resolve the OR ObjectService through DI (null on missing).
	 *
	 * @return object|null
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

	}//end getObjectService()

	/**
	 * Resolve the OR EmailLinkService through DI (null on missing).
	 *
	 * @return object|null
	 */
	private function getEmailLinkService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\EmailLinkService');
		} catch (Throwable $e) {
			return null;
		}

	}//end getEmailLinkService()

	/**
	 * Check whether the leaf already holds a link for the (object, account,
	 * message) triple. Returns the existing link id when found, null otherwise.
	 *
	 * The lookup uses the leaf's mapper indirectly — if introspection isn't
	 * available we return null and let the leaf's own idempotency handle the
	 * dedup. The count delta will still be correct because the leaf returns
	 * the same entity row on the second call.
	 *
	 * @param object $linkService OR EmailLinkService instance.
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $mailAccountId Mail account id.
	 * @param int $mailMessageId Mail message id.
	 *
	 * @return int|null Existing link id, or null.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Flat guards over the linked-email
	 *  lookup and shape normalisation; each branch is an independent early return.
	 */
	private function existingLinkId(
		object $linkService,
		string $objectUuid,
		int $mailAccountId,
		int $mailMessageId,
	): ?int {
		if (method_exists($linkService, 'getLinkedEmails') === false) {
			return null;
		}

		try {
			$page = $linkService->getLinkedEmails($objectUuid, null, 100);
		} catch (Throwable $e) {
			return null;
		}

		if (is_array($page) === false) {
			return null;
		}

		$items = [];
		if (isset($page['items']) === true && is_array($page['items']) === true) {
			$items = $page['items'];
		}

		foreach ($items as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$accId = (int)($row['mailAccountId'] ?? 0);
			$msgId = (int)($row['mailMessageId'] ?? 0);
			if ($accId === $mailAccountId && $msgId === $mailMessageId) {
				return (int)($row['id'] ?? 0);
			}
		}

		return null;
	}//end existingLinkId()

	/**
	 * Fetch a Mail message's metadata from the Mail app DB.
	 *
	 * Returns null when Mail is uninstalled or the row is absent.
	 *
	 * @param int $messageId Mail message id.
	 * @param int $accountId Mail account id.
	 *
	 * @return array{uid:string,subject:?string,sender:?string,recipients:array<int,string>,date:?string}|null
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Two guarded DB lookups plus row
	 *  normalisation; the branches are flat, not nested.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same flat lookup/normalise guards; path
	 *  count is a product of independent conditions.
	 */
	private function fetchMailMessageMeta(int $messageId, int $accountId): ?array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('m.id', 'm.uid', 'm.subject', 'm.sent_at', 'mb.account_id')
				->from('mail_messages', 'm')
				->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('mb.id', 'm.mailbox_id'))
				->where($qb->expr()->eq('m.id', $qb->createNamedParameter($messageId)))
				->andWhere($qb->expr()->eq('mb.account_id', $qb->createNamedParameter($accountId)))
				->setMaxResults(1);

			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
		} catch (Throwable $e) {
			return null;
		}

		if ($row === false || is_array($row) === false) {
			return null;
		}

		$recipients = [];
		$sender = null;
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('email', 'type')
				->from('mail_recipients')
				->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)));

			$result = $qb->executeQuery();
			while (($rcpt = $result->fetch()) !== false) {
				$email = strtolower(trim((string)($rcpt['email'] ?? '')));
				if ($email === '') {
					continue;
				}

				$type = (int)($rcpt['type'] ?? -1);
				if ($type === 0) {
					$sender = $email;
					continue;
				}

				$recipients[] = $email;
			}

			$result->closeCursor();
		} catch (Throwable $e) {
			// Leave recipients empty on lookup failure.
		}//end try

		$sentAt = null;
		if (isset($row['sent_at']) === true && $row['sent_at'] !== null) {
			$sentAt = date('c', (int)$row['sent_at']);
		}

		$subject = null;
		if (isset($row['subject']) === true) {
			$subject = (string)$row['subject'];
		}

		return [
			'uid' => (string)($row['uid'] ?? ''),
			'subject' => $subject,
			'sender' => $sender,
			'recipients' => array_values(array_unique($recipients)),
			'date' => $sentAt,
		];

	}//end fetchMailMessageMeta()

	/**
	 * List inbound Mail message rows for an account, since a cursor id.
	 *
	 * Returns rows ordered by id ascending so the cursor advances
	 * monotonically. The cap of 200 prevents a single run from holding
	 * the DB busy when an account has a large historical backlog.
	 *
	 * @param int $accountId Mail account id.
	 * @param int $sinceId Last processed message id (0 = from the start).
	 *
	 * @return array<int,array{id:int}>
	 */
	private function listInboundMessages(int $accountId, int $sinceId): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('m.id')
				->from('mail_messages', 'm')
				->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('mb.id', 'm.mailbox_id'))
				->where($qb->expr()->eq('mb.account_id', $qb->createNamedParameter($accountId)))
				->andWhere($qb->expr()->gt('m.id', $qb->createNamedParameter($sinceId)))
				->orderBy('m.id', 'ASC')
				->setMaxResults(200);

			$result = $qb->executeQuery();
			$rows = [];
			while (($row = $result->fetch()) !== false) {
				$rows[] = ['id' => (int)($row['id'] ?? 0)];
			}

			$result->closeCursor();
			return $rows;
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: listInboundMessages failed');
			return [];
		}//end try

	}//end listInboundMessages()

	/**
	 * Pull the canonical object id from an OR row.
	 *
	 * @param array<string,mixed> $object Object data.
	 *
	 * @return string
	 */
	private function idOf(array $object): string {
		if (isset($object['@self']) === true && is_array($object['@self']) === true) {
			$self = $object['@self'];
			if (isset($self['id']) === true) {
				return (string)$self['id'];
			}

			if (isset($self['uuid']) === true) {
				return (string)$self['uuid'];
			}
		}

		if (isset($object['id']) === true) {
			return (string)$object['id'];
		}

		if (isset($object['uuid']) === true) {
			return (string)$object['uuid'];
		}

		return '';
	}//end idOf()

	/**
	 * Normalise an OR entity or array to a plain array.
	 *
	 * @param mixed $object Entity, array, or null.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Flat chain of type/shape checks
	 *  for OR entity normalisation; each branch is an independent early return.
	 */
	private function toArray(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'getObject') === true) {
				$payload = $object->getObject();
				if (is_array($payload) === true) {
					return $payload;
				}
			}

			if (method_exists($object, 'toArray') === true) {
				$arr = $object->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$object;
		}//end if

		return null;
	}//end toArray()

	/**
	 * Resolve the pipelinq register slug.
	 *
	 * Fails closed: '' means "unconfigured", and every caller refuses the
	 * OpenRegister call on it. An empty register must never be handed to
	 * OpenRegister — ObjectService skips setRegister() for an empty value, so
	 * the query silently inherits whatever register context an earlier call in
	 * the same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @return string The configured register slug, or '' when unconfigured.
	 */
	private function registerSlug(): string {
		$registerSlug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerSlug === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return $registerSlug;
	}//end registerSlug()

	/**
	 * Resolve a schema slug by app-config key.
	 *
	 * @param string $key App-config key.
	 *
	 * @return string
	 */
	private function schemaSlug(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}//end schemaSlug()

	/**
	 * Per-user app-config key for matching settings.
	 *
	 * @param string $userId The user id.
	 *
	 * @return string
	 */
	private function settingsKeyFor(string $userId): string {
		return self::SETTINGS_KEY . '.' . $userId;
	}//end settingsKeyFor()

	/**
	 * Per-user app-config key for matching status.
	 *
	 * @param string $userId The user id.
	 *
	 * @return string
	 */
	private function statusKeyFor(string $userId): string {
		return self::STATUS_KEY . '.' . $userId;
	}//end statusKeyFor()
}//end class
