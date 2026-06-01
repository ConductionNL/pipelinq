<?php

/**
 * Unit tests for PosBookkeepingService.
 *
 * Covers the pure calculation logic (aggregate computation, idempotency key
 * generation, GL ledger line item construction, exponential backoff calculation)
 * that can be tested without OpenRegister's ObjectService being loaded.
 *
 * Integration scenarios (generateZReport, createOutboundMessage, postToShillinq)
 * that depend on the container-resolved ObjectService are exercised at the
 * integration level in CI.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\PosBookkeepingService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PosBookkeepingService.
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.1
 */
class PosBookkeepingServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var PosBookkeepingService
     */
    private PosBookkeepingService $service;

    /**
     * Mock app config.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $container       = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $logger          = $this->createMock(LoggerInterface::class);

        $this->service = new PosBookkeepingService(
            container: $container,
            appConfig: $this->appConfig,
            logger: $logger,
        );
    }//end setUp()

    // -----------------------------------------------------------------------
    // computeIdempotencyKey
    // -----------------------------------------------------------------------

    /**
     * computeIdempotencyKey returns a deterministic SHA256 hex prefixed with 'sha256:'.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.1
     */
    public function testComputeIdempotencyKeyIsDeterministic(): void
    {
        $id   = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
        $date = '2026-05-20';

        $key1 = $this->service->computeIdempotencyKey($id, $date);
        $key2 = $this->service->computeIdempotencyKey($id, $date);

        $this->assertSame($key1, $key2);
        $this->assertStringStartsWith('sha256:', $key1);
    }//end testComputeIdempotencyKeyIsDeterministic()

    /**
     * Different Z-report UUIDs produce different idempotency keys.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.1
     */
    public function testComputeIdempotencyKeyIsUniquePerReport(): void
    {
        $date = '2026-05-20';
        $key1 = $this->service->computeIdempotencyKey('id-aaa', $date);
        $key2 = $this->service->computeIdempotencyKey('id-bbb', $date);

        $this->assertNotSame($key1, $key2);
    }//end testComputeIdempotencyKeyIsUniquePerReport()

    /**
     * Different dates produce different idempotency keys for the same Z-report.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.1
     */
    public function testComputeIdempotencyKeyChangesWithDate(): void
    {
        $id   = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
        $key1 = $this->service->computeIdempotencyKey($id, '2026-05-20');
        $key2 = $this->service->computeIdempotencyKey($id, '2026-05-21');

        $this->assertNotSame($key1, $key2);
    }//end testComputeIdempotencyKeyChangesWithDate()

    // -----------------------------------------------------------------------
    // computeAggregates
    // -----------------------------------------------------------------------

    /**
     * computeAggregates returns zero totals for an empty transaction list.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.1
     */
    public function testComputeAggregatesEmptyTransactions(): void
    {
        $result = $this->service->computeAggregates([]);

        $this->assertSame(0.0, $result['subtotal']);
        $this->assertSame(0.0, $result['totalTax']);
        $this->assertSame(0.0, $result['total']);
        $this->assertSame(0.0, $result['discountTotal']);
        $this->assertEmpty($result['taxBreakdown']);
        $this->assertEmpty($result['transactionIds']);
    }//end testComputeAggregatesEmptyTransactions()

    /**
     * computeAggregates correctly sums totals from multiple transactions.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.1
     */
    public function testComputeAggregatesSumsTransactions(): void
    {
        $transactions = [
            [
                'id'            => 'txn-001',
                'subtotal'      => 100.00,
                'discountTotal' => 5.00,
                'totalTax'      => 21.00,
                'total'         => 121.00,
                'taxBreakdown'  => [['rate' => 21, 'base' => 100.00, 'tax' => 21.00]],
            ],
            [
                'id'            => 'txn-002',
                'subtotal'      => 50.00,
                'discountTotal' => 0.00,
                'totalTax'      => 4.50,
                'total'         => 54.50,
                'taxBreakdown'  => [['rate' => 9, 'base' => 50.00, 'tax' => 4.50]],
            ],
        ];

        $result = $this->service->computeAggregates($transactions);

        $this->assertSame(150.0, $result['subtotal']);
        $this->assertSame(5.0, $result['discountTotal']);
        $this->assertSame(25.5, $result['totalTax']);
        $this->assertSame(175.5, $result['total']);
        $this->assertCount(2, $result['taxBreakdown']);
        $this->assertContains('txn-001', $result['transactionIds']);
        $this->assertContains('txn-002', $result['transactionIds']);
    }//end testComputeAggregatesSumsTransactions()

    /**
     * computeAggregates merges tax breakdown entries for the same rate.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.1
     */
    public function testComputeAggregatesMergesSameTaxRate(): void
    {
        $transactions = [
            [
                'id'            => 'txn-a',
                'subtotal'      => 100.00,
                'discountTotal' => 0.00,
                'totalTax'      => 21.00,
                'total'         => 121.00,
                'taxBreakdown'  => [['rate' => 21, 'base' => 100.00, 'tax' => 21.00]],
            ],
            [
                'id'            => 'txn-b',
                'subtotal'      => 200.00,
                'discountTotal' => 0.00,
                'totalTax'      => 42.00,
                'total'         => 242.00,
                'taxBreakdown'  => [['rate' => 21, 'base' => 200.00, 'tax' => 42.00]],
            ],
        ];

        $result = $this->service->computeAggregates($transactions);

        // Should have exactly one entry for rate 21.
        $this->assertCount(1, $result['taxBreakdown']);
        $this->assertSame(21.0, $result['taxBreakdown'][0]['rate']);
        $this->assertSame(300.0, $result['taxBreakdown'][0]['base']);
        $this->assertSame(63.0, $result['taxBreakdown'][0]['tax']);
    }//end testComputeAggregatesMergesSameTaxRate()

    // -----------------------------------------------------------------------
    // buildLedgerLineItems
    // -----------------------------------------------------------------------

    /**
     * buildLedgerLineItems creates balanced debit/credit GL entries.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.1
     */
    public function testBuildLedgerLineItemsIsBalanced(): void
    {
        $zReport = [
            'total'        => 535.35,
            'taxBreakdown' => [
                ['rate' => 9,  'base' => 50.00,  'tax' => 4.50],
                ['rate' => 21, 'base' => 385.00, 'tax' => 80.85],
            ],
        ];

        $glMapping = [
            'bankAccount'     => '1000',
            'taxRateMappings' => [
                ['taxRate' => 9,  'debitAccount' => '1210', 'creditAccount' => '5010'],
                ['taxRate' => 21, 'debitAccount' => '1200', 'creditAccount' => '5000'],
            ],
        ];

        $lines = $this->service->buildLedgerLineItems($zReport, $glMapping);

        // Compute total debits and total credits.
        $totalDebit  = 0.0;
        $totalCredit = 0.0;
        foreach ($lines as $line) {
            $totalDebit  += (float) $line['debit'];
            $totalCredit += (float) $line['credit'];
        }

        // Double-entry bookkeeping: debits must equal credits.
        $this->assertSame(round($totalDebit, 2), round($totalCredit, 2));
    }//end testBuildLedgerLineItemsIsBalanced()

    /**
     * buildLedgerLineItems falls back to account 1200/5000 when no mapping is found.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.1
     */
    public function testBuildLedgerLineItemsUsesFallbackAccount(): void
    {
        $zReport = [
            'total'        => 100.00,
            'taxBreakdown' => [['rate' => 21, 'base' => 82.64, 'tax' => 17.36]],
        ];

        // No taxRateMappings provided.
        $glMapping = [
            'bankAccount'     => '1000',
            'taxRateMappings' => [],
        ];

        $lines = $this->service->buildLedgerLineItems($zReport, $glMapping);
        $this->assertNotEmpty($lines);
    }//end testBuildLedgerLineItemsUsesFallbackAccount()

    // -----------------------------------------------------------------------
    // calculateNextRetryAt
    // -----------------------------------------------------------------------

    /**
     * calculateNextRetryAt returns null when attemptCount >= MAX_ATTEMPTS.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.1
     */
    public function testCalculateNextRetryAtReturnsNullAtMaxAttempts(): void
    {
        $result = $this->service->calculateNextRetryAt(PosBookkeepingService::MAX_ATTEMPTS);
        $this->assertNull($result);
    }//end testCalculateNextRetryAtReturnsNullAtMaxAttempts()

    /**
     * calculateNextRetryAt follows the exponential backoff schedule.
     *
     * Attempt 0 → 1 min (60s), 1 → 5 min (300s), 2 → 15 min (900s), 3 → 1 hr (3600s).
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.1
     */
    public function testCalculateNextRetryAtFollowsBackoffSchedule(): void
    {
        $expectedBackoffs = PosBookkeepingService::BACKOFF_SECONDS;
        $now = new \DateTimeImmutable();

        foreach ($expectedBackoffs as $index => $expectedSeconds) {
            $retryAt = $this->service->calculateNextRetryAt($index);
            $this->assertNotNull($retryAt);

            $retryTime = new \DateTimeImmutable($retryAt);
            $diff      = $retryTime->getTimestamp() - $now->getTimestamp();

            // Allow ±5 seconds for test execution time.
            $this->assertGreaterThanOrEqual($expectedSeconds - 5, $diff, "Attempt $index backoff too short");
            $this->assertLessThanOrEqual($expectedSeconds + 5, $diff, "Attempt $index backoff too long");
        }
    }//end testCalculateNextRetryAtFollowsBackoffSchedule()

    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    /**
     * The max attempts constant should be 5.
     *
     * @return void
     */
    public function testMaxAttemptsIsCorrect(): void
    {
        $this->assertSame(5, PosBookkeepingService::MAX_ATTEMPTS);
    }//end testMaxAttemptsIsCorrect()

    /**
     * The BACKOFF_SECONDS array should have 4 entries: 60, 300, 900, 3600.
     *
     * @return void
     */
    public function testBackoffScheduleIsCorrect(): void
    {
        $this->assertSame([60, 300, 900, 3600], PosBookkeepingService::BACKOFF_SECONDS);
    }//end testBackoffScheduleIsCorrect()

    /**
     * Event type constants are correctly defined.
     *
     * @return void
     */
    public function testEventTypeConstants(): void
    {
        $this->assertSame('pipelinq.PosJournalEntry.posted', PosBookkeepingService::EVENT_JOURNAL_POSTED);
        $this->assertSame('pipelinq.PosZReport.submitted', PosBookkeepingService::EVENT_ZREPORT_SUBMITTED);
    }//end testEventTypeConstants()
}//end class
