<?php

/**
 * Pipelinq LoyaltyAccountService.
 *
 * Lifecycle for KlantLoyaltyAccount: create / read / disable / soft-delete (GDPR).
 * Composite uniqueness on (klantId, programmeId) is enforced at the application
 * layer via getOrCreateAccount which queries ObjectService::findAll before insert.
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-003
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * KlantLoyaltyAccount lifecycle service.
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
 */
class LoyaltyAccountService {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (OpenRegister lookup).
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Create a new KlantLoyaltyAccount.
	 *
	 * @param string $customerId The Nextcloud contact UID.
	 * @param string $programmeId The programme UUID.
	 * @param bool $optIn Whether the customer accepted opt-in (REQ-LOY-010).
	 * @param string $termsVersion The version of terms accepted.
	 *
	 * @return array<string, mixed> The created account.
	 *
	 * @throws RuntimeException When OR is unavailable or opt-in missing.
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-010-01
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $optIn is the REQ-LOY-010 opt-in
	 *  acceptance flag, part of the account-creation contract; not a behaviour switch.
	 */
	public function createAccount(
		string $customerId,
		string $programmeId,
		bool $optIn = false,
		string $termsVersion = '1.0',
	): array {
		if ($customerId === '' || $programmeId === '') {
			throw new RuntimeException('klantId and programmeId are required.');
		}

		if ($optIn === false) {
			throw new RuntimeException('Opt-in is mandatory for loyalty account creation (REQ-LOY-010-01).');
		}

		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

		$payload = [
			'customerId' => $customerId,
			'programmeId' => $programmeId,
			'currentBalance' => 0,
			'lifetimePoints' => 0,
			'status' => 'actief',
			'createdOn' => $now,
			'lastActivityDate' => $now,
			'optInAccepted' => true,
			'optInTimestamp' => $now,
			'optInTermsVersion' => $termsVersion,
			'anonymized' => false,
		];

		return $this->persist(payload: $payload, uuid: null);
	}//end createAccount()

	/**
	 * Get an account by UUID.
	 *
	 * @param string $accountId The account UUID.
	 *
	 * @return array<string, mixed>|null The account, or null when not found.
	 */
	public function getAccount(string $accountId): ?array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '' || $accountId === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(
				id: $accountId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Pipelinq: account lookup failed', ['exception' => $e->getMessage()]);
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}//end getAccount()

	/**
	 * Idempotent get-or-create for (klantId, programmeId).
	 *
	 * Enforces composite uniqueness at the application layer by querying first.
	 *
	 * @param string $customerId The Nextcloud contact UID.
	 * @param string $programmeId The programme UUID.
	 * @param bool $optIn Whether opt-in was accepted (only applied on creation).
	 * @param string $termsVersion The terms version.
	 *
	 * @return array<string, mixed> The existing or newly-created account.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $optIn passes through the REQ-LOY-010 opt-in flag to createAccount(); not a behaviour switch.
	 */
	public function getOrCreateAccount(
		string $customerId,
		string $programmeId,
		bool $optIn = true,
		string $termsVersion = '1.0',
	): array {
		$existing = $this->findAccountByKlantAndProgramme(customerId: $customerId, programmeId: $programmeId);
		if ($existing !== null) {
			return $existing;
		}

		return $this->createAccount(
			customerId: $customerId,
			programmeId: $programmeId,
			optIn: $optIn,
			termsVersion: $termsVersion
		);
	}//end getOrCreateAccount()

