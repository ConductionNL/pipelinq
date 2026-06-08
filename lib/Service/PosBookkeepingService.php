<?php

/**
 * Pipelinq PosBookkeepingService.
 *
 * Business logic for the POS end-of-day bookkeeping pipeline: aggregating a
 * posTransaction set into a daily posZReport, transforming the report into a
 * GL-balanced posJournalEntryOutbound staging record (server-authoritative
 * idempotency key), posting that record to Shillinq's /api/JournalEntry
 * endpoint with X-Idempotency-Key + Bearer-token headers, and emitting
 * pipelinq.PosZReport.submitted / pipelinq.PosJournalEntry.posted CloudEvents
 * on success.
 *
 * Every monetary figure is computed server-side from persisted data; the
 * client never supplies totals. Idempotency keys are deterministic
 * (SHA256(zReport.uuid + reportDate)) so a re-submitted message resolves to
 * the same Shillinq journal entry. Retries use exponential backoff
 * (1 min -> 5 min -> 15 min -> 1 hour, max 5 attempts) on 5xx / network
 * timeout; 4xx responses are terminal failures that alert the accounting
 * administrator.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
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
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for the POS end-of-day bookkeeping pipeline.
 *
 * Orchestrates four concerns:
 *
 *   1. Z-report aggregation — derive a per-terminal daily settlement from the
 *      confirmed posTransaction set, computing subtotal, taxBreakdown,
 *      paymentMethodBreakdown and total server-side.
 *   2. Outbound staging — turn a ready Z-report into a balanced
 *      posJournalEntryOutbound with a deterministic idempotency key, using the
 *      default glAccountMapping profile to translate the taxBreakdown into
 *      ledger line items.
 *   3. Shillinq submission — POST the staging payload to /api/JournalEntry
 *      with X-Idempotency-Key + Bearer token, mapping 2xx -> posted, 4xx ->
 *      terminal failed + alert, 5xx / timeout -> failed + exponential-backoff
 *      retry scheduled through the IJobList.
 *   4. CloudEvent emission — dispatch pipelinq.PosZReport.submitted on Z-report
 *      generation and pipelinq.PosJournalEntry.posted on successful posting
 *      through OpenRegister's WebhookService (fire-and-forget).
 *
 * Every public method has @spec tags linking to the spec change. Authorization
 * is centralised on PosAccessPolicy: postToShillinq is gated to POS managers
 * (the accounting role). Read paths (generateZReport, createOutboundMessage)
 * are server-internal and called by the background job + the manager-gated
 * controller; they are not directly exposed to a #[NoAdminRequired] endpoint.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the collaborators the
 *  pipeline legitimately needs (OR container for ObjectService + WebhookService,
 *  app config, HTTP client factory, IJobList for retry, mailer for alerts,
 *  shared POS access policy, logger). Splitting them would add indirection
 *  without reducing real coupling.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class aggregates the
 *  whole end-of-day pipeline (aggregation + outbound build + POST + retry +
 *  event emit) as small single-purpose methods.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The public surface mirrors
 *  the four lifecycle stages, each unit-tested independently.
 * @SuppressWarnings(PHPMD.TooManyMethods)           Private helpers (fetch /
 *  save / sum / time / hash / dispatch) are deliberately small and
 *  single-purpose; merging them would only obscure the lifecycle.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     The class aggregates the
 *  whole pipeline as many small, single-purpose methods.
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
 */
class PosBookkeepingService
{
    /**
     * CloudEvent type emitted on successful submission to Shillinq.
     *
     * @var string
     */
    public const EVENT_JOURNAL_POSTED = 'pipelinq.PosJournalEntry.posted';

    /**
     * CloudEvent type emitted when a Z-report is generated and submission begins.
     *
     * @var string
     */
    public const EVENT_ZREPORT_SUBMITTED = 'pipelinq.PosZReport.submitted';

    /**
     * CloudEvents source identifier for the EOD bookkeeping surface.
     *
     * @var string
     */
    private const EVENT_SOURCE = 'pipelinq/posBookkeeping';

    /**
     * Default Shillinq submission timeout in seconds.
     *
     * @var int
     */
    private const SHILLINQ_TIMEOUT = 30;

    /**
     * Default ceiling on retry attempts before terminal failure.
     *
     * @var int
     */
    public const DEFAULT_MAX_ATTEMPTS = 5;

    /**
     * Exponential-backoff schedule in seconds: 1min, 5min, 15min, 1hr.
     *
     * @var array<int, int>
     */
    public const BACKOFF_SECONDS = [60, 300, 900, 3600];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container     The DI container.
     * @param IAppConfig         $appConfig     The app config.
     * @param IClientService     $clientService The HTTP client factory.
     * @param IJobList           $jobList       The background job list (retry scheduling).
     * @param IMailer            $mailer        The mailer (alert dispatch).
     * @param PosAccessPolicy    $policy        The shared POS access policy.
     * @param LoggerInterface    $logger        The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IClientService $clientService,
        private IJobList $jobList,
        private IMailer $mailer,
        private PosAccessPolicy $policy,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Round a monetary value to 2 decimals.
     *
     * @param float $value The value to round.
     *
     * @return float The value rounded to cents.
     */
    private function money(float $value): float
    {
        return round($value, 2);
    }//end money()

