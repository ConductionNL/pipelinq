<?php

/**
 * Pipelinq PosBookkeepingService.
 *
 * Orchestrates the POS end-of-day bookkeeping pipeline:
 * Z-report aggregation, outbound message staging, and idempotent
 * submission to Shillinq with exponential backoff retry.
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
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for POS end-of-day bookkeeping operations.
 *
 * Provides the three-stage pipeline:
 *  1. generateZReport()      – aggregate posTransaction objects into a posZReport
 *  2. createOutboundMessage() – stage posJournalEntryOutbound with GL mapping
 *  3. postToShillinq()        – submit with idempotency + exponential backoff
 *
 * All CRUD is delegated to OpenRegister via the container-resolved
 * ObjectService. No custom database tables are used.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Legitimate service-layer coupling for
 *  a multi-stage pipeline: OR container, app config, HTTP client, logger. Splitting
 *  would scatter one cohesive accounting concern across multiple classes.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class covers the complete
 *  bookkeeping pipeline (Z-report generation, GL mapping, HTTP submission, retry
 *  scheduling, CloudEvent emission). Each stage is a single-purpose method with
 *  focused test coverage; the breadth reflects the feature scope, not tangled logic.
 * @SuppressWarnings(PHPMD.TooManyMethods)           Each public method corresponds to one
 *  named pipeline stage plus helpers for GL transformation, CloudEvent emission and
 *  retry scheduling. All are tested individually; collapsing them would hide seams.
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
 */
class PosBookkeepingService
{
    /**
     * CloudEvent type emitted on successful journal entry posting.
     *
     * @var string
     */
    public const EVENT_JOURNAL_POSTED = 'pipelinq.PosJournalEntry.posted';

    /**
     * CloudEvent type emitted on Z-report submission.
     *
     * @var string
     */
    public const EVENT_ZREPORT_SUBMITTED = 'pipelinq.PosZReport.submitted';

    /**
     * CloudEvents source identifier for this app's POS bookkeeping surface.
     *
     * @var string
     */
    private const EVENT_SOURCE = '/apps/pipelinq/pos/bookkeeping';

    /**
     * Maximum number of submission attempts before permanent failure.
     *
     * @var int
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Exponential backoff schedule in seconds (1 min, 5 min, 15 min, 1 hr).
     *
     * Index corresponds to attemptCount after failure (0-based). If index
     * exceeds the array length and MAX_ATTEMPTS is not yet reached, the last
     * value (3600) is reused.
     *
     * @var array<int, int>
     */
    public const BACKOFF_SECONDS = [60, 300, 900, 3600];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (resolves ObjectService at runtime).
     * @param IAppConfig         $appConfig Application configuration (sensitive token storage).
     * @param LoggerInterface    $logger    PSR logger.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    // -----------------------------------------------------------------------
    // Stage 1: Z-report generation
    // -----------------------------------------------------------------------

