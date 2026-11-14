<?php

/**
 * Pipelinq LoyaltyEngineService.
 *
 * Orchestrates points awarding on a POS-transaction trigger: looks up the
 * customer's accounts, evaluates rules, applies tier multiplier, enforces
 * maxPerCustomerPerPeriod, credits points, and triggers tier reclassification.
 *
 * Failures NEVER halt the POS flow (REQ-LOY-002-05); errors are logged.
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
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\LoyaltyEngineServiceInterface;
use OCA\Pipelinq\Service\Loyalty\LoyaltyPurchase;
use OCA\Pipelinq\Service\Loyalty\PointsCreditRun;
use OCA\Pipelinq\Service\Loyalty\PointsGrant;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the end-to-end loyalty credit flow on a POS-trigger.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) end-to-end POS credit orchestration is inherently branchy; split across small focused methods
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   orchestrator necessarily wires account/ledger/rule/tier/event collaborators
 */
class LoyaltyEngineService implements LoyaltyEngineServiceInterface {
	use PayloadToArray;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoyaltyAccountService $accountService The account service.
	 * @param PointsLedgerService $ledgerService The ledger service.
	 * @param PointsRuleEngine $ruleEngine The rule engine.
	 * @param TierService $tierService The tier service.
	 * @param IEventDispatcher $eventDispatcher The dispatcher.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-084).
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoyaltyAccountService $accountService,
		private PointsLedgerService $ledgerService,
		private PointsRuleEngine $ruleEngine,
		private TierService $tierService,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Process a POS transaction (or any 'purchase' trigger) and award points.
	 *
	 * Idempotency: callers MUST pass a unique posTransactionId; the service
	 * uses it in the ledger entry brondocument. Duplicate calls produce
	 * additional ledger entries — at-most-once is the caller's responsibility.
	 *
	 * @param string $customerId The Nextcloud contact UID.
	 * @param array<string, mixed> $transaction Transaction context (amount,
	 *                                          category, channel, posTransactionId, segment,
	 *                                          timestamp, posTerminalId).
	 *
	 * @return array<int, array<string, mixed>> One result per programme: status
	 *                                          (credited / skipped / blocked / error),
	 *                                          accountId, points, tierChanged.
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function processPosTransaction(string $customerId, array $transaction): array {
		if ($customerId === '') {
			return [];
		}

		$purchase = new LoyaltyPurchase(customerId: $customerId, transaction: $transaction);
		$results = [];
		foreach ($this->getActiveProgrammes() as $programme) {
			$programmeId = $this->extractProgrammeUuid(programme: $programme);
			if ($programmeId !== null) {
				$results[] = $this->processSafely(purchase: $purchase, programmeId: $programmeId);
			}
		}

		return $results;
	}//end processPosTransaction()

	/**
	 * Process one programme; a failure is logged and answered, never thrown,
	 * so the POS flow is unaffected.
	 *
	 * @param LoyaltyPurchase $purchase    The customer and the transaction.
	 * @param string          $programmeId The programme UUID.
	 *
	 * @return array<string, mixed>
	 */
	private function processSafely(LoyaltyPurchase $purchase, string $programmeId): array {
		try {
			return $this->processForProgramme(purchase: $purchase, programmeId: $programmeId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: loyalty processing failed for programme; POS flow unaffected',
				['programmeId' => $programmeId, 'customerId' => $purchase->customerId, 'exception' => $e->getMessage()]
			);
			return [
				'programmeId' => $programmeId,
				'status' => 'error',
				'reason' => $e->getMessage(),
			];
		}
	}//end processSafely()

	/**
	 * Process for a single programme.
	 *
	 * @param LoyaltyPurchase $purchase    The customer and the transaction.
	 * @param string          $programmeId The programme UUID.
	 *
	 * @return array<string, mixed>
	 */
	private function processForProgramme(LoyaltyPurchase $purchase, string $programmeId): array {
		$account = $this->accountService->findAccountByKlantAndProgramme(
			customerId: $purchase->customerId,
			programmeId: $programmeId
		);

		if ($account === null) {
			// The customer hasn't opted in to this programme; skip silently.
			return [
				'programmeId' => $programmeId,
				'status' => 'skipped',
				'reason' => 'not enrolled',
			];
		}

		$run = new PointsCreditRun(
			programmeId: $programmeId,
			accountId: (string)$this->extractUuid(object: $account),
			transaction: $purchase->transaction,
			context: $this->buildTransactionContext(transaction: $purchase->transaction)
		);
		if ((string)($account['status'] ?? '') !== 'active') {
			$this->logger->info(
				'Pipelinq: account disabled, points credit skipped',
				['accountId' => $run->accountId, 'customerId' => $purchase->customerId]
			);
			return self::blockedResult(run: $run, account: $account);
		}

		return $this->awardPointsForActiveAccount(run: $run, account: $account);
	}//end processForProgramme()

	/**
	 * The answer for an account that is not active.
	 *
	 * @param PointsCreditRun      $run     The programme and account.
	 * @param array<string, mixed> $account The account, for its status.
	 *
	 * @return array<string, mixed>
	 */
	private static function blockedResult(PointsCreditRun $run, array $account): array {
		return [
			'programmeId' => $run->programmeId,
			'accountId' => $run->accountId,
			'status' => 'blocked',
			'reason' => 'account ' . (string)($account['status'] ?? 'unknown'),
		];
	}//end blockedResult()

	/**
	 * Evaluate rules and credit points for an already-resolved, active account.
	 *
	 * @param PointsCreditRun      $run     The programme, account, transaction and its rule context.
	 * @param array<string, mixed> $account The active loyalty account.
	 *
	 * @return array<string, mixed>
	 */
	private function awardPointsForActiveAccount(PointsCreditRun $run, array $account): array {
		$rule = $this->matchingRule(run: $run);
		if ($rule === null) {
			return [
				'programmeId' => $run->programmeId,
				'accountId' => $run->accountId,
				'status' => 'skipped',
				'reason' => 'no matching rule',
			];
		}

		$multiplier = $this->resolveTierMultiplier(account: $account, programmeId: $run->programmeId);
		$rawPoints = $this->ruleEngine->calculatePoints(
			formula: self::formulaOf(rule: $rule),
			amount: $run->context['amount'],
			multiplier: $multiplier
		);

		$toAward = $this->enforceMaxPerPeriod(rule: $rule, accountId: $run->accountId, rawPoints: $rawPoints);
		if ($toAward <= 0) {
			return $this->maxReached(run: $run, rule: $rule);
		}

		return $this->creditAndFinalize(run: $run, grant: new PointsGrant(rule: $rule, points: $toAward, multiplier: $multiplier));
	}//end awardPointsForActiveAccount()

	/**
	 * The highest-priority rule matching the transaction, or null.
	 *
	 * @param PointsCreditRun $run The programme, transaction and its rule context.
	 *
	 * @return array<string, mixed>|null
	 */
	private function matchingRule(PointsCreditRun $run): ?array {
		$matches = $this->ruleEngine->evaluateRules(
			programmeId: $run->programmeId,
			trigger: (string)($run->transaction['trigger'] ?? 'purchase'),
			context: $run->context
		);

		return $this->ruleEngine->getHighestPriorityRule($matches);
	}//end matchingRule()

	/**
	 * The rule's formula, an empty list when it carries none.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return array<string, mixed>
	 */
	private static function formulaOf(array $rule): array {
		$formula = $rule['formula'] ?? [];
		if (is_array($formula) === false) {
			return [];
		}

		return $formula;
	}//end formulaOf()

	/**
	 * Log and answer a credit the period maximum (or a zero formula) stopped.
	 *
	 * @param PointsCreditRun      $run  The programme and account.
	 * @param array<string, mixed> $rule The matched rule.
	 *
	 * @return array<string, mixed>
	 */
	private function maxReached(PointsCreditRun $run, array $rule): array {
		$this->logger->info(
			'Pipelinq: max-per-period reached or zero points; skipping credit',
			['accountId' => $run->accountId, 'ruleId' => $this->extractUuid(object: $rule)]
		);
		return [
			'programmeId' => $run->programmeId,
			'accountId' => $run->accountId,
			'status' => 'skipped',
			'reason' => 'max reached for this customer this period',
			'points' => 0,
		];
	}//end maxReached()

	/**
	 * Build the rule-evaluation context from the raw transaction payload.
	 *
	 * @param array<string, mixed> $transaction The transaction context.
	 *
	 * @return array<string, mixed>
	 */
	private function buildTransactionContext(array $transaction): array {
		return [
			'amount' => (float)($transaction['amount'] ?? 0),
			'category' => (string)($transaction['category'] ?? ''),
			'channel' => (string)($transaction['channel'] ?? ''),
			'segment' => (string)($transaction['segment'] ?? ''),
			'timestamp' => (string)($transaction['timestamp'] ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c')),
		];
	}//end buildTransactionContext()

	/**
	 * Resolve the tier multiplier for an account's current tier (REQ-LOY-003-03).
	 *
	 * @param array<string, mixed> $account The loyalty account.
	 * @param string $programmeId The programme UUID.
	 *
	 * @return float
	 */
	private function resolveTierMultiplier(array $account, string $programmeId): float {
		$multiplier = 1.0;
		$currentTierId = (string)($account['currentTierId'] ?? '');
		if ($currentTierId === '') {
			return $multiplier;
		}

		$tierRules = $this->tierService->getTierRules(programmeId: $programmeId);
		foreach ($tierRules as $tierRule) {
			if ((string)$this->extractUuid(object: $tierRule) === $currentTierId) {
				$multiplier = $this->tierService->applyTierBenefits(tier: $tierRule);
				break;
			}
		}

		return $multiplier;
	}//end resolveTierMultiplier()

	/**
	 * Enforce maxPerKlantPerPeriode and return the actually-awardable points.
	 *
	 * @param array<string, mixed> $rule The matched PointsRule.
	 * @param string $accountId The account UUID.
	 * @param int $rawPoints Points the formula would award.
	 *
	 * @return int
	 */
	private function enforceMaxPerPeriod(array $rule, string $accountId, int $rawPoints): int {
		$max = $rule['maxPerCustomerPerPeriod'] ?? null;
		$period = (string)($rule['maxPerCustomerPeriod'] ?? 'day');
		$already = 0;
		if ($max !== null && (int)$max > 0) {
			$already = $this->countAlreadyEarned(
				accountId: $accountId,
				ruleId: (string)$this->extractUuid(object: $rule),
				period: $period
			);
		}

		$maxInt = null;
		if ($max !== null) {
			$maxInt = (int)$max;
		}

		return $this->ruleEngine->applyMaxPerCustomer(
			earnedInPeriod: $already,
			pointsToAward: $rawPoints,
			max: $maxInt
		);
	}//end enforceMaxPerPeriod()

	/**
	 * Credit the ledger, re-evaluate tier, emit the event, and build the result.
	 *
	 * @param PointsCreditRun $run   The transaction, programme and account.
	 * @param PointsGrant     $grant The rule, points and multiplier to credit.
	 *
	 * @return array<string, mixed>
	 */
	private function creditAndFinalize(PointsCreditRun $run, PointsGrant $grant): array {
		$ledgerEntry = $this->ledgerService->forOperation(operationId: $run->operationId())->creditPoints(
			accountId: $run->accountId,
			amount: $grant->points,
			ruleId: $this->extractUuid(object: $grant->rule),
			sourceDocument: $run->sourceDocument(),
			processedBy: $run->processedBy()
		);

		// Re-evaluate tier.
		$tierResult = $this->tierService->updateTierIfNeeded(accountId: $run->accountId);

		$this->emitCreditedEvent(
			accountId: $run->accountId,
			programmeId: $run->programmeId,
			ledgerEntry: $ledgerEntry
		);

		return [
			'programmeId' => $run->programmeId,
			'accountId' => $run->accountId,
			'status' => 'credited',
			'points' => $grant->points,
			'ruleId' => $this->extractUuid(object: $grant->rule),
			'tierChanged' => $tierResult['changed'],
			'multiplier' => $grant->multiplier,
		];
	}//end creditAndFinalize()

	/**
	 * Get all programmes whose status is "active".
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getActiveProgrammes(): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'loyaltyProgramme_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'status' => 'active',
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 200,
				]
			);
		} catch (Throwable $e) {
			return [];
		}

		$values = [];
		if (is_array($rows) === true) {
			$values = array_values($rows);
		}

		return array_map([$this, 'toArray'], $values);
	}//end getActiveProgrammes()

	/**
	 * Count how many credit points were already earned in the period for this account + rule.
	 *
	 * @param string $accountId The account UUID.
	 * @param string $ruleId The rule UUID.
	 * @param string $period day|week|month|year.
	 *
	 * @return int Sum of |aantal| of matching ledger entries.
	 */
	private function countAlreadyEarned(string $accountId, string $ruleId, string $period): int {
		$from = $this->periodStart(period: $period);
		$history = $this->ledgerService->getLedgerHistory(accountId: $accountId, from: $from);
		$sum = 0;
		foreach ($history as $e) {
			if ((string)($e['type'] ?? '') === 'credit' && (string)($e['ruleId'] ?? '') === $ruleId) {
				$sum += (int)($e['count'] ?? 0);
			}
		}

		return $sum;
	}//end countAlreadyEarned()

	/**
	 * Compute the ISO start of a period bucket relative to now (UTC).
	 *
	 * @param string $period day|week|month|year.
	 *
	 * @return string ISO timestamp.
	 */
	private function periodStart(string $period): string {
		$tz = new DateTimeZone('UTC');
		$now = new DateTimeImmutable('now', $tz);
		switch ($period) {
			case 'week':
				$start = $now->modify('monday this week 00:00:00');
				break;
			case 'month':
				$start = $now->modify('first day of this month 00:00:00');
				break;
			case 'year':
				$start = $now->modify('first day of January ' . $now->format('Y') . ' 00:00:00');
				break;
			case 'day':
			default:
				$start = $now->setTime(0, 0, 0);
		}

		return $start->format('c');
	}//end periodStart()

	/**
	 * Emit a loyalty.points.credited event.
	 *
	 * @param string $accountId The account UUID.
	 * @param string $programmeId The programme UUID.
	 * @param array<string, mixed> $ledgerEntry The created PointsLedgerEntry.
	 *
	 * @return void
	 */
	private function emitCreditedEvent(string $accountId, string $programmeId, array $ledgerEntry): void {
		try {
			$event = new GenericEvent(
				null,
				[
					'type' => 'loyalty.points.credited',
					'accountId' => $accountId,
					'programmeId' => $programmeId,
					'count' => (int)($ledgerEntry['count'] ?? 0),
					'balanceAfter' => (int)($ledgerEntry['balanceAfter'] ?? 0),
				]
			);
			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: points-credited event dispatch failed', ['exception' => $e->getMessage()]);
		}
	}//end emitCreditedEvent()

	/**
	 * Extract programme UUID.
	 *
	 * @param array<string, mixed> $programme The programme.
	 *
	 * @return ?string
	 */
	private function extractProgrammeUuid(array $programme): ?string {
		$self = $programme['@self'] ?? [];
		if (is_array($self) === true && isset($self['id']) === true) {
			return (string)$self['id'];
		}

		return $programme['programmeId'] ?? $programme['uuid'] ?? $programme['id'] ?? null;
	}//end extractProgrammeUuid()

	/**
	 * Extract OR UUID.
	 *
	 * @param array<string, mixed> $object The OR object.
	 *
	 * @return ?string
	 */
	private function extractUuid(array $object): ?string {
		$self = $object['@self'] ?? [];
		if (is_array($self) === true && isset($self['id']) === true) {
			return (string)$self['id'];
		}

		return $object['uuid'] ?? $object['id'] ?? null;
	}//end extractUuid()

	/**
	 * Get the ObjectService.
	 *
	 * @return object
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