    /**
     * Generate a Z-report for a given reportDate and optional terminal.
     *
     * Aggregates every confirmed/settled posTransaction whose confirmedAt falls
     * on the supplied reportDate (in UTC). When a terminalId is supplied only
     * transactions on that terminal contribute; when null, the report spans all
     * terminals and is grouped by `terminal` into one row in the
     * paymentMethodBreakdown. Returns the persisted posZReport array; the
     * caller (background job) is responsible for chaining createOutboundMessage
     * if status != draft.
     *
     * Server-authoritative: subtotal, discountTotal, taxBreakdown, totalTax,
     * total, paymentMethodBreakdown, transactionCount and transactionIds are
     * derived from the transactions; the only client input is the date and
     * optional terminal filter.
     *
     * @param string      $reportDate The settlement date in YYYY-MM-DD.
     * @param string|null $terminalId Optional terminal filter (e.g. kassa-01).
     *
     * @return array<string, mixed> The persisted posZReport.
     *
     * @throws OCSBadRequestException When the reportDate cannot be parsed.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function generateZReport(string $reportDate, ?string $terminalId=null): array
    {
        if ($this->isValidDate(value: $reportDate) === false) {
            throw new OCSBadRequestException('reportDate moet in YYYY-MM-DD formaat zijn.');
        }

        $transactions = $this->fetchTransactionsForDate(reportDate: $reportDate, terminalId: $terminalId);

        $aggregate = $this->aggregateTransactions(transactions: $transactions);

        $status = 'draft';
        if ($aggregate['transactionCount'] > 0) {
            $status = 'ready';
        }

        $reference = 'Z-'.$reportDate;
        if ($terminalId !== null && $terminalId !== '') {
            $reference .= '-'.strtoupper($terminalId);
        }

        $report = [
            'reference'              => $reference,
            'reportDate'             => $reportDate,
            'terminalId'             => $terminalId,
            'transactionIds'         => $aggregate['transactionIds'],
            'transactionCount'       => $aggregate['transactionCount'],
            'subtotal'               => $aggregate['subtotal'],
            'discountTotal'          => $aggregate['discountTotal'],
            'taxBreakdown'           => $aggregate['taxBreakdown'],
            'totalTax'               => $aggregate['totalTax'],
            'total'                  => $aggregate['total'],
            'paymentMethodBreakdown' => $aggregate['paymentMethodBreakdown'],
            'createdAt'              => $this->now(),
            'settledAt'              => $aggregate['settledAt'],
            'status'                 => $status,
        ];

        $persisted = $this->saveObjectFor(schemaKey: 'posZReport_schema', id: '', object: $report);

        $this->emitZReportSubmittedEvent(zReport: $persisted);

        $this->logger->info(
            'Pipelinq: Z-report generated',
            [
                'reportDate'       => $reportDate,
                'terminalId'       => $terminalId,
                'transactionCount' => $aggregate['transactionCount'],
                'total'            => $aggregate['total'],
            ]
        );

        return $persisted;
    }//end generateZReport()

    /**
     * Aggregate a transaction list into the Z-report summary fields.
     *
     * Sums subtotal, discountTotal, totalTax and total; groups
     * taxBreakdown by rate (summing base + tax across all transactions); groups
     * paymentMethodBreakdown by paymentMethod; collects transactionIds; derives
     * settledAt from the latest transaction's settledAt / confirmedAt. Pure
     * function: no side effects, used by generateZReport and indirectly by
     * tests.
     *
     * @param array<int, array<string, mixed>> $transactions The transactions.
     *
     * @return array{
     *   transactionIds: array<int, string>,
     *   transactionCount: int,
     *   subtotal: float,
     *   discountTotal: float,
     *   taxBreakdown: array<int, array<string, float|int>>,
     *   totalTax: float,
     *   total: float,
     *   paymentMethodBreakdown: array<int, array<string, float|string>>,
     *   settledAt: string|null
     * } The aggregate.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function aggregateTransactions(array $transactions): array
    {
        $ids           = [];
        $subtotal      = 0.0;
        $discountTotal = 0.0;
        $totalTax      = 0.0;
        $grandTotal    = 0.0;
        $byRate        = [];
        $byMethod      = [];
        $latestSettled = null;

        foreach ($transactions as $txn) {
            $id = (string) ($txn['id'] ?? $txn['uuid'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }

            $subtotal      += (float) ($txn['subtotal'] ?? 0);
            $discountTotal += (float) ($txn['discountTotal'] ?? 0);
            $totalTax      += (float) ($txn['totalTax'] ?? 0);
            $grandTotal    += (float) ($txn['total'] ?? 0);

            $latestSettled = $this->maxIso(left: $latestSettled, right: (string) ($txn['settledAt'] ?? $txn['confirmedAt'] ?? ''));

            foreach (($txn['taxBreakdown'] ?? []) as $entry) {
                $rate = (string) (int) ($entry['rate'] ?? 0);
                if (isset($byRate[$rate]) === false) {
                    $byRate[$rate] = ['rate' => (int) (float) ($entry['rate'] ?? 0), 'base' => 0.0, 'tax' => 0.0];
                }

                $byRate[$rate]['base'] += (float) ($entry['base'] ?? 0);
                $byRate[$rate]['tax']  += (float) ($entry['tax'] ?? 0);
            }

            $method = (string) ($txn['paymentMethod'] ?? '');
            if ($method !== '') {
                if (isset($byMethod[$method]) === false) {
                    $byMethod[$method] = ['method' => $method, 'amount' => 0.0];
                }

                $byMethod[$method]['amount'] += (float) ($txn['total'] ?? 0);
            }
        }//end foreach

        $taxBreakdown = [];
        foreach ($byRate as $row) {
            $taxBreakdown[] = [
                'rate' => $row['rate'],
                'base' => $this->money(value: (float) $row['base']),
                'tax'  => $this->money(value: (float) $row['tax']),
            ];
        }

        $paymentMethodBreakdown = [];
        foreach ($byMethod as $row) {
            $paymentMethodBreakdown[] = [
                'method' => $row['method'],
                'amount' => $this->money(value: (float) $row['amount']),
            ];
        }

        return [
            'transactionIds'         => $ids,
            'transactionCount'       => count($ids),
            'subtotal'               => $this->money(value: $subtotal),
            'discountTotal'          => $this->money(value: $discountTotal),
            'taxBreakdown'           => $taxBreakdown,
            'totalTax'               => $this->money(value: $totalTax),
            'total'                  => $this->money(value: $grandTotal),
            'paymentMethodBreakdown' => $paymentMethodBreakdown,
            'settledAt'              => $latestSettled,
        ];
    }//end aggregateTransactions()

    /**
     * Stage a posJournalEntryOutbound record from a ready Z-report.
     *
     * Loads the Z-report, resolves the default glAccountMapping profile,
     * computes a deterministic SHA256(zReport.uuid + reportDate) idempotency
     * key, builds GL-balanced ledger line items per tax rate (debit revenue
     * account, credit GL revenue account per rate, credit bank clearing for
     * the gross total) and persists the outbound message in status="draft".
     * Refuses to stage when the Z-report is missing the GL mapping for any rate
     * in its breakdown — in that case an alert is emitted and the Z-report
     * stays in `ready` for manual intervention (REQ-POS-BK-002-02).
     *
     * @param string $zReportId The Z-report UUID.
     *
     * @return array<string, mixed> The persisted outbound message.
     *
     * @throws OCSNotFoundException   When the Z-report or default mapping is missing.
     * @throws OCSBadRequestException When the GL mapping doesn't cover all rates.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function createOutboundMessage(string $zReportId): array
    {
        $zReport = $this->fetchZReport(id: $zReportId);

        $mapping = $this->fetchDefaultMapping();
        if ($mapping === []) {
            $this->sendAlertEmail(
                subject: 'POS bookkeeping: geen actieve GL mapping geconfigureerd',
                body: 'Z-report '.((string) ($zReport['reference'] ?? $zReportId))
                    .' kan niet worden geboekt; configureer een standaard glAccountMapping.'
            );
            throw new OCSNotFoundException('Geen standaard GL mapping geconfigureerd.');
        }

        $taxBreakdown = $zReport['taxBreakdown'] ?? [];
        $missing      = $this->missingRates(taxBreakdown: $taxBreakdown, mapping: $mapping);
        if ($missing !== []) {
            $this->sendAlertEmail(
                subject: 'POS bookkeeping: ontbrekende GL mapping voor BTW-tarief(en)',
                body: 'Z-report '.((string) ($zReport['reference'] ?? $zReportId)).' bevat tarieven zonder mapping: '.implode(', ', $missing).'%.'
            );
            throw new OCSBadRequestException('GL mapping ontbreekt voor BTW-tarief(en): '.implode(', ', $missing).'%.');
        }

        $idempotencyKey = $this->computeIdempotencyKey(zReport: $zReport);
        $ledgerLines    = $this->buildLedgerLineItems(zReport: $zReport, mapping: $mapping);
        $reportDate     = (string) ($zReport['reportDate'] ?? '');

        $outbound = [
            'zReport'            => (string) ($zReport['id'] ?? $zReport['uuid'] ?? $zReportId),
            'idempotencyKey'     => $idempotencyKey,
            'payloadVersion'     => 1,
            'ledgerLineItems'    => $ledgerLines,
            'postingDate'        => $reportDate,
            'status'             => 'draft',
            'submissionAttempts' => [],
            'attemptCount'       => 0,
        ];

        return $this->saveObjectFor(schemaKey: 'posJournalEntryOutbound_schema', id: '', object: $outbound);
    }//end createOutboundMessage()

    /**
     * Compute the deterministic idempotency key for a Z-report.
     *
     * Hash is SHA256(zReport.uuid + reportDate) prefixed with `sha256:` so the
     * Shillinq side can detect the algorithm; deterministic so a re-submission
     * with the same Z-report resolves to the same Shillinq journal entry; and
     * unique per (zReport, reportDate) pair so two Z-reports never collide.
     *
     * @param array<string, mixed> $zReport The Z-report object.
     *
     * @return string The idempotency key.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function computeIdempotencyKey(array $zReport): string
    {
        $uuid = (string) ($zReport['id'] ?? $zReport['uuid'] ?? '');
        $date = (string) ($zReport['reportDate'] ?? '');

        return 'sha256:'.hash('sha256', $uuid.$date);
    }//end computeIdempotencyKey()

    /**
     * Build balanced GL ledger line items from a Z-report + GL mapping.
     *
     * Per-rate emits a debit on the revenue/AR account and a credit on the GL
     * revenue account from the mapping; appends a single bank clearing credit
     * line for the gross total so debits and credits balance. The breakdown
     * `base` is treated as the net revenue amount; `tax` is rolled into the
     * gross via the bank clearing total. When the mapping has a bankAccount
     * configured it is used; otherwise a default 1000 placeholder is used (the
     * mapping is enforced as required upstream).
     *
     * @param array<string, mixed> $zReport The Z-report.
     * @param array<string, mixed> $mapping The default GL mapping profile.
     *
     * @return array<int, array<string, mixed>> The ledger line items.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function buildLedgerLineItems(array $zReport, array $mapping): array
    {
        $rateAccounts = $this->rateAccountIndex(mapping: $mapping);
        $bankAccount  = (string) ($mapping['bankAccount'] ?? '1000');
        if ($bankAccount === '') {
            $bankAccount = '1000';
        }

        $rateRows = $zReport['taxBreakdown'] ?? [];
        $baseSum  = 0.0;
        $taxSum   = 0.0;
        $lines    = [];

        // Balanced double-entry POS retail booking:
        //   1× debit on the bank/cash clearing account == gross,
        //   1× credit on each rate-specific omzet/revenue account == base,
        //   1× credit on the Te dragen BTW (1500) clearing == total tax.
        // Per-rate revenue lines preserve the BTW-uitsplitsing the bookkeeper
        // expects; the single bank-side debit keeps the entry compact.
        foreach ($rateRows as $row) {
            $rate = (int) (float) ($row['rate'] ?? 0);
            $base = (float) ($row['base'] ?? 0);
            $tax  = (float) ($row['tax'] ?? 0);

            $creditAccount = (string) ($rateAccounts[$rate]['credit'] ?? '5000');

            $lines[]  = [
                'account'     => $creditAccount,
                'debit'       => 0,
                'credit'      => $this->money(value: $base),
                'description' => 'Omzet '.$rate.'% BTW',
                'taxRate'     => $rate,
            ];
            $baseSum += $base;
            $taxSum  += $tax;
        }//end foreach

        if ($taxSum > 0.0) {
            $lines[] = [
                'account'     => '1500',
                'debit'       => 0,
                'credit'      => $this->money(value: $taxSum),
                'description' => 'Te dragen BTW',
                'taxRate'     => null,
            ];
        }

        $gross = (float) ($zReport['total'] ?? ($baseSum + $taxSum));
        if ($gross > 0.0) {
            // Debit the bank/cash clearing account for the gross — paired against
            // the per-rate credit revenue + BTW lines so the entry balances.
            array_unshift(
                $lines,
                [
                    'account'     => $bankAccount,
                    'debit'       => $this->money(value: $gross),
                    'credit'      => 0,
                    'description' => 'Kas/Bank clearing',
                    'taxRate'     => null,
                ]
            );
        }

        return $lines;
    }//end buildLedgerLineItems()

    /**
     * Submit a posJournalEntryOutbound to Shillinq's /api/JournalEntry.
     *
     * Manager-gated (POS manager OR Nextcloud admin via PosAccessPolicy::
     * isManager). Builds the JSON payload from the outbound message + parent
     * Z-report, sends a POST with `Authorization: Bearer ${token}` +
     * `X-Idempotency-Key: ${key}` headers, and maps the response:
     *
     *   - 200/201/202 -> status="posted", record attempt, emit
     *     pipelinq.PosJournalEntry.posted, transition Z-report -> posted.
     *   - 4xx         -> status="failed" (terminal), record attempt, alert
     *     accounting administrator, transition Z-report -> failed; no retry.
     *   - 5xx / IO    -> status="failed" (transient), record attempt, increment
     *     attemptCount, schedule next retry via IJobList; max 5 attempts.
     *
     * Returns the persisted outbound message after the lifecycle decision.
     *
     * @param string $outboundMessageId The outbound message UUID.
     * @param string $userId            The acting user UID (manager / admin).
     *
     * @return array<string, mixed> The persisted outbound after the call.
     *
     * @throws OCSForbiddenException  When the user is not a POS manager / admin.
     * @throws OCSNotFoundException   When the outbound or Z-report is missing.
     * @throws OCSBadRequestException When the outbound is not draft/failed or
     *                                the Shillinq endpoint is unconfigured.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function postToShillinq(string $outboundMessageId, string $userId): array
    {
        $this->requireManager(userId: $userId);

        $outbound = $this->fetchOutbound(id: $outboundMessageId);
        $status   = (string) ($outbound['status'] ?? '');
        if (in_array($status, ['draft', 'failed', 'pending'], true) === false) {
            throw new OCSBadRequestException('Alleen draft, pending of failed berichten kunnen worden ingediend.');
        }

        $endpoint = $this->shillinqEndpoint();
        if ($endpoint === '') {
            throw new OCSBadRequestException('Shillinq endpoint is niet geconfigureerd.');
        }

        $zReport = $this->fetchZReport(id: (string) ($outbound['zReport'] ?? ''));
        $payload = $this->buildShillinqPayload(outbound: $outbound, zReport: $zReport);
        $headers = [
            'Content-Type'      => 'application/json',
            'Accept'            => 'application/json',
            'X-Idempotency-Key' => (string) ($outbound['idempotencyKey'] ?? ''),
        ];

        $token = $this->shillinqToken();
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $attemptCount = ((int) ($outbound['attemptCount'] ?? 0)) + 1;
        $attemptAt    = $this->now();

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                rtrim($endpoint, '/').'/api/JournalEntry',
                [
                    'headers' => $headers,
                    'body'    => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'timeout' => self::SHILLINQ_TIMEOUT,
                ]
            );

            $statusCode = (int) $response->getStatusCode();
            $body       = (string) $response->getBody();
            $decoded    = json_decode($body, true);
            if (is_array($decoded) === false) {
                $decoded = [];
            }

            $message = (string) ($decoded['message'] ?? 'Accepted');
            $eventId = (string) ($decoded['eventId'] ?? '');
            $jeId    = (string) ($decoded['journalEntryId'] ?? '');
            $glRef   = (string) ($decoded['glReference'] ?? '');

            return $this->onPosted(
                outbound: $outbound,
                zReport: $zReport,
                statusCode: $statusCode,
                message: $message,
                eventId: $eventId,
                journalEntryId: $jeId,
                glReference: $glRef,
                attemptCount: $attemptCount,
                attemptAt: $attemptAt
            );
        } catch (\OCP\Http\Client\LocalServerException $e) {
            return $this->onTransientFailure(
                outbound: $outbound,
                zReport: $zReport,
                statusCode: 0,
                message: 'NETWORK_TIMEOUT: '.$e->getMessage(),
                errorCode: 'NETWORK_TIMEOUT',
                attemptCount: $attemptCount,
                attemptAt: $attemptAt
            );
        } catch (\Throwable $e) {
            return $this->classifyAndPersistFailure(
                outbound: $outbound,
                zReport: $zReport,
                exception: $e,
                attemptCount: $attemptCount,
                attemptAt: $attemptAt
            );
        }//end try
    }//end postToShillinq()

    /**
     * Persist a successful submission result and emit the posted CloudEvent.
     *
     * @param array<string, mixed> $outbound       The pre-call outbound state.
     * @param array<string, mixed> $zReport        The parent Z-report.
     * @param int                  $statusCode     The HTTP status code.
     * @param string               $message        The response message.
     * @param string               $eventId        The Shillinq CloudEvents id (may be empty).
     * @param string               $journalEntryId The created Shillinq journal entry UUID.
     * @param string               $glReference    The GL batch reference.
     * @param int                  $attemptCount   The incremented attempt count.
     * @param string               $attemptAt      The attempt timestamp.
     *
     * @return array<string, mixed> The persisted outbound after success handling.
     */
    private function onPosted(
        array $outbound,
        array $zReport,
        int $statusCode,
        string $message,
        string $eventId,
        string $journalEntryId,
        string $glReference,
        int $attemptCount,
        string $attemptAt
    ): array {
        if ($statusCode < 200 || $statusCode >= 300) {
            if ($statusCode >= 500) {
                return $this->onTransientFailure(
                    outbound: $outbound,
                    zReport: $zReport,
                    statusCode: $statusCode,
                    message: $message,
                    errorCode: (string) $statusCode,
                    attemptCount: $attemptCount,
                    attemptAt: $attemptAt
                );
            }

            return $this->onTerminalFailure(
                outbound: $outbound,
                zReport: $zReport,
                statusCode: $statusCode,
                message: $message,
                errorCode: (string) $statusCode,
                attemptCount: $attemptCount,
                attemptAt: $attemptAt
            );
        }//end if

        $attempts   = (array) ($outbound['submissionAttempts'] ?? []);
        $attempts[] = [
            'timestamp' => $attemptAt,
            'status'    => $statusCode,
            'message'   => $message,
            'eventId'   => $eventId,
        ];

        $outbound['status'] = 'posted';
        $outbound['submissionAttempts']     = $attempts;
        $outbound['attemptCount']           = $attemptCount;
        $outbound['lastAttemptAt']          = $attemptAt;
        $outbound['nextRetryAt']            = null;
        $outbound['shillinqEventId']        = $eventId;
        $outbound['shillinqJournalEntryId'] = $journalEntryId;
        $outbound['glReference']            = $glReference;
        $outbound['lastErrorMessage']       = null;
        $outbound['lastErrorCode']          = null;

        $outboundId = (string) ($outbound['id'] ?? $outbound['uuid'] ?? '');
        $outbound   = $this->saveObjectFor(schemaKey: 'posJournalEntryOutbound_schema', id: $outboundId, object: $outbound);

        $cloudEventId = $this->emitJournalPostedEvent(outbound: $outbound, zReport: $zReport);
        if ($cloudEventId !== '') {
            $outbound['cloudEventId'] = $cloudEventId;
            $outboundId = (string) ($outbound['id'] ?? $outbound['uuid'] ?? $outboundId);
            $outbound   = $this->saveObjectFor(schemaKey: 'posJournalEntryOutbound_schema', id: $outboundId, object: $outbound);
        }

        $zReport['status'] = 'posted';
        $this->saveObjectFor(schemaKey: 'posZReport_schema', id: (string) ($zReport['id'] ?? $zReport['uuid'] ?? ''), object: $zReport);

        $this->logger->info(
            'Pipelinq: Shillinq journal entry posted',
            ['outboundId' => $outboundId, 'statusCode' => $statusCode, 'journalEntryId' => $journalEntryId]
        );

        return $outbound;
    }//end onPosted()