    /**
     * Aggregate confirmed/settled posTransaction objects for a date into a posZReport.
     *
     * Queries all posTransaction objects with status 'confirmed' or 'settled' whose
     * `transactionDate` (or `createdAt`) matches the given ISO date. Groups by
     * terminalId when $terminalId is null (creates one Z-report per terminal).
     * When $terminalId is provided, creates one Z-report for that terminal only.
     *
     * Empty days (no transactions) produce a draft Z-report with zero totals.
     *
     * @param string      $reportDate ISO date in YYYY-MM-DD format.
     * @param string|null $terminalId Optional terminal filter; null = all terminals.
     *
     * @return string[] Array of created posZReport UUIDs (one per terminal).
     *
     * @throws OCSBadRequestException If reportDate format is invalid.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function generateZReport(string $reportDate, ?string $terminalId): array
    {
        // Validate date format.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate) !== 1) {
            throw new OCSBadRequestException('Invalid reportDate format; expected YYYY-MM-DD');
        }

        $objectService = $this->getObjectService();

        // Fetch all confirmed/settled transactions for the date.
        $transactions = $this->fetchTransactionsForDate(
            objectService: $objectService,
            reportDate: $reportDate,
            terminalId: $terminalId
        );

        // Group by terminalId.
        $grouped = $this->groupTransactionsByTerminal(transactions: $transactions);

        // When filtering by terminal but no transactions found, still create one empty report.
        if ($terminalId !== null && isset($grouped[$terminalId]) === false) {
            $grouped[$terminalId] = [];
        }

        // Edge case: no transactions at all and no terminal filter → create one generic report.
        if (count($grouped) === 0) {
            $grouped[''] = [];
        }

        $createdIds = [];

        foreach ($grouped as $terminal => $txns) {
            $terminalArg = null;
            if ($terminal !== '') {
                $terminalArg = $terminal;
            }

            $uuid         = $this->createZReportObject(
                objectService: $objectService,
                reportDate: $reportDate,
                terminalId: $terminalArg,
                transactions: $txns
            );
            $createdIds[] = $uuid;

            // Automatically create outbound message for non-empty ready reports.
            try {
                $this->createOutboundMessage(zReportId: $uuid);
            } catch (\Throwable $e) {
                // Log but do not block Z-report creation.
                $this->logger->warning(
                    'PosBookkeepingService: failed to create outbound message for Z-report {uuid}',
                    ['uuid' => $uuid, 'exception' => $e->getMessage()]
                );
            }
        }//end foreach

        return $createdIds;
    }//end generateZReport()

    /**
     * Fetch posTransaction objects for a given date (confirmed or settled).
     *
     * @param object      $objectService The OR ObjectService.
     * @param string      $reportDate    The date to filter.
     * @param string|null $terminalId    Optional terminal filter.
     *
     * @return array<int, array<string, mixed>> Transaction objects.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    private function fetchTransactionsForDate(object $objectService, string $reportDate, ?string $terminalId): array
    {
        $filters = [
            'register' => Application::APP_ID,
            'schema'   => 'posTransaction',
            'filters'  => [
                ['field' => 'status', 'operator' => 'in', 'value' => ['confirmed', 'settled']],
                ['field' => 'transactionDate', 'operator' => 'eq', 'value' => $reportDate],
            ],
            'limit'    => 1000,
            'offset'   => 0,
        ];

        if ($terminalId !== null) {
            $filters['filters'][] = ['field' => 'terminalId', 'operator' => 'eq', 'value' => $terminalId];
        }

        try {
            $result = $objectService->findAll(filters: $filters);
            if (is_array($result) === false) {
                return [];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'PosBookkeepingService: could not fetch transactions for {date}',
                ['date' => $reportDate, 'exception' => $e->getMessage()]
            );
            return [];
        }
    }//end fetchTransactionsForDate()

    /**
     * Group transaction objects by their terminalId property.
     *
     * @param array<int, array<string, mixed>> $transactions The transactions.
     *
     * @return array<string, array<int, array<string, mixed>>> Grouped by terminalId.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    private function groupTransactionsByTerminal(array $transactions): array
    {
        $grouped = [];
        foreach ($transactions as $txn) {
            $terminal = '';
            if (isset($txn['terminalId']) === true) {
                $terminal = (string) $txn['terminalId'];
            }

            if (isset($grouped[$terminal]) === false) {
                $grouped[$terminal] = [];
            }

            $grouped[$terminal][] = $txn;
        }

        return $grouped;
    }//end groupTransactionsByTerminal()

    /**
     * Compute aggregate totals from a list of transactions.
     *
     * @param array<int, array<string, mixed>> $transactions The transactions.
     *
     * @return array<string, mixed> Aggregated totals: subtotal, discountTotal, taxBreakdown, totalTax, total,
     *                              paymentMethodBreakdown, transactionIds.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function computeAggregates(array $transactions): array
    {
        $subtotal        = 0.0;
        $discountTotal   = 0.0;
        $totalTax        = 0.0;
        $total           = 0.0;
        $taxBreakdownAcc = [];
        $paymentMethodBreakdown = [];
        $transactionIds         = [];

        foreach ($transactions as $txn) {
            $subtotal      += (float) ($txn['subtotal'] ?? 0);
            $discountTotal += (float) ($txn['discountTotal'] ?? 0);
            $totalTax      += (float) ($txn['totalTax'] ?? 0);
            $total         += (float) ($txn['total'] ?? 0);

            if (isset($txn['id']) === true) {
                $transactionIds[] = (string) $txn['id'];
            }

            // Aggregate tax breakdown.
            if (isset($txn['taxBreakdown']) === true && is_array($txn['taxBreakdown']) === true) {
                foreach ($txn['taxBreakdown'] as $entry) {
                    $rate = (string) ($entry['rate'] ?? 0);
                    if (isset($taxBreakdownAcc[$rate]) === false) {
                        $taxBreakdownAcc[$rate] = ['rate' => (float) $rate, 'base' => 0.0, 'tax' => 0.0];
                    }

                    $taxBreakdownAcc[$rate]['base'] += (float) ($entry['base'] ?? 0);
                    $taxBreakdownAcc[$rate]['tax']  += (float) ($entry['tax'] ?? 0);
                }
            }

            // Aggregate payment method breakdown.
            if (isset($txn['paymentMethod']) === true) {
                $method = (string) $txn['paymentMethod'];
                if (isset($paymentMethodBreakdown[$method]) === false) {
                    $paymentMethodBreakdown[$method] = 0.0;
                }

                $paymentMethodBreakdown[$method] += (float) ($txn['total'] ?? 0);
            }
        }//end foreach

        // Round all monetary values.
        $taxBreakdown = [];
        foreach ($taxBreakdownAcc as $entry) {
            $taxBreakdown[] = [
                'rate' => round($entry['rate'], 2),
                'base' => round($entry['base'], 2),
                'tax'  => round($entry['tax'], 2),
            ];
        }

        $paymentBreakdownArr = [];
        foreach ($paymentMethodBreakdown as $method => $amount) {
            $paymentBreakdownArr[] = ['method' => $method, 'amount' => round($amount, 2)];
        }

        return [
            'subtotal'               => round($subtotal, 2),
            'discountTotal'          => round($discountTotal, 2),
            'taxBreakdown'           => $taxBreakdown,
            'totalTax'               => round($totalTax, 2),
            'total'                  => round($total, 2),
            'paymentMethodBreakdown' => $paymentBreakdownArr,
            'transactionIds'         => $transactionIds,
        ];
    }//end computeAggregates()

    /**
     * Create and persist a posZReport object in OpenRegister.
     *
     * @param object                           $objectService The OR ObjectService.
     * @param string                           $reportDate    The settlement date.
     * @param string|null                      $terminalId    The terminal ID, or null for all terminals.
     * @param array<int, array<string, mixed>> $transactions  The transactions to aggregate.
     *
     * @return string The UUID of the created posZReport.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    private function createZReportObject(
        object $objectService,
        string $reportDate,
        ?string $terminalId,
        array $transactions
    ): string {
        $aggregates     = $this->computeAggregates(transactions: $transactions);
        $terminalSuffix = 'ALL';
        if ($terminalId !== null) {
            $terminalSuffix = strtoupper(str_replace(search: '-', replace: '', subject: $terminalId));
        }

        $reference       = sprintf('Z-%s-%s', $reportDate, $terminalSuffix);
        $hasTransactions = count($transactions) > 0;
        $status          = 'draft';
        if ($hasTransactions === true) {
            $status = 'ready';
        }

        $data = [
            'reference'              => $reference,
            'reportDate'             => $reportDate,
            'terminalId'             => $terminalId,
            'transactionCount'       => count($transactions),
            'subtotal'               => $aggregates['subtotal'],
            'discountTotal'          => $aggregates['discountTotal'],
            'taxBreakdown'           => $aggregates['taxBreakdown'],
            'totalTax'               => $aggregates['totalTax'],
            'total'                  => $aggregates['total'],
            'paymentMethodBreakdown' => $aggregates['paymentMethodBreakdown'],
            'transactionIds'         => $aggregates['transactionIds'],
            'status'                 => $status,
        ];

        $created = $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'posZReport',
            object: $data
        );

        if (is_array($created) === false || isset($created['id']) === false) {
            throw new \RuntimeException('Failed to create posZReport object');
        }

        $this->logger->info(
            'PosBookkeepingService: created Z-report {ref} with {count} transactions (total: {total} EUR)',
            [
                'ref'   => $reference,
                'count' => count($transactions),
                'total' => $aggregates['total'],
            ]
        );

        return (string) $created['id'];
    }//end createZReportObject()

    // -----------------------------------------------------------------------
    // Stage 2: Outbound message staging
    // -----------------------------------------------------------------------

    /**
     * Create a posJournalEntryOutbound staging object from a posZReport.
     *
     * Loads the Z-report, fetches the default glAccountMapping, computes the
     * idempotency key (SHA256 of uuid+reportDate), transforms tax breakdown into
     * balanced GL ledger line items, and persists the outbound record.
     *
     * @param string $zReportId The UUID of the posZReport.
     *
     * @return string The UUID of the created posJournalEntryOutbound.
     *
     * @throws OCSNotFoundException    If the Z-report does not exist.
     * @throws OCSBadRequestException  If GL mapping is incomplete.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function createOutboundMessage(string $zReportId): string
    {
        $objectService = $this->getObjectService();

        // Load the Z-report.
        $zReport = $this->loadZReport(objectService: $objectService, id: $zReportId);

        // Skip empty draft reports.
        $total        = (float) ($zReport['total'] ?? 0);
        $reportStatus = 'draft';
        if (isset($zReport['status']) === true) {
            $reportStatus = (string) $zReport['status'];
        }

        if ($total === 0.0 && $reportStatus === 'draft') {
            throw new OCSBadRequestException('Cannot create outbound message for empty draft Z-report');
        }

        // Load GL mapping.
        $glMapping = $this->loadDefaultGlMapping(objectService: $objectService);
        if ($glMapping === null) {
            $this->sendAlertEmail(
                outboundMessageId: null,
                errorMessage: 'Geen standaard GB-rekeningkoppeling geconfigureerd. Dagboekpost kon niet worden aangemaakt.'
            );
            throw new OCSBadRequestException('No default GL account mapping configured');
        }

        // Compute idempotency key.
        $reportDate = '';
        if (isset($zReport['reportDate']) === true) {
            $reportDate = (string) $zReport['reportDate'];
        }

        $idempotencyKey = $this->computeIdempotencyKey(zReportId: $zReportId, reportDate: $reportDate);

        // Build GL ledger line items.
        $ledgerLineItems = $this->buildLedgerLineItems(zReport: $zReport, glMapping: $glMapping);

        $data = [
            'zReport'            => $zReportId,
            'idempotencyKey'     => $idempotencyKey,
            'payloadVersion'     => 1,
            'ledgerLineItems'    => $ledgerLineItems,
            'postingDate'        => $zReport['reportDate'] ?? null,
            'status'             => 'draft',
            'submissionAttempts' => [],
            'attemptCount'       => 0,
        ];

        $created = $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'posJournalEntryOutbound',
            object: $data
        );

        if (is_array($created) === false || isset($created['id']) === false) {
            throw new \RuntimeException('Failed to create posJournalEntryOutbound object');
        }

        $this->logger->info(
            'PosBookkeepingService: created outbound message {id} for Z-report {zReport}',
            ['id' => $created['id'], 'zReport' => $zReportId]
        );

        return (string) $created['id'];
    }//end createOutboundMessage()

    /**
     * Compute the SHA256 idempotency key for a Z-report.
     *
     * The key is deterministic: same inputs always produce the same hash.
     * Prefixed with 'sha256:' for transparency.
     *
     * @param string $zReportId  The Z-report UUID.
     * @param string $reportDate The settlement date (YYYY-MM-DD).
     *
     * @return string The idempotency key (sha256:hex).
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function computeIdempotencyKey(string $zReportId, string $reportDate): string
    {
        return 'sha256:'.hash('sha256', $zReportId.$reportDate);
    }//end computeIdempotencyKey()

    /**
     * Build balanced GL ledger line items from a Z-report and GL mapping.
     *
     * For each tax rate in taxBreakdown, creates:
     *   - Debit: revenue account (credit account in mapping), full amount
     *   - Credit: bank clearing account (bankAccount in mapping)
     * Ensures debit total === credit total for double-entry bookkeeping.
     *
     * @param array<string, mixed> $zReport   The posZReport data.
     * @param array<string, mixed> $glMapping The glAccountMapping data.
     *
     * @return array<int, array<string, mixed>> The ledger line items.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function buildLedgerLineItems(array $zReport, array $glMapping): array
    {
        $total       = (float) ($zReport['total'] ?? 0);
        $bankAccount = (string) ($glMapping['bankAccount'] ?? '1000');
        $lines       = [];

        // Per-tax-rate revenue lines.
        $taxBreakdown = [];
        if (isset($zReport['taxBreakdown']) === true && is_array($zReport['taxBreakdown']) === true) {
            $taxBreakdown = $zReport['taxBreakdown'];
        }

        $taxRateMappings = [];
        if (isset($glMapping['taxRateMappings']) === true && is_array($glMapping['taxRateMappings']) === true) {
            $taxRateMappings = $glMapping['taxRateMappings'];
        }

        // Index GL mapping by taxRate for O(1) lookup.
        $mappingByRate = [];
        foreach ($taxRateMappings as $mapping) {
            $rate = (string) ($mapping['taxRate'] ?? '');
            $mappingByRate[$rate] = $mapping;
        }

        foreach ($taxBreakdown as $entry) {
            $rate          = (string) ($entry['rate'] ?? '');
            $base          = (float) ($entry['base'] ?? 0);
            $tax           = (float) ($entry['tax'] ?? 0);
            $lineTotal     = round($base + $tax, 2);
            $rateMapping   = $mappingByRate[$rate] ?? null;
            $debitAccount  = '1200';
            $creditAccount = '5000';
            if (isset($rateMapping['debitAccount']) === true) {
                $debitAccount = (string) $rateMapping['debitAccount'];
            }

            if (isset($rateMapping['creditAccount']) === true) {
                $creditAccount = (string) $rateMapping['creditAccount'];
            }

            $description = sprintf(
                'POS-omzet - %s%% btw',
                $rate
            );

            // Debit: accounts receivable / cash account.
            $lines[] = [
                'account'     => $debitAccount,
                'debit'       => $lineTotal,
                'credit'      => 0.0,
                'description' => $description,
                'taxRate'     => (float) $rate,
            ];

            // Credit: revenue account.
            $lines[] = [
                'account'     => $creditAccount,
                'debit'       => 0.0,
                'credit'      => $lineTotal,
                'description' => $description.' (omzet)',
                'taxRate'     => (float) $rate,
            ];
        }//end foreach

        // If no tax breakdown, use one summary line for the full total.
        if (count($taxBreakdown) === 0 && $total > 0) {
            $lines[] = [
                'account'     => '1200',
                'debit'       => $total,
                'credit'      => 0.0,
                'description' => 'POS-omzet',
                'taxRate'     => null,
            ];
            $lines[] = [
                'account'     => '5000',
                'debit'       => 0.0,
                'credit'      => $total,
                'description' => 'POS-omzet (omzet)',
                'taxRate'     => null,
            ];
        }

        // Add bank clearing line to balance debits vs credits.
        if ($total > 0) {
            $lines[] = [
                'account'     => $bankAccount,
                'debit'       => $total,
                'credit'      => 0.0,
                'description' => 'Bank/Kas Verrekening',
                'taxRate'     => null,
            ];
            // Counter-entry: debit totals from revenue lines back out via the
            // bank clearing.  We neutralise by a single credit on 1200.
            $lines[] = [
                'account'     => '1200',
                'debit'       => 0.0,
                'credit'      => $total,
                'description' => 'Bank/Kas Verrekening (tegenboeking)',
                'taxRate'     => null,
            ];
        }

        return $lines;
    }//end buildLedgerLineItems()

    // -----------------------------------------------------------------------
    // Stage 3: Shillinq submission
    // -----------------------------------------------------------------------

    /**
     * POST a posJournalEntryOutbound to Shillinq with idempotency.
     *
     * Validates the outbound message status (must be draft or failed),
     * constructs the JSON payload, submits via HTTP POST with the
     * X-Idempotency-Key and Authorization headers.
     *
     * On 202/201: marks as posted, stores Shillinq event ID, emits CloudEvent.
     * On 4xx:     marks as failed, sends alert email, does NOT retry.
     * On 5xx / timeout: marks as failed, schedules exponential backoff retry.
     *
     * @param string $outboundMessageId The UUID of the posJournalEntryOutbound.
     *
     * @return array<string, mixed> Response array with status and metadata.
     *
     * @throws OCSNotFoundException   If the outbound message does not exist.
     * @throws OCSBadRequestException If the outbound message is not in draft or failed state.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function postToShillinq(string $outboundMessageId): array
    {
        $objectService = $this->getObjectService();

        // Load outbound message.
        $outbound = $this->loadOutboundMessage(objectService: $objectService, id: $outboundMessageId);

        $status = (string) ($outbound['status'] ?? 'draft');
        if (in_array(needle: $status, haystack: ['draft', 'failed'], strict: true) === false) {
            throw new OCSBadRequestException(
                sprintf("Outbound message %s has status '%s'; only draft or failed can be resubmitted", $outboundMessageId, $status)
            );
        }

        // Load Z-report.
        $zReportId = (string) ($outbound['zReport'] ?? '');
        $zReport   = $this->loadZReport(objectService: $objectService, id: $zReportId);

        // Prepare submission.
        $idempotencyKey = (string) ($outbound['idempotencyKey'] ?? '');
        $endpoint       = rtrim((string) $this->appConfig->getValueString(Application::APP_ID, 'shillinq_endpoint', ''), '/');
        $token          = (string) $this->appConfig->getValueString(Application::APP_ID, 'shillinq_token', '');

        if ($endpoint === '') {
            throw new OCSBadRequestException('Shillinq endpoint is not configured');
        }

        $payload = [
            'idempotencyKey'  => $idempotencyKey,
            'postingDate'     => $outbound['postingDate'] ?? null,
            'reference'       => $zReport['reference'] ?? null,
            'ledgerLineItems' => $outbound['ledgerLineItems'] ?? [],
        ];

        $timestamp    = (new DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $attemptCount = (int) ($outbound['attemptCount'] ?? 0) + 1;

        // Update attempt count before calling.
        $outbound['attemptCount']  = $attemptCount;
        $outbound['lastAttemptAt'] = $timestamp;
        $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'posJournalEntryOutbound',
            object: array_merge($outbound, ['id' => $outboundMessageId])
        );

        // Execute HTTP POST.
        try {
            $response = $this->httpPost(
                url: $endpoint.'/api/JournalEntry',
                payload: $payload,
                idempotencyKey: $idempotencyKey,
                token: $token
            );
        } catch (\Throwable $e) {
            // Network timeout or connection failure.
            return $this->handleSubmissionError(
                objectService: $objectService,
                outbound: $outbound,
                outboundMessageId: $outboundMessageId,
                zReportId: $zReportId,
                statusCode: 0,
                message: $e->getMessage(),
                errorCode: 'NETWORK_TIMEOUT',
                attemptCount: $attemptCount
            );
        }

        $httpStatus   = (int) $response['status'];
        $responseBody = $response['body'] ?? [];

        if ($httpStatus >= 200 && $httpStatus < 300) {
            return $this->handleSubmissionSuccess(
                objectService: $objectService,
                outbound: $outbound,
                outboundMessageId: $outboundMessageId,
                zReportId: $zReportId,
                httpStatus: $httpStatus,
                responseBody: $responseBody,
                idempotencyKey: $idempotencyKey,
                timestamp: $timestamp
            );
        }

        if ($httpStatus >= 400 && $httpStatus < 500) {
            // Client error — do not retry.
            return $this->handleSubmissionError(
                objectService: $objectService,
                outbound: $outbound,
                outboundMessageId: $outboundMessageId,
                zReportId: $zReportId,
                statusCode: $httpStatus,
                message: (string) ($responseBody['message'] ?? 'Client error'),
                errorCode: (string) $httpStatus,
                attemptCount: $attemptCount,
                retry: false
            );
        }

        // Server error (5xx) — schedule retry.
        return $this->handleSubmissionError(
            objectService: $objectService,
            outbound: $outbound,
            outboundMessageId: $outboundMessageId,
            zReportId: $zReportId,
            statusCode: $httpStatus,
            message: (string) ($responseBody['message'] ?? 'Server error'),
            errorCode: (string) $httpStatus,
            attemptCount: $attemptCount,
            retry: true
        );
    }//end postToShillinq()

    /**
     * Handle a successful Shillinq submission.
     *
     * @param object               $objectService     The OR ObjectService.
     * @param array<string, mixed> $outbound          The outbound message data.
     * @param string               $outboundMessageId The outbound message UUID.
     * @param string               $zReportId         The Z-report UUID.
     * @param int                  $httpStatus        The HTTP status code received.
     * @param array<string, mixed> $responseBody      The parsed response body.
     * @param string               $idempotencyKey    The idempotency key used.
     * @param string               $timestamp         The submission timestamp.
     *
     * @return array<string, mixed> Success response metadata.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    private function handleSubmissionSuccess(
        object $objectService,
        array $outbound,
        string $outboundMessageId,
        string $zReportId,
        int $httpStatus,
        array $responseBody,
        string $idempotencyKey,
        string $timestamp
    ): array {
        $shillinqEventId        = (string) ($responseBody['eventId'] ?? $responseBody['id'] ?? '');
        $shillinqJournalEntryId = (string) ($responseBody['journalEntryId'] ?? $responseBody['id'] ?? '');

        // Append attempt.
        $attempts = [];
        if (isset($outbound['submissionAttempts']) === true && is_array($outbound['submissionAttempts']) === true) {
            $attempts = $outbound['submissionAttempts'];
        }

        $attempts[] = [
            'timestamp' => $timestamp,
            'status'    => $httpStatus,
            'message'   => 'Accepted',
            'eventId'   => $shillinqEventId,
        ];

        // Update outbound message.
        $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'posJournalEntryOutbound',
            object: array_merge(
                    $outbound,
                    [
                        'id'                     => $outboundMessageId,
                        'status'                 => 'posted',
                        'submissionAttempts'     => $attempts,
                        'lastAttemptAt'          => $timestamp,
                        'nextRetryAt'            => null,
                        'shillinqEventId'        => $shillinqEventId,
                        'shillinqJournalEntryId' => $shillinqJournalEntryId,
                    ]
                    )
        );

        // Update Z-report status to posted.
        $this->updateZReportStatus(objectService: $objectService, zReportId: $zReportId, status: 'posted');

        // Emit CloudEvent.
        $this->emitPostedEvent(outboundMessageId: $outboundMessageId);

        $this->logger->info(
            'PosBookkeepingService: outbound message {id} posted successfully to Shillinq',
            ['id' => $outboundMessageId, 'shillinqEventId' => $shillinqEventId]
        );

        return [
            'status'          => 'posted',
            'idempotencyKey'  => $idempotencyKey,
            'shillinqEventId' => $shillinqEventId,
        ];
    }//end handleSubmissionSuccess()

    /**
     * Handle a failed Shillinq submission (4xx, 5xx, or network timeout).
     *
     * @param object               $objectService     The OR ObjectService.
     * @param array<string, mixed> $outbound          The outbound message data.
     * @param string               $outboundMessageId The outbound message UUID.
     * @param string               $zReportId         The Z-report UUID.
     * @param int                  $statusCode        The HTTP status code (0 for timeout).
     * @param string               $message           The error message.
     * @param string               $errorCode         The error code string.
     * @param int                  $attemptCount      The current attempt count.
     * @param bool                 $retry             Whether to schedule a retry.
     *
     * @return array<string, mixed> Failure response metadata.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    private function handleSubmissionError(
        object $objectService,
        array $outbound,
        string $outboundMessageId,
        string $zReportId,
        int $statusCode,
        string $message,
        string $errorCode,
        int $attemptCount,
        bool $retry=true
    ): array {
        $timestamp = (new DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $attempts = [];
        if (isset($outbound['submissionAttempts']) === true && is_array($outbound['submissionAttempts']) === true) {
            $attempts = $outbound['submissionAttempts'];
        }

        $attempts[] = [
            'timestamp' => $timestamp,
            'status'    => $statusCode,
            'message'   => $message,
        ];

        $nextRetryAt = null;
        $finalStatus = 'failed';

        if ($retry === true && $attemptCount < self::MAX_ATTEMPTS) {
            $backoffIndex = min($attemptCount - 1, count(self::BACKOFF_SECONDS) - 1); // phpcs:ignore
            $backoffSec   = self::BACKOFF_SECONDS[$backoffIndex];
            $nextRetryAt  = (new DateTimeImmutable())->modify(sprintf('+%d seconds', $backoffSec))->format(\DateTimeInterface::ATOM);

            $this->logger->info(
                'PosBookkeepingService: scheduling retry for outbound message {id} at {retryAt}',
                ['id' => $outboundMessageId, 'retryAt' => $nextRetryAt]
            );
        } else if ($attemptCount >= self::MAX_ATTEMPTS || $retry === false) {
            // Permanent failure — alert accounting team.
            $this->sendAlertEmail(outboundMessageId: $outboundMessageId, errorMessage: $message);
        }

        $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'posJournalEntryOutbound',
            object: array_merge(
                    $outbound,
                    [
                        'id'                 => $outboundMessageId,
                        'status'             => $finalStatus,
                        'submissionAttempts' => $attempts,
                        'lastAttemptAt'      => $timestamp,
                        'nextRetryAt'        => $nextRetryAt,
                        'lastErrorMessage'   => $message,
                        'lastErrorCode'      => $errorCode,
                    ]
                    )
        );

        // Update Z-report status to failed.
        $this->updateZReportStatus(objectService: $objectService, zReportId: $zReportId, status: 'failed');

        $this->logger->warning(
            'PosBookkeepingService: outbound message {id} submission failed (attempt {attempt}/{max}): {message}',
            [
                'id'      => $outboundMessageId,
                'attempt' => $attemptCount,
                'max'     => self::MAX_ATTEMPTS,
                'message' => $message,
            ]
        );

        return [
            'status'      => $finalStatus,
            'errorCode'   => $errorCode,
            'message'     => $message,
            'nextRetryAt' => $nextRetryAt,
        ];
    }//end handleSubmissionError()

    // -----------------------------------------------------------------------
    // Retry scheduling
    // -----------------------------------------------------------------------

    /**
     * Calculate the next retry timestamp for a given attempt count.
     *
     * @param int $attemptCount The zero-based attempt index.
     *
     * @return string|null ISO 8601 timestamp for the next retry, or null if max attempts reached.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function calculateNextRetryAt(int $attemptCount): ?string
    {
        if ($attemptCount >= self::MAX_ATTEMPTS) {
            return null;
        }

        $backoffIndex = min($attemptCount, count(self::BACKOFF_SECONDS) - 1); // phpcs:ignore
        $backoffSec   = self::BACKOFF_SECONDS[$backoffIndex];

        return (new DateTimeImmutable())->modify(sprintf('+%d seconds', $backoffSec))->format(\DateTimeInterface::ATOM);
    }//end calculateNextRetryAt()

    // -----------------------------------------------------------------------
    // CloudEvent emission
    // -----------------------------------------------------------------------

    /**
     * Emit a pipelinq.PosJournalEntry.posted CloudEvent via WebhookService.
     *
     * @param string $outboundMessageId The UUID of the posted posJournalEntryOutbound.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function emitPostedEvent(string $outboundMessageId): void
    {
        $objectService = $this->getObjectService();
        $outbound      = $this->tryLoadObject(
            objectService: $objectService,
            schema: 'posJournalEntryOutbound',
            id: $outboundMessageId
        );
        if ($outbound === null) {
            $this->logger->warning('PosBookkeepingService::emitPostedEvent: outbound message not found', ['id' => $outboundMessageId]);
            return;
        }

        $zReportId = (string) ($outbound['zReport'] ?? '');
        $zReport   = $this->tryLoadObject(objectService: $objectService, schema: 'posZReport', id: $zReportId);

        $total = null;
        if ($zReport !== null) {
            $total = $zReport['total'] ?? null;
        }

        $subject = $zReportId;
        if ($zReport !== null) {
            $subject = (string) ($zReport['reference'] ?? $zReportId);
        }

        $eventData = [
            'outboundMessageId'      => $outboundMessageId,
            'zReportId'              => $zReportId,
            'idempotencyKey'         => $outbound['idempotencyKey'] ?? null,
            'shillinqJournalEntryId' => $outbound['shillinqJournalEntryId'] ?? null,
            'shillinqEventId'        => $outbound['shillinqEventId'] ?? null,
            'total'                  => $total,
            'currency'               => 'EUR',
        ];

        $cloudEventId = $this->emitCloudEvent(
            type: self::EVENT_JOURNAL_POSTED,
            subject: $subject,
            data: $eventData
        );

        if ($cloudEventId !== null) {
            // Store the cloudEventId on the outbound message.
            $objectService->saveObject(
                register: Application::APP_ID,
                schema: 'posJournalEntryOutbound',
                object: array_merge($outbound, ['id' => $outboundMessageId, 'cloudEventId' => $cloudEventId])
            );
        }
    }//end emitPostedEvent()

    /**
     * Emit a pipelinq.PosZReport.submitted CloudEvent.
     *
     * @param string $zReportId The UUID of the posZReport.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function emitZReportSubmittedEvent(string $zReportId): void
    {
        $objectService = $this->getObjectService();
        $zReport       = $this->tryLoadObject(objectService: $objectService, schema: 'posZReport', id: $zReportId);
        if ($zReport === null) {
            $this->logger->warning('PosBookkeepingService::emitZReportSubmittedEvent: Z-report not found', ['id' => $zReportId]);
            return;
        }

        $reference = $zReportId;
        if (isset($zReport['reference']) === true) {
            $reference = (string) $zReport['reference'];
        }

        $eventData = [
            'zReportId'        => $zReportId,
            'reportDate'       => $zReport['reportDate'] ?? null,
            'terminalId'       => $zReport['terminalId'] ?? null,
            'transactionCount' => $zReport['transactionCount'] ?? 0,
            'total'            => $zReport['total'] ?? 0,
            'currency'         => 'EUR',
        ];

        $this->emitCloudEvent(
            type: self::EVENT_ZREPORT_SUBMITTED,
            subject: $reference,
            data: $eventData
        );
    }//end emitZReportSubmittedEvent()

    /**
     * Send an alert email to the configured accounting administrator.
     *
     * @param string|null $outboundMessageId The outbound message UUID (optional).
     * @param string      $errorMessage      The error message to include.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function sendAlertEmail(?string $outboundMessageId, string $errorMessage): void
    {
        $alertEmail = (string) $this->appConfig->getValueString(Application::APP_ID, 'bookkeeping_alert_email', '');

        if ($alertEmail === '') {
            $this->logger->warning(
                'PosBookkeepingService: alert email not configured; cannot notify about bookkeeping failure',
                ['outboundMessageId' => $outboundMessageId, 'error' => $errorMessage]
            );
            return;
        }

        $msgId = $outboundMessageId ?? 'N/A';
        $this->logger->error(
            'PosBookkeepingService: ACCOUNTING ALERT — bookkeeping submission failed for {id}: {error}. Alert sent to {email}.',
            [
                'id'    => $msgId,
                'error' => $errorMessage,
                'email' => $alertEmail,
            ]
        );

        // Attempt mailer dispatch if available.
        try {
            // @var \OCP\Mail\IMailer $mailer
            $mailer  = $this->container->get(\OCP\Mail\IMailer::class);
            $message = $mailer->createMessage();
            $message->setTo([$alertEmail]);
            $message->setSubject('[Pipelinq] Boekhoudkundige verwerking mislukt');
            $bodyTemplate = "De boekhoudkundige verwerking is mislukt.\n\n"
                ."Uitstuur-ID: %s\nFout: %s\n\n"
                ."Los dit op via de Pipelinq-boekhoudkundige afhandeling.";
            $message->setPlainBody(
                sprintf(
                    $bodyTemplate,
                    $msgId,
                    $errorMessage
                )
            );
            $mailer->send($message);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'PosBookkeepingService: could not send alert email: {error}',
                ['error' => $e->getMessage()]
            );
        }//end try
    }//end sendAlertEmail()

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Resolve the ObjectService from the DI container at runtime.
     *
     * @return object The OR ObjectService instance.
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Load a posZReport by UUID, throwing OCSNotFoundException if missing.
     *
     * @param object $objectService The OR ObjectService.
     * @param string $id            The Z-report UUID.
     *
     * @return array<string, mixed> The Z-report data.
     *
     * @throws OCSNotFoundException If not found.
     */
    private function loadZReport(object $objectService, string $id): array
    {
        $zReport = $this->tryLoadObject(objectService: $objectService, schema: 'posZReport', id: $id);
        if ($zReport === null) {
            throw new OCSNotFoundException(sprintf('posZReport %s not found', $id));
        }

        return $zReport;
    }//end loadZReport()

