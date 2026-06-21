<?php

/**
 * Pipelinq PointsLedgerService.
 *
 * Append-only ledger for KlantLoyaltyAccount points movements (credit, debit,
 * expiry, adjustment, refund). Ledger entries are immutable after creation; this
 * service NEVER calls updateObject on a PointsLedgerEntry. Account balance is
 * derived from the ledger and denormalised onto KlantLoyaltyAccount via
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Append-only points ledger.
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
 */
class PointsLedgerService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface    $container             The DI container.
     * @param IAppConfig            $appConfig             The app configuration.
     * @param LoyaltyAccountService $loyaltyAccountService The loyalty account service.
     * @param LoggerInterface       $logger                The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoyaltyAccountService $loyaltyAccountService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Credit points to an account (atomic ledger entry + balance update).
     *
     * @param string               $accountId    The account UUID.
     * @param int                  $amount       Positive integer points to credit.
     * @param ?string              $ruleId       The PointsRule UUID that produced the credit.
     * @param array<string, mixed> $brondocument Source linkage (transactionId etc.).
     * @param string               $verwerktDoor Who/what processed it (POS terminal id, system).
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
        array $brondocument,
        string $verwerktDoor
    ): array {
        if ($amount <= 0) {
            throw new RuntimeException('Credit amount must be positive.');
        }

        return $this->appendAndUpdate(
            accountId: $accountId,
            type: 'credit',
            signedAantal: $amount,
            ruleId: $ruleId,
            brondocument: $brondocument,
            verwerktDoor: $verwerktDoor,
            lifetimeDelta: $amount
        );
    }//end creditPoints()

    /**
     * Debit points (redemption-style).
     *
     * @param string               $accountId    The account UUID.
     * @param int                  $amount       Positive integer points to debit.
     * @param string               $redemptionId The Redemption UUID.
     * @param array<string, mixed> $brondocument Source linkage.
     * @param string               $verwerktDoor Who/what processed it.
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
        array $brondocument,
        string $verwerktDoor
    ): array {
        if ($amount <= 0) {
            throw new RuntimeException('Debit amount must be positive.');
        }

        $account = $this->loyaltyAccountService->getAccount(accountId: $accountId);
        if ($account === null) {
            throw new RuntimeException('Account not found.');
        }

        if ((int) ($account['currentBalance'] ?? 0) < $amount) {
            throw new RuntimeException('Insufficient balance.');
        }

        $brondocument['redemptionId'] = $redemptionId;

        return $this->appendAndUpdate(
            accountId: $accountId,
            type: 'debit',
            signedAantal: -$amount,
            ruleId: null,
            brondocument: $brondocument,
            verwerktDoor: $verwerktDoor,
            lifetimeDelta: 0
        );
    }//end debitPoints()

    /**
     * Expire points (system-initiated by PointsExpiryBatchJob).
     *
     * @param string $accountId The account UUID.
     * @param int    $amount    Positive integer to expire.
     * @param string $reason    Expiry reason (e.g. "12m inactivity").
     *
     * @return array<string, mixed> The ledger entry.
     *
     * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-005-01
     */
    public function expirePoints(string $accountId, int $amount, string $reason): array
    {
        if ($amount <= 0) {
            throw new RuntimeException('Expiry amount must be positive.');
        }

        return $this->appendAndUpdate(
            accountId: $accountId,
            type: 'expiry',
            signedAantal: -$amount,
            ruleId: null,
            brondocument: ['expiryPolicyRef' => $reason],
            verwerktDoor: 'system:expiry-batch',
            lifetimeDelta: 0
        );
    }//end expirePoints()

    /**
     * Manual adjustment (signed delta).
     *
     * @param string $accountId    The account UUID.
     * @param int    $delta        Signed delta.
     * @param string $reason       Reason.
     * @param string $verwerktDoor Who processed.
     *
     * @return array<string, mixed> The ledger entry.
     */
    public function adjustPoints(string $accountId, int $delta, string $reason, string $verwerktDoor): array
    {
        if ($delta === 0) {
            throw new RuntimeException('Adjustment delta cannot be zero.');
        }

        return $this->appendAndUpdate(
            accountId: $accountId,
            type: 'adjustment',
            signedAantal: $delta,
            ruleId: null,
            brondocument: ['reason' => $reason],
            verwerktDoor: $verwerktDoor,
            lifetimeDelta: max(0, $delta)
        );
    }//end adjustPoints()

    /**
     * Refund a previous debit (e.g. redemption cancellation).
     *
     * @param string $accountId    The account UUID.
     * @param int    $amount       Positive amount to credit back.
     * @param string $redemptionId The cancelled Redemption UUID.
     * @param string $verwerktDoor Who processed.
     *
     * @return array<string, mixed> The ledger entry.
     */
    public function refundPoints(
        string $accountId,
        int $amount,
        string $redemptionId,
        string $verwerktDoor
    ): array {
        if ($amount <= 0) {
            throw new RuntimeException('Refund amount must be positive.');
        }

        return $this->appendAndUpdate(
            accountId: $accountId,
            type: 'refund',
            signedAantal: $amount,
            ruleId: null,
            brondocument: ['redemptionId' => $redemptionId, 'reason' => 'redemption cancelled'],
            verwerktDoor: $verwerktDoor,
            lifetimeDelta: 0
        );
    }//end refundPoints()

    /**
     * Compute the live balance from the ledger (source of truth).
     *
     * @param string $accountId The account UUID.
     *
     * @return int The sum of all ledger entry amounts.
     */
    public function getAccountBalance(string $accountId): int
    {
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
            $query  = AggregationQuery::create(
                metric: 'sum',
                field: 'aantal',
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

        return (int) round((float) ($result['value'] ?? 0));
    }//end getAccountBalance()

    /**
     * Fetch the ledger history for an account, optionally bounded by date.
     *
     * @param string  $accountId The account UUID.
     * @param ?string $from      ISO-8601 lower bound (inclusive) on timestamp.
     * @param ?string $to        ISO-8601 upper bound (inclusive) on timestamp.
     *
     * @return array<int, array<string, mixed>> The ledger entries, oldest first.
     */
    public function getLedgerHistory(string $accountId, ?string $from=null, ?string $to=null): array
    {
        [$register, $schema] = $this->config();
        if ($register === '' || $schema === '' || $accountId === '') {
            return [];
        }

        try {
            $rows = $this->getObjectService()->findAll(
                filters: ['accountId' => $accountId],
                register: $register,
                schema: $schema,
                limit: 10000
            );
        } catch (\Throwable $e) {
            $this->logger->debug('Pipelinq: ledger findAll failed', ['exception' => $e->getMessage()]);
            return [];
        }

        if (is_array($rows) === true) {
            $rowsToMap = array_values($rows);
        } else {
            $rowsToMap = [];
        }

        $rows = array_map([$this, 'toArray'], $rowsToMap);

        $filtered = array_filter(
            $rows,
            static function (array $entry) use ($from, $to): bool {
                $ts = (string) ($entry['timestamp'] ?? '');
                if ($from !== null && $ts < $from) {
                    return false;
                }

                if ($to !== null && $ts > $to) {
                    return false;
                }

                return true;
            }
        );

        usort(
            $filtered,
            static fn(array $a, array $b): int => strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? ''))
        );

        return array_values($filtered);
    }//end getLedgerHistory()

    /**
     * Get ledger entries for a programme in a window (used by reporting).
     *
     * @param string  $programmeId The programme UUID.
     * @param string  $type        The entry type filter (credit/debit/expiry/etc.).
     * @param ?string $from        ISO-8601 lower bound on timestamp (inclusive).
     * @param ?string $to          ISO-8601 upper bound (inclusive).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLedgerEntriesForProgramme(
        string $programmeId,
        string $type,
        ?string $from=null,
        ?string $to=null
    ): array {
        // Collect all accounts for the programme then fetch their ledgers.
        $accounts = $this->loyaltyAccountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

        $entries = [];
        foreach ($accounts as $account) {
            $accountId = (string) ($account['accountId'] ?? $account['@self']['id'] ?? $account['uuid'] ?? '');
            if ($accountId === '') {
                continue;
            }

            $history = $this->getLedgerHistory(accountId: $accountId, from: $from, to: $to);
            foreach ($history as $e) {
                if ((string) ($e['type'] ?? '') === $type) {
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
     * @param string               $accountId     The account UUID.
     * @param string               $type          One of credit/debit/expiry/adjustment/refund.
     * @param int                  $signedAantal  Signed delta.
     * @param ?string              $ruleId        Optional PointsRule UUID.
     * @param array<string, mixed> $brondocument  Source linkage.
     * @param string               $verwerktDoor  Processor identifier.
     * @param int                  $lifetimeDelta Positive contribution to lifetimePoints (credits only).
     *
     * @return array<string, mixed> The ledger entry.
     */
    private function appendAndUpdate(
        string $accountId,
        string $type,
        int $signedAantal,
        ?string $ruleId,
        array $brondocument,
        string $verwerktDoor,
        int $lifetimeDelta
    ): array {
        $account = $this->loyaltyAccountService->getAccount(accountId: $accountId);
        if ($account === null) {
            throw new RuntimeException('Account not found.');
        }

        $currentBalance = (int) ($account['currentBalance'] ?? 0);
        $newBalance     = $currentBalance + $signedAantal;
        $now            = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

        $entry = [
            'accountId'    => $accountId,
            'klantId'      => $account['klantId'] ?? null,
            'type'         => $type,
            'aantal'       => $signedAantal,
            'balansNa'     => $newBalance,
            'brondocument' => $brondocument,
            'regelId'      => $ruleId,
            'timestamp'    => $now,
            'verwerktDoor' => $verwerktDoor,
        ];

        $saved = $this->persist(payload: $entry);

        try {
            $this->loyaltyAccountService->updateBalances(
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
    private function persist(array $payload): array
    {
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
     * @return array{0: string, 1: string}
     */
    private function config(): array
    {
        return [
            $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
            $this->appConfig->getValueString(Application::APP_ID, 'pointsLedgerEntry_schema', ''),
        ];
    }//end config()

    /**
     * Normalise OR entity/array to a plain array.
     *
     * @param mixed $object The OR entity or array.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $s = $object->jsonSerialize();
            if (is_array($s) === true) {
                return $s;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $d = $object->getObject();
            if (is_array($d) === true) {
                return $d;
            }
        }

        return [];
    }//end toArray()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister ObjectService is unavailable.', 0, $e);
        }
    }//end getObjectService()

    /**
     * Get the OpenRegister ad-hoc AggregationRunner.
     *
     * Resolved from the DI container the same way ObjectService is, so the
     * full-ledger balance SUM is computed by OpenRegister (ADR-022) instead of
     * hydrating the whole ledger and reducing in PHP.
     *
     * @return object The aggregation runner.
     *
     * @throws RuntimeException If OpenRegister is unavailable.
     */
    private function getAggregationRunner(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\Aggregation\AggregationRunner');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister aggregation runner is unavailable.', 0, $e);
        }
    }//end getAggregationRunner()
}//end class