    /**
     * Persist a terminal 4xx failure: alert, no retry scheduled.
     *
     * @param array<string, mixed> $outbound     The pre-call outbound.
     * @param array<string, mixed> $zReport      The parent Z-report.
     * @param int                  $statusCode   The HTTP status code.
     * @param string               $message      The error message.
     * @param string               $errorCode    The error code (HTTP status).
     * @param int                  $attemptCount The incremented attempt count.
     * @param string               $attemptAt    The attempt timestamp.
     *
     * @return array<string, mixed> The persisted outbound.
     */
    private function onTerminalFailure(
        array $outbound,
        array $zReport,
        int $statusCode,
        string $message,
        string $errorCode,
        int $attemptCount,
        string $attemptAt
    ): array {
        $attempts   = (array) ($outbound['submissionAttempts'] ?? []);
        $attempts[] = [
            'timestamp' => $attemptAt,
            'status'    => $statusCode,
            'message'   => $message,
            'eventId'   => null,
        ];

        $outbound['status'] = 'failed';
        $outbound['submissionAttempts'] = $attempts;
        $outbound['attemptCount']       = $attemptCount;
        $outbound['lastAttemptAt']      = $attemptAt;
        $outbound['nextRetryAt']        = null;
        $outbound['lastErrorMessage']   = $message;
        $outbound['lastErrorCode']      = $errorCode;

        $outboundId = (string) ($outbound['id'] ?? $outbound['uuid'] ?? '');
        $outbound   = $this->saveObjectFor(schemaKey: 'posJournalEntryOutbound_schema', id: $outboundId, object: $outbound);

        $zReport['status'] = 'failed';
        $this->saveObjectFor(schemaKey: 'posZReport_schema', id: (string) ($zReport['id'] ?? $zReport['uuid'] ?? ''), object: $zReport);

        $this->sendAlertEmail(
            subject: 'POS bookkeeping: Shillinq weigert Z-report ('.$statusCode.')',
            body: 'Outbound '.$outboundId.' afgewezen door Shillinq. Reden: '.$message
        );

        $this->logger->warning(
            'Pipelinq: Shillinq journal entry rejected (terminal)',
            ['outboundId' => $outboundId, 'statusCode' => $statusCode, 'message' => $message]
        );

        return $outbound;
    }//end onTerminalFailure()