    /**
     * Load a posJournalEntryOutbound by UUID, throwing OCSNotFoundException if missing.
     *
     * @param object $objectService The OR ObjectService.
     * @param string $id            The outbound message UUID.
     *
     * @return array<string, mixed> The outbound message data.
     *
     * @throws OCSNotFoundException If not found.
     */
    private function loadOutboundMessage(object $objectService, string $id): array
    {
        $outbound = $this->tryLoadObject(objectService: $objectService, schema: 'posJournalEntryOutbound', id: $id);
        if ($outbound === null) {
            throw new OCSNotFoundException(sprintf('posJournalEntryOutbound %s not found', $id));
        }

        return $outbound;
    }//end loadOutboundMessage()

    /**
     * Try to load an OpenRegister object; return null if not found or on error.
     *
     * @param object $objectService The OR ObjectService.
     * @param string $schema        The schema slug.
     * @param string $id            The object UUID or slug.
     *
     * @return array<string, mixed>|null The object data or null.
     */
    private function tryLoadObject(object $objectService, string $schema, string $id): ?array
    {
        try {
            $obj = $objectService->findObject(
                register: Application::APP_ID,
                schema: $schema,
                id: $id
            );

            if (is_array($obj) === true) {
                return $obj;
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }//end tryLoadObject()

    /**
     * Load the default glAccountMapping from OpenRegister.
     *
     * @param object $objectService The OR ObjectService.
     *
     * @return array<string, mixed>|null The default mapping or null if not configured.
     */
    private function loadDefaultGlMapping(object $objectService): ?array
    {
        try {
            $mappings = $objectService->findAll(
                    filters: [
                        'register' => Application::APP_ID,
                        'schema'   => 'glAccountMapping',
                        'filters'  => [
                            ['field' => 'isDefault', 'operator' => 'eq', 'value' => true],
                        ],
                        'limit'    => 1,
                        'offset'   => 0,
                    ]
                    );

            if (is_array($mappings) === true && count($mappings) > 0) {
                return $mappings[0];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }//end try
    }//end loadDefaultGlMapping()

    /**
     * Update the status of a posZReport.
     *
     * @param object $objectService The OR ObjectService.
     * @param string $zReportId     The Z-report UUID.
     * @param string $status        The new status.
     *
     * @return void
     */
    private function updateZReportStatus(object $objectService, string $zReportId, string $status): void
    {
        try {
            $zReport = $this->tryLoadObject(objectService: $objectService, schema: 'posZReport', id: $zReportId);
            if ($zReport !== null) {
                $objectService->saveObject(
                    register: Application::APP_ID,
                    schema: 'posZReport',
                    object: array_merge($zReport, ['id' => $zReportId, 'status' => $status])
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'PosBookkeepingService: could not update Z-report {id} status to {status}',
                ['id' => $zReportId, 'status' => $status, 'exception' => $e->getMessage()]
            );
        }
    }//end updateZReportStatus()

    /**
     * Execute an HTTP POST to the Shillinq API.
     *
     * Uses PHP's cURL bindings (available in all NC environments).
     * Returns an array with 'status' (int) and 'body' (array).
     *
     * @param string               $url            The full URL to POST to.
     * @param array<string, mixed> $payload        The JSON payload.
     * @param string               $idempotencyKey The idempotency key header value.
     * @param string               $token          The bearer token.
     *
     * @return array{status: int, body: array<string, mixed>} HTTP response.
     *
     * @throws \RuntimeException On cURL initialisation failure.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    private function httpPost(string $url, array $payload, string $idempotencyKey, string $token): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialise cURL');
        }

        $jsonBody = json_encode($payload);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Idempotency-Key: '.$idempotencyKey,
        ];

        if ($token !== '') {
            $headers[] = 'Authorization: Bearer '.$token;
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('cURL error: '.$error);
        }

        $body = [];
        if (is_string($raw) === true && $raw !== '') {
            $decoded = json_decode($raw, associative: true);
            if (is_array($decoded) === true) {
                $body = $decoded;
            }
        }

        return ['status' => $status, 'body' => $body];
    }//end httpPost()

    /**
     * Emit a CloudEvent via the WebhookService (if available in the container).
     *
     * @param string               $type    The CloudEvent type.
     * @param string               $subject The subject (Z-report reference).
     * @param array<string, mixed> $data    The event data payload.
     *
     * @return string|null The emitted event ID, or null on failure.
     */
    private function emitCloudEvent(string $type, string $subject, array $data): ?string
    {
        try {
            $webhookService = $this->container->get('OCA\OpenConnector\Service\WebhookService');
            $eventId        = 'evt-pipelinq-'.bin2hex(random_bytes(8));
            $event          = [
                'specversion'     => '1.0',
                'type'            => $type,
                'source'          => self::EVENT_SOURCE,
                'id'              => $eventId,
                'time'            => (new DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'datacontenttype' => 'application/json',
                'subject'         => $subject,
                'data'            => $data,
            ];

            $webhookService->dispatch(event: $event);

            return $eventId;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'PosBookkeepingService: could not emit CloudEvent {type}: {error}',
                ['type' => $type, 'error' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end emitCloudEvent()
}//end class
