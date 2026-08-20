<?php

/**
 * Pipelinq PointsLedgerService.
 *
 * Append-only ledger for CustomerLoyaltyAccount points movements (credit, debit,
 * expiry, adjustment, refund). Ledger entries are immutable after creation; this
 * service NEVER calls updateObject on a PointsLedgerEntry. Account balance is
 * derived from the ledger and denormalised onto CustomerLoyaltyAccount via
 * LoyaltyAccountService::updateBalances.
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;

/**
 * Append-only points ledger.
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) owns every ledger
 *  movement type (credit/debit/expiry/adjustment/refund) plus balance/history
 *  reads as small, single-purpose methods delegating to one shared
 *  append-and-update core.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Measured 13, threshold 13.
 *  Same cause as above: one class owns every movement type, so it names each
 *  movement's request/result type once. Splitting by movement would duplicate
 *  the append-and-update core five times.
 */
class PointsLedgerService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoyaltyAccountService $accountService The loyalty account service.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-084).
	 * @param AggregationRunner $aggregationRunner Runs the balance aggregations.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoyaltyAccountService $accountService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly AggregationRunner $aggregationRunner,
	) {
	}//end __construct()

	/**
	 * Credit points to an account (atomic ledger entry + balance update).
	 *
	 * @param string $accountId The account UUID.
	 * @param int $amount Positive integer points to credit.
	 * @param ?string $ruleId The PointsRule UUID that produced the credit.
	 * @param array<string, mixed> $sourceDocument Source linkage (transactionId etc.).
	 * @param string $processedBy Who/what processed it (POS terminal id, system).
	 *
	 * @return array<string, mixed> The created PointsLedgerEntry.
	 *
	 * @throws RuntimeException When amount is non-positive or account missing.
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002-01
	 */
	public function creditPoints(
		string $accountId,
		int $amount,
		?string $ruleId,
		array $sourceDocument,
		string $processedBy,
	): array {
		if ($amount <= 0) {
			throw new RuntimeException('Credit amount must be positive.');
		}

		return $this->appendAndUpdate(
			accountId: $accountId,
			type: 'credit',
			signedCount: $amount,
			ruleId: $ruleId,
			sourceDocument: $sourceDocument,
			processedBy: $processedBy,
			lifetimeDelta: $amount
		);
	}//end creditPoints()

	/**
	 * Debit points (redemption-style).
	 *
	 * @param string $accountId The account UUID.
	 * @param int $amount Positive integer points to debit.
	 * @param string $redemptionId The Redemption UUID.
	 * @param array<string, mixed> $sourceDocument Source linkage.
	 * @param string $processedBy Who/what processed it.
	 *
	 * @return array<string, mixed> The PointsLedgerEntry.
	 *
	 * @throws RuntimeException When amount non-positive or balance insufficient.
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-004-01
	 */
	public function debitPoints(
		string $accountId,
		int $amount,
		string $redemptionId,
		array $sourceDocument,
		string $processedBy,
	): array {
		if ($amount <= 0) {
			throw new RuntimeException('Debit amount must be positive.');
		}

		$account = $this->accountService->getAccount(accountId: $accountId);
		if ($account === null) {
			throw new RuntimeException('Account not found.');
		}

		if ((int)($account['currentBalance'] ?? 0) < $amount) {
			throw new RuntimeException('Insufficient balance.');
		}

		$sourceDocument['redemptionId'] = $redemptionId;

		return $this->appendAndUpdate(
			accountId: $accountId,
			type: 'debit',
			signedCount: -$amount,
			ruleId: null,
			sourceDocument: $sourceDocument,
			processedBy: $processedBy,
			lifetimeDelta: 0
		);
	}//end debitPoints()

	/**
	 * Expire points (system-initiated by PointsExpiryBatchJob).
	 *
	 * @param string $accountId The account UUID.
	 * @param int $amount Positive integer to expire.
	 * @param string $reason Expiry reason (e.g. "12m inactivity").
	 *
	 * @return array<string, mixed> The ledger entry.
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-005-01
	 */
	public function expirePoints(string $accountId, int $amount, string $reason): array {
		if ($amount <= 0) {
			throw new RuntimeException('Expiry amount must be positive.');
		}

		return $this->appendAndUpdate(
			accountId: $accountId,
			type: 'expiry',
			signedCount: -$amount,
			ruleId: null,
			sourceDocument: ['expiryPolicyRef' => $reason],
			processedBy: 'system:expiry-batch',
			lifetimeDelta: 0
		);
	}//end expirePoints()

	/**
	 * Manual adjustment (signed delta).
	 *
	 * @param string $accountId The account UUID.
	 * @param int $delta Signed delta.
	 * @param string $reason Reason.
	 * @param string $processedBy Who processed.
	 *
	 * @return array<string, mixed> The ledger entry.
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
	 */
	public function adjustPoints(string $accountId, int $delta, string $reason, string $processedBy): array {
		if ($delta === 0) {
			throw new RuntimeException('Adjustment delta cannot be zero.');
		}

		return $this->appendAndUpdate(
			accountId: $accountId,
			type: 'adjustment',
			signedCount: $delta,
			ruleId: null,
			sourceDocument: ['reason' => $reason],
			processedBy: $processedBy,
			lifetimeDelta: max(0, $delta)
		);
	}//end adjustPoints()

	/**
	 * Refund a previous debit (e.g. redemption cancellation).
	 *
	 * @param string $accountId The account UUID.
	 * @param int $amount Positive amount to credit back.
	 * @param string $redemptionId The cancelled Redemption UUID.
	 * @param string $processedBy Who processed.
	 *
	 * @return array<string, mixed> The ledger entry.
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
	 */
	public function refundPoints(
		string $accountId,
		int $amount,
		string $redemptionId,
		string $processedBy,
	): array {
		if ($amount <= 0) {
			throw new RuntimeException('Refund amount must be positive.');
		}

		return $this->appendAndUpdate(
			accountId: $accountId,
			type: 'refund',
			signedCount: $amount,
			ruleId: null,
			sourceDocument: ['redemptionId' => $redemptionId, 'reason' => 'redemption cancelled'],
			processedBy: $processedBy,
			lifetimeDelta: 0
		);
	}//end refundPoints()

	/**
	 * Compute the live balance from the ledger (source of truth).
	 *
	 * @param string $accountId The account UUID.
	 *
	 * @return int The sum of all ledger entry amounts.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AggregationQuery::create() is OpenRegister's documented query-builder factory, not app state
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
	 */
	public function getAccountBalance(string $accountId): int {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '' || $accountId === '') {
			return 0;
		}

		// Push the full-ledger balance SUM down into OpenRegister: SUM over the
		// signed `aantal` column across every ledger entry for the account.
		// There is NO date window here, so the SQL SUM is exactly the prior PHP
		// sum over the unfiltered ledger history (verified live). On an empty
		// ledger the runner returns null, which casts to 0 — matching the prior
		// "no entries" result. Degrades to 0 when OpenRegister is unavailable,
		// mirroring getLedgerHistory()'s findAll-failure path.
		try {
			$query = AggregationQuery::create(
				metric: 'sum',
				field: 'count',
				filter: ['accountId' => $accountId],
			);
			$result = $this->getAggregationRunner()->runAdhocByRef(
				registerRef: $register,
				schemaRef: $schema,
				query: $query
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Pipelinq: ledger balance aggregation failed', ['exception' => $e->getMessage()]);
			return 0;
		}

		return (int)round((float)($result['value'] ?? 0));
	}//end getAccountBalance()

	/**
	 * Fetch the ledger history for an account, optionally bounded by date.
	 *
	 * @param string $accountId The account UUID.
	 * @param ?string $from ISO-8601 lower bound (inclusive) on timestamp.
	 * @param ?string $to ISO-8601 upper bound (inclusive) on timestamp.
	 *
	 * @return array<int, array<string, mixed>> The ledger entries, oldest first.
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function getLedgerHistory(string $accountId, ?string $from = null, ?string $to = null): array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '' || $accountId === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'accountId' => $accountId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 10000,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Pipelinq: ledger findAll failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$rowsToMap = [];
		if (is_array($rows) === true) {
			$rowsToMap = array_values($rows);
		}

		$rows = array_map([$this, 'toArray'], $rowsToMap);

		$filtered = array_filter(
			$rows,
			fn (array $entry): bool => $this->isWithinWindow(entry: $entry, from: $from, to: $to)
		);

		usort(
			$filtered,
			static fn (array $a, array $b): int => strcmp((string)($a['timestamp'] ?? ''), (string)($b['timestamp'] ?? ''))
		);

		return array_values($filtered);
	}//end getLedgerHistory()

	/**
	 * Whether a ledger entry's timestamp falls within an optional [from, to] window.
	 *
	 * @param array<string, mixed> $entry The ledger entry.
	 * @param ?string $from ISO-8601 lower bound (inclusive), or null.
	 * @param ?string $to ISO-8601 upper bound (inclusive), or null.
	 *
	 * @return bool
	 */
	private function isWithinWindow(array $entry, ?string $from, ?string $to): bool {
		$timestamp = (string)($entry['timestamp'] ?? '');
		if ($from !== null && $timestamp < $from) {
			return false;
		}

		if ($to !== null && $timestamp > $to) {
			return false;
		}

		return true;
	}//end isWithinWindow()

	/**
	 * Get ledger entries for a programme in a window (used by reporting).
	 *
	 * @param string $programmeId The programme UUID.
	 * @param string $type The entry type filter (credit/debit/expiry/etc.).
	 * @param ?string $from ISO-8601 lower bound on timestamp (inclusive).
	 * @param ?string $to ISO-8601 upper bound (inclusive).
	 *
	 * @return array<int, array<string, mixed>>
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
	 */
	public function getLedgerEntriesForProgramme(
		string $programmeId,
		string $type,
		?string $from = null,
		?string $to = null,
	): array {
		// Collect all accounts for the programme then fetch their ledgers.
		$accounts = $this->accountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

		$entries = [];
		foreach ($accounts as $account) {
			$accountId = (string)($account['accountId'] ?? $account['@self']['id'] ?? $account['uuid'] ?? '');
			if ($accountId === '') {
				continue;
			}

			$history = $this->getLedgerHistory(accountId: $accountId, from: $from, to: $to);
			foreach ($history as $e) {
				if ((string)($e['type'] ?? '') === $type) {
					$entries[] = $e;
				}
			}
		}

		return $entries;
	}//end getLedgerEntriesForProgramme()

	/**
	 * Append a ledger entry and atomically update the account balance.
	 *
	 * Atomicity: ledger entry is created first; on success the account is
	 * updated. If the account update fails we log the inconsistency but do NOT
	 * roll back the ledger (ledger is the source of truth — denormalised
	 * balance can be recomputed via getAccountBalance).
	 *
	 * @param string $accountId The account UUID.
	 * @param string $type One of credit/debit/expiry/adjustment/refund.
	 * @param int $signedCount Signed delta.
	 * @param ?string $ruleId Optional PointsRule UUID.
	 * @param array<string, mixed> $sourceDocument Source linkage.
	 * @param string $processedBy Processor identifier.
	 * @param int $lifetimeDelta Positive contribution to lifetimePoints (credits only).
	 *
	 * @return array<string, mixed> The ledger entry.
	 */
	private function appendAndUpdate(
		string $accountId,
		string $type,
		int $signedCount,
		?string $ruleId,
		array $sourceDocument,
		string $processedBy,
		int $lifetimeDelta,
	): array {
		$account = $this->accountService->getAccount(accountId: $accountId);
		if ($account === null) {
			throw new RuntimeException('Account not found.');
		}

		$currentBalance = (int)($account['currentBalance'] ?? 0);
		$newBalance = $currentBalance + $signedCount;
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

		$entry = [
			'accountId' => $accountId,
			'customerId' => $account['customerId'] ?? null,
			'type' => $type,
			'count' => $signedCount,
			'balanceAfter' => $newBalance,
			'sourceDocument' => $sourceDocument,
			'ruleId' => $ruleId,
			'timestamp' => $now,
			'processedBy' => $processedBy,
		];

		$saved = $this->persist(payload: $entry);

		try {
			$this->accountService->updateBalances(
				accountId: $accountId,
				newCurrentBalance: $newBalance,
				lifetimeDelta: $lifetimeDelta,
				lastActivityDate: $now
			);
		} catch (\Throwable $e) {
			// Ledger is source of truth; log inconsistency for reconciliation.
			$this->logger->error(
				'Pipelinq: account balance denorm update failed; ledger entry retained',
				['accountId' => $accountId, 'exception' => $e->getMessage()]
			);
		}

		return $saved;
	}//end appendAndUpdate()

	/**
	 * Persist a ledger entry (always create, never update — immutable).
	 *
	 * @param array<string, mixed> $payload The ledger entry payload.
	 *
	 * @return array<string, mixed> The saved entry.
	 */
	private function persist(array $payload): array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '') {
			throw new RuntimeException('PointsLedgerEntry schema is not configured.');
		}

		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: null
		);

		return $this->toArray(object: $saved);
	}//end persist()

	/**
	 * Resolve register + ledger schema IDs.
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
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'pointsLedgerEntry_schema', '');
		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning(
				'Pipelinq: register/pointsLedgerEntry_schema not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return [$registerId, $schemaId];
	}//end config()

	/**
	 * Normalise OR entity/array to a plain array.
	 *
	 * @param mixed $object The OR entity or array.
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
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Get the OpenRegister ad-hoc AggregationRunner.
	 *
	 * Constructor-injected the same way ObjectService is, so the full-ledger
	 * balance SUM is computed by OpenRegister (ADR-022) instead of hydrating the
	 * whole ledger and reducing in PHP. It was formerly resolved from the DI
	 * container inside a try/catch; since the migration to injection that catch
	 * was unreachable — phpstan reports it as a dead catch.
	 *
	 * @return object The aggregation runner.
	 */
	private function getAggregationRunner(): object {
		return $this->aggregationRunner;
	}//end getAggregationRunner()
}//end class