    /**
     * Persist a transient 5xx / timeout failure and schedule next retry.
     *
     * Schedules a PosRetryBackoffJob if attemptCount < maxAttempts; otherwise
     * marks terminally failed and sends an alert.
     *
     * @param array<string, mixed> $outbound     The pre-call outbound.
     * @param array<string, mixed> $zReport      The parent Z-report.
     * @param int                  $statusCode   The HTTP status code (0 on network).
     * @param string               $message      The error message.
     * @param string               $errorCode    The error code.
     * @param int                  $attemptCount The incremented attempt count.
     * @param string               $attemptAt    The attempt timestamp.
     *
     * @return array<string, mixed> The persisted outbound.
     */
    private function onTransientFailure(
        array $outbound,
        array $zReport,
        int $statusCode,
        string $message,
        string $errorCode,
        int $attemptCount,
        string $attemptAt
    ): array {
        $maxAttempts = $this->maxAttempts();
        $attempts    = (array) ($outbound['submissionAttempts'] ?? []);
        $attempts[]  = [
            'timestamp' => $attemptAt,
            'status'    => $statusCode,
            'message'   => $message,
            'eventId'   => null,
        ];

        $outbound['submissionAttempts'] = $attempts;
        $outbound['attemptCount']       = $attemptCount;
        $outbound['lastAttemptAt']      = $attemptAt;
        $outbound['lastErrorMessage']   = $message;
        $outbound['lastErrorCode']      = $errorCode;

        if ($attemptCount >= $maxAttempts) {
            $outbound['status']      = 'failed';
            $outbound['nextRetryAt'] = null;

            $outboundId = (string) ($outbound['id'] ?? $outbound['uuid'] ?? '');
            $outbound   = $this->saveObjectFor(schemaKey: 'posJournalEntryOutbound_schema', id: $outboundId, object: $outbound);

            $zReport['status'] = 'failed';
            $this->saveObjectFor(schemaKey: 'posZReport_schema', id: (string) ($zReport['id'] ?? $zReport['uuid'] ?? ''), object: $zReport);

            $this->sendAlertEmail(
                subject: 'POS bookkeeping: Shillinq submission max retries bereikt',
                body: 'Outbound '.$outboundId.' is na '.$attemptCount.' pogingen permanent gefaald. Laatste fout: '.$message
            );

            $this->logger->warning(
                'Pipelinq: Shillinq journal entry max-retries exhausted',
                ['outboundId' => $outboundId, 'attemptCount' => $attemptCount]
            );

            return $outbound;
        }//end if

        $nextRetryAt        = $this->scheduleNextRetry(attemptCount: $attemptCount);
        $outbound['status'] = 'failed';
        $outbound['nextRetryAt'] = $nextRetryAt;

        $outboundId = (string) ($outbound['id'] ?? $outbound['uuid'] ?? '');
        $outbound   = $this->saveObjectFor(schemaKey: 'posJournalEntryOutbound_schema', id: $outboundId, object: $outbound);

        $zReport['status'] = 'failed';
        $this->saveObjectFor(schemaKey: 'posZReport_schema', id: (string) ($zReport['id'] ?? $zReport['uuid'] ?? ''), object: $zReport);

        try {
            $this->jobList->add(
                \OCA\Pipelinq\BackgroundJob\PosRetryBackoffJob::class,
                ['outboundMessageId' => $outboundId, 'scheduledAt' => $nextRetryAt]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: PosRetryBackoffJob could not be scheduled (job will be picked up on next admin retry)',
                ['outboundId' => $outboundId, 'exception' => $e->getMessage()]
            );
        }//end try

        $this->logger->info(
            'Pipelinq: Shillinq journal entry transient failure, retry scheduled',
            [
                'outboundId'   => $outboundId,
                'statusCode'   => $statusCode,
                'attemptCount' => $attemptCount,
                'nextRetryAt'  => $nextRetryAt,
            ]
        );

        return $outbound;
    }//end onTransientFailure()