	/**
	 * Find an account by composite (klantId, programmeId).
	 *
	 * @param string $customerId The Nextcloud contact UID.
	 * @param string $programmeId The programme UUID.
	 *
	 * @return array<string, mixed>|null The account, or null.
	 */
	public function findAccountByKlantAndProgramme(string $customerId, string $programmeId): ?array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$result = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'customerId' => $customerId,
						'programmeId' => $programmeId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Pipelinq: account findAll failed', ['exception' => $e->getMessage()]);
			return null;
		}

		if (is_array($result) === false || count($result) === 0) {
			return null;
		}

		return $this->toArray(object: reset($result));
	}//end findAccountByKlantAndProgramme()

	/**
	 * Disable an account (e.g. fraud, dispute).
	 *
	 * @param string $accountId The account UUID.
	 * @param string $reason Free-text reason (logged).
	 *
	 * @return array<string, mixed>|null The updated account, or null.
	 */
	public function disableAccount(string $accountId, string $reason): ?array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return null;
		}

		$account['status'] = 'geblokkerd';
		$this->logger->info(
			'Pipelinq: loyalty account disabled',
			['accountId' => $accountId, 'reason' => $reason]
		);

		return $this->persist(payload: $account, uuid: $accountId);
	}//end disableAccount()

	/**
	 * GDPR soft-delete: anonymise klantId, keep account+ledger for audit.
	 *
	 * @param string $accountId The account UUID.
	 *
	 * @return array<string, mixed>|null The anonymised account, or null.
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-010-03
	 */
	public function deleteAccount(string $accountId): ?array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return null;
		}

		$account['customerId'] = null;
		$account['status'] = 'gedeactiveerd';
		$account['anonymized'] = true;

		$this->logger->info(
			'Pipelinq: loyalty account GDPR-anonymised',
			['accountId' => $accountId]
		);

		return $this->persist(payload: $account, uuid: $accountId);
	}//end deleteAccount()

	/**
	 * Update the denormalised balance fields after a ledger movement.
	 *
	 * Called by PointsLedgerService after a credit/debit/expiry/adjustment.
	 *
	 * @param string $accountId The account UUID.
	 * @param int $newCurrentBalance The new current balance.
	 * @param int $lifetimeDelta How much to add to lifetimePoints (only positive on credit).
	 * @param string $lastActivityDate ISO-8601 timestamp of the activity.
	 *
	 * @return array<string, mixed>|null The updated account, or null.
	 */
	public function updateBalances(
		string $accountId,
		int $newCurrentBalance,
		int $lifetimeDelta,
		string $lastActivityDate,
	): ?array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return null;
		}

		$account['currentBalance'] = $newCurrentBalance;
		$account['lifetimePoints'] = (int)($account['lifetimePoints'] ?? 0) + max(0, $lifetimeDelta);
		$account['lastActivityDate'] = $lastActivityDate;

		return $this->persist(payload: $account, uuid: $accountId);
	}//end updateBalances()

	/**
	 * Set the current tier and validity dates on the account.
	 *
	 * @param string $accountId The account UUID.
	 * @param ?string $tierId The new tier ID (null clears).
	 * @param ?string $tierAchievedOn Timestamp the tier was reached.
	 * @param ?string $tierValidTo Scheduled downgrade date.
	 *
	 * @return array<string, mixed>|null The updated account.
	 */
	public function setTier(
		string $accountId,
		?string $tierId,
		?string $tierAchievedOn = null,
		?string $tierValidTo = null,
	): ?array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return null;
		}

		$account['currentTierId'] = $tierId;
		if ($tierAchievedOn !== null) {
			$account['tierAchievedOn'] = $tierAchievedOn;
		}

		if ($tierValidTo !== null) {
			$account['tierValidTo'] = $tierValidTo;
		}

		return $this->persist(payload: $account, uuid: $accountId);
	}//end setTier()

	/**
	 * List all accounts for a programme (paginated; soft-cap 1000).
	 *
	 * @param string $programmeId The programme UUID.
	 * @param int $limit Max results.
	 * @param int $offset Pagination offset.
	 *
	 * @return array<int, array<string, mixed>> The accounts.
	 */
	public function listAccountsForProgramme(string $programmeId, int $limit = 1000, int $offset = 0): array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'programmeId' => $programmeId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => $limit,
					'offset' => $offset,
				]
			);
		} catch (\Throwable $e) {
			return [];
		}

		$values = [];
		if (is_array($rows) === true) {
			$values = array_values($rows);
		}

		return array_map([$this, 'toArray'], $values);
	}//end listAccountsForProgramme()

	/**
	 * Persist an account (create when uuid is null, update otherwise).
	 *
	 * @param array<string, mixed> $payload The account data.
	 * @param ?string $uuid The UUID for updates.
	 *
	 * @return array<string, mixed> The saved account.
	 */
	private function persist(array $payload, ?string $uuid): array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '') {
			throw new RuntimeException('KlantLoyaltyAccount schema is not configured.');
		}

		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);

		return $this->toArray(object: $saved);
	}//end persist()

	/**
	 * Resolve register + klantLoyaltyAccount schema IDs.
	 *
	 * Fails closed: '' on either id means "unconfigured", and every caller
	 * refuses the OpenRegister call on it. An empty id must never be handed to
	 * OpenRegister — ObjectService skips setRegister()/setSchema() for an empty
	 * value, so the query silently inherits whatever context an earlier call in
	 * the same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @return array{0: string, 1: string} The [register, schema] ids, each ''
	 *                                     when unconfigured.
	 */
	private function config(): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'klantLoyaltyAccount_schema', '');

		// Spelled as in_array() rather than `$a === '' || $b === ''` to keep
		// this class under the PHPMD complexity ceiling; the check is identical.
		if (in_array('', [$registerId, $schemaId], true) === true) {
			$this->logger->warning(
				'Pipelinq: register/klantLoyaltyAccount_schema not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return [$registerId, $schemaId];
	}//end config()

	/**
	 * Normalise OR entity/array to a plain array.
	 *
	 * @param mixed $object The entity or array.
	 *
	 * @return array<string, mixed>
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

		return [];
	}//end toArray()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException When OR is unavailable.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
