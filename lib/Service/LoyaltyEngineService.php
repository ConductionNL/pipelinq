<?php

/**
 * Pipelinq LoyaltyEngineService.
 *
 * Orchestrates points awarding on a POS-transaction trigger: looks up the
 * customer's accounts, evaluates rules, applies tier multiplier, enforces
 * maxPerKlantPerPeriode, credits points, and triggers tier reclassification.
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\GenericEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the end-to-end loyalty credit flow on a POS-trigger.
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) end-to-end POS credit orchestration is inherently branchy; split across small focused methods
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   orchestrator necessarily wires account/ledger/rule/tier/event collaborators
 */
class LoyaltyEngineService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface    $container       The DI container.
     * @param IAppConfig            $appConfig       The app configuration.
     * @param LoyaltyAccountService $accountService  The account service.
     * @param PointsLedgerService   $ledgerService   The ledger service.
     * @param PointsRuleEngine      $ruleEngine      The rule engine.
     * @param TierService           $tierService     The tier service.
     * @param IEventDispatcher      $eventDispatcher The dispatcher.
     * @param LoggerInterface       $logger          The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoyaltyAccountService $accountService,
        private PointsLedgerService $ledgerService,
        private PointsRuleEngine $ruleEngine,
        private TierService $tierService,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Process a POS transaction (or any 'purchase' trigger) and award points.
     *
     * Idempotency: callers MUST pass a unique posTransactionId; the service
     * uses it in the ledger entry brondocument. Duplicate calls produce
     * additional ledger entries — at-most-once is the caller's responsibility.
     *
     * @param string               $klantId     The Nextcloud contact UID.
     * @param array<string, mixed> $transaction Transaction context (amount,
     *                                          category, channel, posTransactionId, segment,
     *                                          timestamp, posTerminalId).
     *
     * @return array<int, array<string, mixed>> One result per programme: status
     *                              (credited / skipped / blocked / error),
     *                              accountId, points, tierChanged.
     *
     * @spec exclude phpmd mechanical refactor
     */
    public function processPosTransaction(string $klantId, array $transaction): array
    {
        if ($klantId === '') {
            return [];
        }

        $results    = [];
        $programmes = $this->getActiveProgrammes();
        foreach ($programmes as $programme) {
            $programmeId = $this->extractProgrammeUuid(programme: $programme);
            if ($programmeId === null) {
                continue;
            }

            try {
                $results[] = $this->processForProgramme(
                    klantId: $klantId,
                    programmeId: $programmeId,
                    transaction: $transaction
                );
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Pipelinq: loyalty processing failed for programme; POS flow unaffected',
                    [
                        'programmeId' => $programmeId,
                        'klantId'     => $klantId,
                        'exception'   => $e->getMessage(),
                    ]
                );
                $results[] = [
                    'programmeId' => $programmeId,
                    'status'      => 'error',
                    'reason'      => $e->getMessage(),
                ];
            }//end try
        }//end foreach

        return $results;
    }//end processPosTransaction()

    /**
     * Process for a single programme.
     *
     * @param string               $klantId     The contact UID.
     * @param string               $programmeId The programme UUID.
     * @param array<string, mixed> $transaction The transaction context.
     *
     * @return array<string, mixed>
     */
    private function processForProgramme(
        string $klantId,
        string $programmeId,
        array $transaction
    ): array {
        $account = $this->accountService->findAccountByKlantAndProgramme(
            klantId: $klantId,
            programmeId: $programmeId
        );

        if ($account === null) {
            // The customer hasn't opted in to this programme; skip silently.
            return [
                'programmeId' => $programmeId,
                'status'      => 'skipped',
                'reason'      => 'not enrolled',
            ];
        }

        $accountId = (string) $this->extractUuid(object: $account);
        if ((string) ($account['status'] ?? '') !== 'actief') {
            $this->logger->info(
                'Pipelinq: account disabled, points credit skipped',
                ['accountId' => $accountId, 'klantId' => $klantId]
            );
            return [
                'programmeId' => $programmeId,
                'accountId'   => $accountId,
                'status'      => 'blocked',
                'reason'      => 'account '.(string) ($account['status'] ?? 'unknown'),
            ];
        }

        return $this->awardPointsForActiveAccount(
            programmeId: $programmeId,
            account: $account,
            accountId: $accountId,
            transaction: $transaction
        );
    }//end processForProgramme()

    /**
     * Evaluate rules and credit points for an already-resolved, active account.
     *
     * @param string               $programmeId The programme UUID.
     * @param array<string, mixed> $account     The active loyalty account.
     * @param string               $accountId   The account UUID.
     * @param array<string, mixed> $transaction The transaction context.
     *
     * @return array<string, mixed>
     */
    private function awardPointsForActiveAccount(string $programmeId, array $account, string $accountId, array $transaction): array
    {
        $context = $this->buildTransactionContext(transaction: $transaction);

        $matches = $this->ruleEngine->evaluateRules(
            programmeId: $programmeId,
            trigger: (string) ($transaction['trigger'] ?? 'purchase'),
            context: $context
        );
        $rule    = $this->ruleEngine->getHighestPriorityRule($matches);
        if ($rule === null) {
            return [
                'programmeId' => $programmeId,
                'accountId'   => $accountId,
                'status'      => 'skipped',
                'reason'      => 'no matching rule',
            ];
        }

        $multiplier = $this->resolveTierMultiplier(account: $account, programmeId: $programmeId);

        $formule = $rule['formule'] ?? [];
        if (is_array($formule) === false) {
            $formule = [];
        }

        $rawPoints = $this->ruleEngine->calculatePoints(
            formule: $formule,
            amount: $context['amount'],
            multiplier: $multiplier
        );

        $toAward = $this->enforceMaxPerPeriod(rule: $rule, accountId: $accountId, rawPoints: $rawPoints);

        if ($toAward <= 0) {
            $this->logger->info(
                'Pipelinq: max-per-period reached or zero points; skipping credit',
                ['accountId' => $accountId, 'ruleId' => $this->extractUuid(object: $rule)]
            );
            return [
                'programmeId' => $programmeId,
                'accountId'   => $accountId,
                'status'      => 'skipped',
                'reason'      => 'max reached for this customer this period',
                'points'      => 0,
            ];
        }

        return $this->creditAndFinalize(
            programmeId: $programmeId,
            accountId: $accountId,
            rule: $rule,
            toAward: $toAward,
            context: $context,
            transaction: $transaction,
            multiplier: $multiplier
        );
    }//end awardPointsForActiveAccount()

    /**
     * Build the rule-evaluation context from the raw transaction payload.
     *
     * @param array<string, mixed> $transaction The transaction context.
     *
     * @return array<string, mixed>
     */
    private function buildTransactionContext(array $transaction): array
    {
        return [
            'amount'    => (float) ($transaction['amount'] ?? 0),
            'category'  => (string) ($transaction['category'] ?? ''),
            'channel'   => (string) ($transaction['channel'] ?? ''),
            'segment'   => (string) ($transaction['segment'] ?? ''),
            'timestamp' => (string) ($transaction['timestamp'] ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c')),
        ];
    }//end buildTransactionContext()

    /**
     * Resolve the tier multiplier for an account's current tier (REQ-LOY-003-03).
     *
     * @param array<string, mixed> $account     The loyalty account.
     * @param string               $programmeId The programme UUID.
     *
     * @return float
     */
    private function resolveTierMultiplier(array $account, string $programmeId): float
    {
        $multiplier    = 1.0;
        $currentTierId = (string) ($account['currentTierId'] ?? '');
        if ($currentTierId === '') {
            return $multiplier;
        }

        $tierRules = $this->tierService->getTierRules(programmeId: $programmeId);
        foreach ($tierRules as $tierRule) {
            if ((string) $this->extractUuid(object: $tierRule) === $currentTierId) {
                $multiplier = $this->tierService->applyTierBenefits(tier: $tierRule);
                break;
            }
        }

        return $multiplier;
    }//end resolveTierMultiplier()

    /**
     * Enforce maxPerKlantPerPeriode and return the actually-awardable points.
     *
     * @param array<string, mixed> $rule      The matched PointsRule.
     * @param string               $accountId The account UUID.
     * @param int                  $rawPoints Points the formula would award.
     *
     * @return int
     */
    private function enforceMaxPerPeriod(array $rule, string $accountId, int $rawPoints): int
    {
        $max     = $rule['maxPerKlantPerPeriode'] ?? null;
        $period  = (string) ($rule['maxPerKlantPeriode'] ?? 'day');
        $already = 0;
        if ($max !== null && (int) $max > 0) {
            $already = $this->countAlreadyEarned(
                accountId: $accountId,
                ruleId: (string) $this->extractUuid(object: $rule),
                period: $period
            );
        }

        $maxInt = null;
        if ($max !== null) {
            $maxInt = (int) $max;
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
     * @param string               $programmeId The programme UUID.
     * @param string               $accountId   The account UUID.
     * @param array<string, mixed> $rule        The matched PointsRule.
     * @param int                  $toAward     Points to credit.
     * @param array<string, mixed> $context     The rule-evaluation context.
     * @param array<string, mixed> $transaction The transaction context.
     * @param float                $multiplier  The applied tier multiplier.
     *
     * @return array<string, mixed>
     */
    private function creditAndFinalize(
        string $programmeId,
        string $accountId,
        array $rule,
        int $toAward,
        array $context,
        array $transaction,
        float $multiplier
    ): array {
        $ledgerEntry = $this->ledgerService->creditPoints(
            accountId: $accountId,
            amount: $toAward,
            ruleId: $this->extractUuid(object: $rule),
            brondocument: [
                'transactionId' => $transaction['posTransactionId'] ?? null,
                'amount'        => $context['amount'],
                'channel'       => $context['channel'],
            ],
            verwerktDoor: (string) ($transaction['posTerminalId'] ?? 'system')
        );

        // Re-evaluate tier.
        $tierResult = $this->tierService->updateTierIfNeeded(accountId: $accountId);

        $this->emitCreditedEvent(
            accountId: $accountId,
            programmeId: $programmeId,
            ledgerEntry: $ledgerEntry
        );

        return [
            'programmeId' => $programmeId,
            'accountId'   => $accountId,
            'status'      => 'credited',
            'points'      => $toAward,
            'ruleId'      => $this->extractUuid(object: $rule),
            'tierChanged' => $tierResult['changed'],
            'multiplier'  => $multiplier,
        ];
    }//end creditAndFinalize()

    /**
     * Get all programmes whose status is "actief".
     *
     * @return array<int, array<string, mixed>>
     */
    private function getActiveProgrammes(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'loyaltyProgramme_schema', '');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $rows = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'status'   => 'actief',
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                    'limit'   => 200,
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
     * @param string $ruleId    The rule UUID.
     * @param string $period    day|week|month|year.
     *
     * @return int Sum of |aantal| of matching ledger entries.
     */
    private function countAlreadyEarned(string $accountId, string $ruleId, string $period): int
    {
        $from    = $this->periodStart(period: $period);
        $history = $this->ledgerService->getLedgerHistory(accountId: $accountId, from: $from);
        $sum     = 0;
        foreach ($history as $e) {
            if ((string) ($e['type'] ?? '') === 'credit' && (string) ($e['regelId'] ?? '') === $ruleId) {
                $sum += (int) ($e['aantal'] ?? 0);
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
    private function periodStart(string $period): string
    {
        $tz  = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $tz);
        switch ($period) {
            case 'week':
                $start = $now->modify('monday this week 00:00:00');
                break;
            case 'month':
                $start = $now->modify('first day of this month 00:00:00');
                break;
            case 'year':
                $start = $now->modify('first day of January '.$now->format('Y').' 00:00:00');
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
     * @param string               $accountId   The account UUID.
     * @param string               $programmeId The programme UUID.
     * @param array<string, mixed> $ledgerEntry The created PointsLedgerEntry.
     *
     * @return void
     */
    private function emitCreditedEvent(string $accountId, string $programmeId, array $ledgerEntry): void
    {
        try {
            $event = new GenericEvent(
                null,
                [
                    'type'        => 'loyalty.points.credited',
                    'accountId'   => $accountId,
                    'programmeId' => $programmeId,
                    'aantal'      => (int) ($ledgerEntry['aantal'] ?? 0),
                    'balansNa'    => (int) ($ledgerEntry['balansNa'] ?? 0),
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
    private function extractProgrammeUuid(array $programme): ?string
    {
        $self = $programme['@self'] ?? [];
        if (is_array($self) === true && isset($self['id']) === true) {
            return (string) $self['id'];
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
    private function extractUuid(array $object): ?string
    {
        $self = $object['@self'] ?? [];
        if (is_array($self) === true && isset($self['id']) === true) {
            return (string) $self['id'];
        }

        return $object['uuid'] ?? $object['id'] ?? null;
    }//end extractUuid()

    /**
     * Normalise OR entity/array to array.
     *
     * @param mixed $object The OR entity or array.
     *
     * @return array<string, mixed>
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) invoked via array_map([$this, 'toArray'], ...) callable reference, not statically detected
     */
    private function toArray(mixed $object): array
    {
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
     * Get the ObjectService.
     *
     * @return object
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            throw new RuntimeException('OpenRegister ObjectService is unavailable.', 0, $e);
        }
    }//end getObjectService()
}//end class