    /**
     * Compute the next-retry ISO 8601 timestamp from the attempt index.
     *
     * Uses the {@see self::BACKOFF_SECONDS} table indexed by attemptCount-1;
     * an attemptCount past the table caps at the last entry (1 hour) so
     * very-old retries still progress before the max-attempts terminal cut-off.
     *
     * @param int $attemptCount The attempts made (1-based).
     *
     * @return string The ISO 8601 next-retry timestamp.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function scheduleNextRetry(int $attemptCount): string
    {
        $idx     = max(0, $attemptCount - 1);
        $idx     = min($idx, count(self::BACKOFF_SECONDS) - 1);
        $seconds = self::BACKOFF_SECONDS[$idx];

        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+'.$seconds.' seconds')
            ->format(DateTimeInterface::ATOM);
    }//end scheduleNextRetry()

    /**
     * Classify an arbitrary exception thrown during the HTTP call.
     *
     * Standard Throwables (DNS, timeout, TLS) are mapped to the transient
     * branch; explicit network exceptions short-circuit there too. Errors that
     * carry an HTTP code (Guzzle ClientException etc.) flow through the status
     * code interpreter.
     *
     * @param array<string, mixed> $outbound     The pre-call outbound.
     * @param array<string, mixed> $zReport      The parent Z-report.
     * @param \Throwable           $exception    The thrown exception.
     * @param int                  $attemptCount The incremented attempt count.
     * @param string               $attemptAt    The attempt timestamp.
     *
     * @return array<string, mixed> The persisted outbound.
     */
    private function classifyAndPersistFailure(
        array $outbound,
        array $zReport,
        \Throwable $exception,
        int $attemptCount,
        string $attemptAt
    ): array {
        $code    = (int) $exception->getCode();
        $message = $exception->getMessage();

        if ($code >= 400 && $code < 500) {
            return $this->onTerminalFailure(
                outbound: $outbound,
                zReport: $zReport,
                statusCode: $code,
                message: $message,
                errorCode: (string) $code,
                attemptCount: $attemptCount,
                attemptAt: $attemptAt
            );
        }

        $statusCode = 0;
        if ($code >= 500 && $code < 600) {
            $statusCode = $code;
        }

        $errorCode = 'NETWORK_ERROR';
        if ($statusCode > 0) {
            $errorCode = (string) $statusCode;
        }

        return $this->onTransientFailure(
            outbound: $outbound,
            zReport: $zReport,
            statusCode: $statusCode,
            message: $message,
            errorCode: $errorCode,
            attemptCount: $attemptCount,
            attemptAt: $attemptAt
        );
    }//end classifyAndPersistFailure()

