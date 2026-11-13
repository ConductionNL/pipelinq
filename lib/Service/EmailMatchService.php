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

use OCA\OpenRegister\Service\EmailMessageRef;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Util\AnyPayloadToArray;
use OCA\Pipelinq\Util\RowIdOf;
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
	use AnyPayloadToArray;
	use RowIdOf;


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
	 */
	public function matchDomainToOrganization(string $domain): ?array {
		$domain = strtolower(trim($domain));
		if ($this->isCorporateDomain(domain: $domain) === false) {
			return null;
		}

		$filters = $this->schemaFilters(schemaKey: 'client_schema');
		if ($filters === null) {
			return null;
		}

		$rows = $this->organizationRows(filters: $filters, domain: $domain);
		return (new EmailRowMatcher())->firstOrganizationWithSuffix(rows: $rows, suffix: '@' . $domain);
	}//end matchDomainToOrganization()

	/**
	 * The organization clients of the configured schema; a failed lookup is logged.
	 *
	 * @param array{register:string,schema:string} $filters The register and schema filters.
	 * @param string                               $domain  The domain being matched, for the log.
	 *
	 * @return mixed The rows, or null when the lookup failed or OpenRegister is unavailable.
	 */
	private function organizationRows(array $filters, string $domain): mixed {
		try {
			return $this->findAllRows(config: ['filters' => $filters + ['type' => 'organization'], 'limit' => 1000]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: matchDomainToOrganization lookup failed',
				['domain' => $domain]
			);
			return null;
		}
	}//end organizationRows()

	/**
	 * Whether a (normalised) domain can match an organization: not empty and
	 * not a public email provider.
	 *
	 * @param string $domain The lowercased, trimmed domain.
	 *
	 * @return bool True for a corporate domain.
	 */
	private function isCorporateDomain(string $domain): bool {
		return $domain !== '' && $this->isPublicDomain(domain: $domain) === false;
	}//end isCorporateDomain()

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
	 * @param MailMessageKey $message The Mail message.
	 * @param string         $userId  The owning user.
	 *
	 * @return int Number of new links created via the leaf.
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function matchAndLinkMessage(MailMessageKey $message, string $userId): int {
		$meta = (new MailMessageReader(db: $this->db, logger: $this->logger))->fetchMeta(message: $message);
		if ($meta === null) {
			return 0;
		}

		$entities = $this->entitiesForMessage(meta: $meta, userId: $userId);
		if ($entities === []) {
			return 0;
		}

		$leaf = $this->emailLeaf();
		if ($leaf === null) {
			return 0;
		}

		$run = new MessageLinkRun(leaf: $leaf, message: new FetchedMailMessage(key: $message, uid: (string)$meta['uid']));
		return $this->linkEntities(entities: $entities, run: $run);
	}//end matchAndLinkMessage()

	/**
	 * The unique CRM entities a message's addresses match, the user's
	 * excluded addresses left out.
	 *
	 * @param array<string, mixed> $meta   The message metadata.
	 * @param string               $userId The owning user.
	 *
	 * @return array<string, array{entityType:string,entityId:string}> Keyed by `type:id`.
	 */
	private function entitiesForMessage(array $meta, string $userId): array {
		$addresses = $this->extractAddresses(message: $meta);
		if ($addresses === []) {
			return [];
		}

		$exclude = $this->getExcludedAddresses(userId: $userId);
		$entities = [];
		foreach ($addresses as $address) {
			if (in_array($address, $exclude, true) === false) {
				$entities = $this->withAddressEntities(entities: $entities, address: $address);
			}
		}

		return $entities;
	}//end entitiesForMessage()

	/**
	 * Add the entities one address matches: by exact email, then the
	 * organization of its corporate domain.
	 *
	 * @param array<string, array{entityType:string,entityId:string}> $entities The entities so far.
	 * @param string                                                  $address  The address.
	 *
	 * @return array<string, array{entityType:string,entityId:string}> The entities with this address's matches.
	 */
	private function withAddressEntities(array $entities, string $address): array {
		foreach ($this->matchEmailToEntities(address: $address) as $hit) {
			$entities[$hit['entityType'] . ':' . $hit['entityId']] = $hit;
		}

		$domain = $this->extractDomain(email: $address);
		if ($domain === null) {
			return $entities;
		}

		$org = $this->matchDomainToOrganization(domain: $domain);
		if ($org !== null) {
			$entities[$org['entityType'] . ':' . $org['entityId']] = $org;
		}

		return $entities;
	}//end withAddressEntities()

	/**
	 * The email leaf bound to the configured register, or null when either
	 * is missing.
	 *
	 * Fails closed on an unconfigured register. Without this the link would
	 * cast '' to int and link every email against register id 0 — a write
	 * scoped to the wrong register rather than a refused one.
	 *
	 * @return EmailLeaf|null The leaf, or null.
	 */
	private function emailLeaf(): ?EmailLeaf {
		$linkService = $this->getEmailLinkService();
		if ($linkService === null) {
			return null;
		}

		$register = $this->registerSlug();
		if ($register === '') {
			return null;
		}

		return new EmailLeaf(linkService: $linkService, register: $register);
	}//end emailLeaf()

	/**
	 * Link the message to every entity.
	 *
	 * @param array<string, array{entityType:string,entityId:string}> $entities The matched entities.
	 * @param MessageLinkRun                                          $run      The leaf and the message.
	 *
	 * @return int Number of new links.
	 */
	private function linkEntities(array $entities, MessageLinkRun $run): int {
		$createdCount = 0;
		foreach ($entities as $entity) {
			$createdCount += $this->linkEntity(entity: $entity, run: $run);
		}

		return $createdCount;
	}//end linkEntities()

	/**
	 * Link the message to one entity through the leaf; a failure is logged.
	 *
	 * @param array{entityType:string,entityId:string} $entity The entity.
	 * @param MessageLinkRun                           $run    The leaf and the message.
	 *
	 * @return int 1 when the leaf held no link for the message before, else 0.
	 */
	private function linkEntity(array $entity, MessageLinkRun $run): int {
		$schema = $this->schemaSlug(key: $entity['entityType'] . '_schema');
		if ($schema === '') {
			return 0;
		}

		try {
			$before = (new LinkedEmails(linkService: $run->leaf->linkService))->existingLinkId(objectUuid: $entity['entityId'], message: $run->message->key);
			$target = (new LinkTarget(objectUuid: $entity['entityId'], registerId: (int)$run->leaf->register))->inSchema(schemaId: (int)$schema);
			$this->linkInLeaf(target: $target, run: $run);
			return (int)($before === null);
		} catch (Throwable $e) {
			$this->logLinkFailure(entity: $entity, run: $run);
			return 0;
		}
	}//end linkEntity()

	/**
	 * Ask the leaf to link the run's message to a target.
	 *
	 * @param LinkTarget     $target The object to link to.
	 * @param MessageLinkRun $run    The leaf and the message.
	 *
	 * @return void
	 */
	private function linkInLeaf(LinkTarget $target, MessageLinkRun $run): void {
		$key = $run->message->key;
		$run->leaf->linkService->linkEmail(
			target: $target,
			message: (new EmailMessageRef(mailAccountId: $key->mailAccountId, messageId: (string)$key->mailMessageId))
				->withUid(uid: $run->message->uid)
		);
	}//end linkInLeaf()

	/**
	 * Log a failed leaf call for one entity.
	 *
	 * @param array{entityType:string,entityId:string} $entity The entity.
	 * @param MessageLinkRun                           $run    The leaf and the message.
	 *
	 * @return void
	 */
	private function logLinkFailure(array $entity, MessageLinkRun $run): void {
		$this->logger->warning(
			'Pipelinq: email leaf linkEmail failed',
			[
				'entityType' => $entity['entityType'],
				'entityId' => $entity['entityId'],
				'messageId' => $run->message->key->mailMessageId,
			]
		);
	}//end logLinkFailure()

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

		if ($this->ownsAccount(userId: $userId, accountId: $settings['account']) === false) {
			return ['linked' => 0, 'scanned' => 0];
		}

		$scanned = 0;
		$linked = 0;
		$lastId = (int)$settings['cursor'];
		$maxId = $lastId;

		$messages = (new MailMessageReader(db: $this->db, logger: $this->logger))->listInbound(accountId: $settings['account'], sinceId: $lastId);
		foreach ($messages as $msg) {
			$scanned++;
			try {
				$linked += $this->matchAndLinkMessage(
					message: new MailMessageKey(mailAccountId: $settings['account'], mailMessageId: $msg['id']),
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
			AppIdentity::APP_ID,
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
	 * The user's own Nextcloud Mail accounts, the only accounts the matcher may read.
	 *
	 * Empty when Nextcloud Mail is not installed or the user has no account.
	 *
	 * @param string $userId The user id.
	 *
	 * @return array<int,array{id:int,email:string,name:string}>
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function listAccounts(string $userId): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'email', 'name')
				->from('mail_accounts')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->orderBy('id', 'ASC');

			$result   = $qb->executeQuery();
			$accounts = [];
			while (($row = $result->fetch()) !== false) {
				$accounts[] = [
					'id' => (int)($row['id'] ?? 0),
					'email' => (string)($row['email'] ?? ''),
					'name' => (string)($row['name'] ?? ''),
				];
			}

			$result->closeCursor();
			return $accounts;
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: listAccounts failed');
			return [];
		}//end try

	}//end listAccounts()

	/**
	 * Whether a Nextcloud Mail account belongs to the user.
	 *
	 * @param string $userId    The user id.
	 * @param int    $accountId The Mail account id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function ownsAccount(string $userId, int $accountId): bool {
		foreach ($this->listAccounts(userId: $userId) as $account) {
			if ($account['id'] === $accountId) {
				return true;
			}
		}

		return false;

	}//end ownsAccount()

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
			AppIdentity::APP_ID,
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
			AppIdentity::APP_ID,
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
			AppIdentity::APP_ID,
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
	 */
	private function findObjectsByEmail(string $schemaKey, string $email): array {
		$filters = $this->schemaFilters(schemaKey: $schemaKey);
		if ($filters === null) {
			return [];
		}

		try {
			$rows = $this->findAllRows(config: ['filters' => $filters + ['email' => $email], 'limit' => 100]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: findObjectsByEmail failed',
				['schemaKey' => $schemaKey]
			);
			return [];
		}

		return (new EmailRowMatcher())->idsWithEmail(rows: $rows, email: $email);
	}//end findObjectsByEmail()

	/**
	 * The register and schema filters of a configured schema.
	 *
	 * @param string $schemaKey Pipelinq app-config schema key.
	 *
	 * @return array{register:string,schema:string}|null The filters, null when either is not configured.
	 */
	private function schemaFilters(string $schemaKey): ?array {
		$register = $this->registerSlug();
		$schema = $this->schemaSlug(key: $schemaKey);
		if ($register === '' || $schema === '') {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end schemaFilters()

	/**
	 * Run one OpenRegister listing.
	 *
	 * @param array<string, mixed> $config The findAll() config.
	 *
	 * @return mixed The rows, or null when OpenRegister is not available.
	 *
	 * @throws Throwable When the listing fails.
	 */
	private function findAllRows(array $config): mixed {
		$service = $this->getObjectService();
		if ($service === null) {
			return null;
		}

		return $service->findAll($config);
	}//end findAllRows()

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
		$registerSlug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
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
		return $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
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