    /**
     * Emit the pipelinq.PosJournalEntry.posted CloudEvent.
     *
     * Fire-and-forget through OpenRegister's WebhookService. Failure to
     * resolve the WebhookService (no consumer / OR offline) is logged but
     * never aborts the lifecycle — the outbound message is still marked
     * posted with the Shillinq response data.
     *
     * @param array<string, mixed> $outbound The posted outbound message.
     * @param array<string, mixed> $zReport  The parent Z-report.
     *
     * @return string The generated CloudEvents id, or empty string on failure.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function emitJournalPostedEvent(array $outbound, array $zReport): string
    {
        $eventId = $this->uuid();
        $payload = [
            'specversion'     => '1.0',
            'type'            => self::EVENT_JOURNAL_POSTED,
            'source'          => self::EVENT_SOURCE,
            'id'              => $eventId,
            'time'            => $this->now(),
            'subject'         => (string) ($zReport['reference'] ?? ($zReport['id'] ?? '')),
            'datacontenttype' => 'application/json',
            'data'            => [
                'zReportId'              => (string) ($zReport['id'] ?? $zReport['uuid'] ?? ''),
                'outboundMessageId'      => (string) ($outbound['id'] ?? $outbound['uuid'] ?? ''),
                'idempotencyKey'         => (string) ($outbound['idempotencyKey'] ?? ''),
                'shillinqJournalEntryId' => (string) ($outbound['shillinqJournalEntryId'] ?? ''),
                'shillinqEventId'        => (string) ($outbound['shillinqEventId'] ?? ''),
                'glReference'            => (string) ($outbound['glReference'] ?? ''),
                'total'                  => (float) ($zReport['total'] ?? 0),
                'currency'               => 'EUR',
            ],
        ];

        return $this->dispatchCloudEvent(eventName: self::EVENT_JOURNAL_POSTED, payload: $payload, eventId: $eventId);
    }//end emitJournalPostedEvent()

    /**
     * Emit the pipelinq.PosZReport.submitted CloudEvent on Z-report generation.
     *
     * Fire-and-forget. Carries the Z-report summary so a downstream
     * reconciliation consumer can correlate it with its eventual journal
     * posting (via subject = Z-report reference).
     *
     * @param array<string, mixed> $zReport The persisted Z-report.
     *
     * @return string The generated CloudEvents id, or empty string on failure.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function emitZReportSubmittedEvent(array $zReport): string
    {
        $eventId = $this->uuid();
        $payload = [
            'specversion'     => '1.0',
            'type'            => self::EVENT_ZREPORT_SUBMITTED,
            'source'          => self::EVENT_SOURCE,
            'id'              => $eventId,
            'time'            => $this->now(),
            'subject'         => (string) ($zReport['reference'] ?? ($zReport['id'] ?? '')),
            'datacontenttype' => 'application/json',
            'data'            => [
                'zReportId'        => (string) ($zReport['id'] ?? $zReport['uuid'] ?? ''),
                'reportDate'       => (string) ($zReport['reportDate'] ?? ''),
                'terminalId'       => (string) ($zReport['terminalId'] ?? ''),
                'transactionCount' => (int) ($zReport['transactionCount'] ?? 0),
                'total'            => (float) ($zReport['total'] ?? 0),
                'currency'         => 'EUR',
            ],
        ];

        return $this->dispatchCloudEvent(eventName: self::EVENT_ZREPORT_SUBMITTED, payload: $payload, eventId: $eventId);
    }//end emitZReportSubmittedEvent()

    /**
     * Best-effort CloudEvent dispatch through OR's WebhookService.
     *
     * @param string               $eventName The CloudEvent type.
     * @param array<string, mixed> $payload   The CloudEvent payload.
     * @param string               $eventId   The pre-generated event id.
     *
     * @return string The event id on success, or empty string on failure.
     */
    private function dispatchCloudEvent(string $eventName, array $payload, string $eventId): string
    {
        try {
            $webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
            $event          = new Event();
            $webhookService->dispatchEvent(_event: $event, eventName: $eventName, payload: $payload);

            return $eventId;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: bookkeeping CloudEvent not dispatched (no consumer or OR unavailable)',
                ['exception' => $e->getMessage(), 'eventName' => $eventName]
            );

            return '';
        }//end try
    }//end dispatchCloudEvent()

    /**
     * Build the JSON payload sent to Shillinq.
     *
     * @param array<string, mixed> $outbound The outbound staging record.
     * @param array<string, mixed> $zReport  The parent Z-report.
     *
     * @return array<string, mixed> The Shillinq JournalEntry payload.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function buildShillinqPayload(array $outbound, array $zReport): array
    {
        return [
            'specversion'     => '1.0',
            'type'            => 'shillinq.JournalEntry.post',
            'source'          => self::EVENT_SOURCE,
            'id'              => (string) ($outbound['idempotencyKey'] ?? $this->uuid()),
            'time'            => $this->now(),
            'subject'         => (string) ($zReport['reference'] ?? ''),
            'datacontenttype' => 'application/json',
            'data'            => [
                'idempotencyKey'   => (string) ($outbound['idempotencyKey'] ?? ''),
                'payloadVersion'   => (int) ($outbound['payloadVersion'] ?? 1),
                'reference'        => (string) ($zReport['reference'] ?? ''),
                'postingDate'      => (string) ($outbound['postingDate'] ?? $zReport['reportDate'] ?? ''),
                'terminalId'       => (string) ($zReport['terminalId'] ?? ''),
                'currency'         => 'EUR',
                'subtotal'         => (float) ($zReport['subtotal'] ?? 0),
                'totalTax'         => (float) ($zReport['totalTax'] ?? 0),
                'total'            => (float) ($zReport['total'] ?? 0),
                'taxBreakdown'     => (array) ($zReport['taxBreakdown'] ?? []),
                'ledgerLineItems'  => (array) ($outbound['ledgerLineItems'] ?? []),
                'transactionCount' => (int) ($zReport['transactionCount'] ?? 0),
            ],
        ];
    }//end buildShillinqPayload()

    /**
     * Send an alert email to the configured accounting administrator.
     *
     * Best-effort. Failure to send (no mailer config, transport down) is
     * logged but never propagated — the lifecycle decision the alert
     * documents has already been persisted.
     *
     * @param string $subject The alert subject.
     * @param string $body    The alert body.
     *
     * @return void
     */
    private function sendAlertEmail(string $subject, string $body): void
    {
        $to = trim($this->appConfig->getValueString(Application::APP_ID, 'pos_eod.alert_email', ''));
        if ($to === '') {
            return;
        }

        try {
            $message = $this->mailer->createMessage();
            $message->setTo([$to]);
            $message->setSubject($subject);
            $message->setPlainBody($body);
            $this->mailer->send($message);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: POS bookkeeping alert email not sent',
                ['exception' => $e->getMessage(), 'to' => $to, 'subject' => $subject]
            );
        }
    }//end sendAlertEmail()

    /**
     * Fetch the persisted default glAccountMapping profile.
     *
     * Returns the first object with isDefault=true, or the first profile when
     * none is marked default, or an empty array when none exist.
     *
     * @return array<string, mixed> The mapping, or [] when none is configured.
     */
    private function fetchDefaultMapping(): array
    {
        [$register, $schema] = $this->config(schemaKey: 'glAccountMapping_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: failed to fetch GL account mapping',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $first    = null;
        $defaults = [];
        foreach (($results ?? []) as $result) {
            $row   = $this->toArray(object: $result);
            $first = $first ?? $row;
            if (($row['isDefault'] ?? false) === true) {
                $defaults[] = $row;
            }
        }

        if ($defaults !== []) {
            return $defaults[0];
        }

        return $first ?? [];
    }//end fetchDefaultMapping()

    /**
     * Build a rate-indexed lookup table from a mapping's taxRateMappings.
     *
     * @param array<string, mixed> $mapping The mapping object.
     *
     * @return array<int, array{debit: string, credit: string}> Per-rate accounts.
     */
    private function rateAccountIndex(array $mapping): array
    {
        $index = [];
        foreach (($mapping['taxRateMappings'] ?? []) as $row) {
            $rate = (int) (float) ($row['taxRate'] ?? 0);
            $index[$rate]['debit']  = (string) ($row['debitAccount'] ?? '');
            $index[$rate]['credit'] = (string) ($row['creditAccount'] ?? '');
        }

        return $index;
    }//end rateAccountIndex()

    /**
     * Find tax rates in the breakdown that have no entry in the mapping.
     *
     * @param array<int, array<string, mixed>> $taxBreakdown The Z-report's breakdown.
     * @param array<string, mixed>             $mapping      The GL mapping profile.
     *
     * @return array<int, int> The missing rates (integers).
     */
    private function missingRates(array $taxBreakdown, array $mapping): array
    {
        $index   = $this->rateAccountIndex(mapping: $mapping);
        $missing = [];
        foreach ($taxBreakdown as $row) {
            $rate = (int) (float) ($row['rate'] ?? 0);
            if (isset($index[$rate]) === false) {
                $missing[] = $rate;
            }
        }

        return array_values(array_unique($missing));
    }//end missingRates()

    /**
     * Fetch confirmed/settled posTransactions matching a date + optional terminal.
     *
     * Reads every posTransaction, then filters in-PHP by status (confirmed,
     * settled), terminalId (when supplied) and reportDate (matching the day
     * of `settledAt` or `confirmedAt` in UTC). A row that cannot be parsed
     * simply does not contribute — the report degrades gracefully rather
     * than aborting on a single malformed transaction.
     *
     * @param string      $reportDate The settlement date in YYYY-MM-DD.
     * @param string|null $terminalId Optional terminal filter.
     *
     * @return array<int, array<string, mixed>> The matching transactions.
     */
    private function fetchTransactionsForDate(string $reportDate, ?string $terminalId): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: failed to read POS transactions for Z-report; total assumed 0',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $matches = [];
        foreach (($results ?? []) as $result) {
            $txn    = $this->toArray(object: $result);
            $status = (string) ($txn['status'] ?? '');
            if (in_array($status, ['confirmed', 'settled'], true) === false) {
                continue;
            }

            if ($terminalId !== null && $terminalId !== '' && (string) ($txn['terminalId'] ?? '') !== $terminalId) {
                continue;
            }

            $stamp = (string) ($txn['settledAt'] ?? $txn['confirmedAt'] ?? '');
            $day   = $this->isoDay(value: $stamp);
            if ($day !== $reportDate) {
                continue;
            }

            $matches[] = $txn;
        }

        return $matches;
    }//end fetchTransactionsForDate()

    /**
     * Assert the user is a POS manager (accounting role).
     *
     * @param string $userId The acting user UID.
     *
     * @return void
     *
     * @throws OCSForbiddenException When the user is not a manager / admin.
     */
    private function requireManager(string $userId): void
    {
        if ($this->policy->isManager(userId: $userId) === false) {
            throw new OCSForbiddenException('Alleen accounting-beheerders mogen Z-reports indienen bij Shillinq.');
        }
    }//end requireManager()

    /**
     * Fetch a posZReport by UUID (scoped to this app's schema).
     *
     * @param string $id The Z-report UUID.
     *
     * @return array<string, mixed> The Z-report.
     *
     * @throws OCSNotFoundException When the Z-report is not found.
     */
    private function fetchZReport(string $id): array
    {
        return $this->fetchOne(schemaKey: 'posZReport_schema', id: $id, label: 'Z-report niet gevonden.');
    }//end fetchZReport()

    /**
     * Fetch a posJournalEntryOutbound by UUID (scoped to this app's schema).
     *
     * @param string $id The outbound message UUID.
     *
     * @return array<string, mixed> The outbound message.
     *
     * @throws OCSNotFoundException When the outbound is not found.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#3.2
     */
    public function fetchOutbound(string $id): array
    {
        return $this->fetchOne(
            schemaKey: 'posJournalEntryOutbound_schema',
            id: $id,
            label: 'Outbound bericht niet gevonden.'
        );
    }//end fetchOutbound()

    /**
     * Fetch a single object by UUID for a schema config key.
     *
     * @param string $schemaKey The app-config schema key.
     * @param string $id        The object UUID.
     * @param string $label     The not-found error message.
     *
     * @return array<string, mixed> The object.
     *
     * @throws OCSNotFoundException When the object is not found.
     */
    private function fetchOne(string $schemaKey, string $id, string $label): array
    {
        [$register, $schema] = $this->config(schemaKey: $schemaKey);

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException($label);
        }

        return $this->toArray(object: $object);
    }//end fetchOne()

    /**
     * Persist an object via the OR ObjectService for a schema config key.
     *
     * @param string               $schemaKey The app-config schema key.
     * @param string               $id        The object UUID (empty to create).
     * @param array<string, mixed> $object    The object data.
     *
     * @return array<string, mixed> The saved object.
     */
    private function saveObjectFor(string $schemaKey, string $id, array $object): array
    {
        [$register, $schema] = $this->config(schemaKey: $schemaKey);

        unset($object['@self']);

        $saved = $this->getObjectService()->saveObject(
            object: $object,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $id
        );

        return $this->toArray(object: $saved);
    }//end saveObjectFor()

    /**
     * Resolve the register + a schema config key into their stored IDs.
     *
     * @param string $schemaKey The app-config schema key.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws OCSNotFoundException When the register or schema is not configured.
     */
    private function config(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

        if ($register === '' || $schema === '') {
            throw new OCSNotFoundException('Bookkeeping-register of -schema is niet geconfigureerd.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Read the configured Shillinq endpoint (trimmed; without trailing slash).
     *
     * @return string The endpoint, or empty string when unset.
     */
    private function shillinqEndpoint(): string
    {
        return trim($this->appConfig->getValueString(Application::APP_ID, 'pos_eod.shillinq_endpoint', ''));
    }//end shillinqEndpoint()

    /**
     * Read the configured Shillinq bearer token (sensitive).
     *
     * @return string The token, or empty string when unset.
     */
    private function shillinqToken(): string
    {
        return trim($this->appConfig->getValueString(Application::APP_ID, 'pos_eod.shillinq_token', ''));
    }//end shillinqToken()

    /**
     * Read the configured max retry attempts (default {@see self::DEFAULT_MAX_ATTEMPTS}).
     *
     * @return int The max attempts (>= 1).
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function maxAttempts(): int
    {
        $raw = trim(
            $this->appConfig->getValueString(
                Application::APP_ID,
                'pos_eod.max_retry_attempts',
                (string) self::DEFAULT_MAX_ATTEMPTS
            )
        );

        $candidate = (int) $raw;
        if ($candidate > 0) {
            return $candidate;
        }

        return self::DEFAULT_MAX_ATTEMPTS;
    }//end maxAttempts()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Normalise an OR object (entity or array) into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
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

        return (array) $object;
    }//end toArray()

    /**
     * The greater of two ISO 8601 timestamp strings (null-safe).
     *
     * @param string|null $left  The left value.
     * @param string|null $right The right value.
     *
     * @return string|null The greater timestamp, or null when both are empty.
     */
    private function maxIso(?string $left, ?string $right): ?string
    {
        $l = $left;
        if ($left === null || $left === '') {
            $l = null;
        }

        $r = $right;
        if ($right === null || $right === '') {
            $r = null;
        }

        if ($l === null) {
            return $r;
        }

        if ($r === null) {
            return $l;
        }

        if (strcmp($l, $r) >= 0) {
            return $l;
        }

        return $r;
    }//end maxIso()

    /**
     * Extract the day portion of an ISO 8601 timestamp in UTC.
     *
     * @param string $value The ISO 8601 timestamp.
     *
     * @return string The day in YYYY-MM-DD, or empty string on parse failure.
     */
    private function isoDay(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            $dt = new DateTimeImmutable($value);
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        } catch (\Throwable $e) {
            return '';
        }
    }//end isoDay()

    /**
     * Whether a YYYY-MM-DD string is a parseable date.
     *
     * @param string $value The candidate date.
     *
     * @return bool True when valid.
     */
    private function isValidDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        try {
            new DateTimeImmutable($value);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }//end isValidDate()

    /**
     * Current time as an ISO 8601 string in UTC.
     *
     * Public so the background jobs and unit tests can stamp timestamps
     * consistently without each coupling directly to the date-time classes.
     *
     * @return string The current timestamp.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.1
     */
    public function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }//end now()

    /**
     * Generate a v4 UUID.
     *
     * @return string The UUID.
     */
    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }//end uuid()
}//end class
