<?php

/**
 * Pipelinq PosTransactionService.
 *
 * Business logic for POS transaction lifecycle, server-authoritative total /
 * tax calculation, and CloudEvent emission on confirmation.
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
 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for POS transaction business operations.
 *
 * All monetary totals are computed server-side from the persisted line items
 * (server-authoritative). Client-supplied subtotal / tax / total values are
 * never trusted: confirm and recalculate always re-derive them from the lines.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the collaborators a POS
 *  lifecycle service legitimately needs (OR container, app config, group
 *  manager, optional webhook dispatch, logger); splitting them would add
 *  indirection without reducing real coupling.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class aggregates the
 *  whole POS lifecycle (calc core + five transitions + event emit + OR
 *  persistence helpers) as many small, single-purpose methods; the cohesion is
 *  intentional and splitting it would scatter one transactional concern across
 *  several classes without reducing real complexity.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) The public surface is the POS
 *  calculation core (recalculateLine / computeTotals / normalizePriceMode), the
 *  BTW report aggregator (buildTaxReport / taxReport), the five lifecycle
 *  transitions, the manager gate and the event emitter — all single-purpose and
 *  unit-tested individually; collapsing them would only hide tested seams.
 *
 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
 */
class PosTransactionService
{
    /**
     * CloudEvent type emitted on confirmation.
     *
     * @var string
     */
    public const EVENT_CONFIRMED = 'pipelinq.PosTransaction.confirmed';

    /**
     * CloudEvents source identifier for this app's POS surface.
     *
     * @var string
     */
    private const EVENT_SOURCE = '/apps/pipelinq/pos';

    /**
     * Statuses from which a transaction may be confirmed.
     *
     * @var array<int, string>
     */
    private const CONFIRMABLE_FROM = ['draft', 'parked'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container    The DI container.
     * @param IAppConfig         $appConfig    The app config.
     * @param IGroupManager      $groupManager The group manager.
     * @param LoggerInterface    $logger       The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Valid price-display / entry modes for a transaction.
     *
     * @var array<int, string>
     */
    private const PRICE_MODES = ['excl', 'incl'];

    /**
     * Default price mode when none is supplied.
     *
     * @var string
     */
    private const DEFAULT_PRICE_MODE = 'excl';

    /**
     * Human-readable Dutch GL descriptions per common BTW rate, used to label
     * the invoiceBreakdown lines shillinq posts. Falls back to a generated
     * "X% BTW" string for any rate not listed here.
     *
     * @var array<int, string>
     */
    private const RATE_DESCRIPTIONS = [
        0  => 'Nultarief (0%)',
        9  => 'Verlaagd tarief (9%)',
        21 => 'Standaardtarief (21%)',
    ];

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
     * Normalise a client-supplied price mode to a known value.
     *
     * Defaults to tax-exclusive ('excl') for any unknown / missing value so a
     * malformed mode can never change the tax base unexpectedly.
     *
     * @param mixed $mode The raw price mode.
     *
     * @return string Either 'excl' or 'incl'.
     *
     * @spec openspec/changes/pos-nl-btw-engine/specs/pos-nl-btw-engine/spec.md#REQ-BTW-004
     */
    public function normalizePriceMode(mixed $mode): string
    {
        $value = '';
        if (is_string($mode) === true) {
            $value = strtolower(trim($mode));
        }

        if (in_array($value, self::PRICE_MODES, true) === true) {
            return $value;
        }

        return self::DEFAULT_PRICE_MODE;
    }//end normalizePriceMode()

    /**
     * Human-readable Dutch GL description for a BTW rate.
     *
     * @param float $rate The BTW rate percentage.
     *
     * @return string The description.
     *
     * @spec openspec/changes/pos-nl-btw-engine/specs/pos-nl-btw-engine/spec.md#REQ-BTW-003
     */
    private function rateDescription(float $rate): string
    {
        $intRate = (int) round($rate);
        if (isset(self::RATE_DESCRIPTIONS[$intRate]) === true) {
            return self::RATE_DESCRIPTIONS[$intRate];
        }

        return rtrim(rtrim((string) $rate, '0'), '.').'% BTW';
    }//end rateDescription()

    /**
     * Compute taxAmount and lineTotal for a single line.
     *
     * Pure function: trusts only quantity, unitPrice, discount and taxRate from
     * the input and overwrites any client-supplied taxAmount / lineTotal. This
     * is the server-authoritative price computation referenced by REQ-POS-002.
     *
     * The optional $priceMode selects how the entered unitPrice is interpreted:
     *   - 'excl' (default): unitPrice is the net price; tax is added on top.
     *       net       = quantity * unitPrice * (1 - discount/100)
     *       taxAmount = net * taxRate/100
     *   - 'incl': unitPrice already contains BTW; the net is extracted out of it.
     *       gross     = quantity * unitPrice * (1 - discount/100)
     *       net       = gross / (1 + taxRate/100)
     *       taxAmount = gross - net
     * In both modes:
     *   lineTotal = net + taxAmount   (the gross, BTW-inclusive line amount)
     * and the persisted `net` field always carries the tax-exclusive base so the
     * per-rate breakdown is identical regardless of how prices were entered.
     *
     * @param array<string, mixed> $lineData  The raw line data.
     * @param string|null          $priceMode The transaction price mode ('excl'|'incl');
     *                                        null falls back to the line's own priceMode
     *                                        or the 'excl' default.
     *
     * @return array<string, mixed> The line data with computed net, taxAmount and lineTotal.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     * @spec openspec/changes/pos-nl-btw-engine/specs/pos-nl-btw-engine/spec.md#REQ-BTW-004
     */
    public function recalculateLine(array $lineData, ?string $priceMode=null): array
    {
        $quantity  = max(0.0, (float) ($lineData['quantity'] ?? 0));
        $unitPrice = max(0.0, (float) ($lineData['unitPrice'] ?? 0));
        $discount  = min(100.0, max(0.0, (float) ($lineData['discount'] ?? 0)));
        $taxRate   = min(100.0, max(0.0, (float) ($lineData['taxRate'] ?? 21)));

        $mode = $this->normalizePriceMode(mode: ($priceMode ?? ($lineData['priceMode'] ?? null)));

        $amount = ($quantity * $unitPrice * (1 - ($discount / 100)));

        // Default ('excl'): entered amount is the net base, BTW added on top.
        $net       = $amount;
        $taxAmount = ($net * ($taxRate / 100));

        if ($mode === 'incl') {
            // Entered amount is BTW-inclusive: extract the net base out of it.
            $net       = ($amount / (1 + ($taxRate / 100)));
            $taxAmount = ($amount - $net);
        }

        $lineData['quantity']  = $quantity;
        $lineData['unitPrice'] = $unitPrice;
        $lineData['discount']  = $discount;
        $lineData['taxRate']   = $taxRate;
        $lineData['net']       = $this->money(value: $net);
        $lineData['taxAmount'] = $this->money(value: $taxAmount);
        $lineData['lineTotal'] = $this->money(value: ($net + $taxAmount));

        return $lineData;
    }//end recalculateLine()

    /**
     * Compute aggregate totals for a transaction from its line items.
     *
     * Pure function used by recalculateTotals(): groups tax by rate into a
     * taxBreakdown array and a GL-oriented invoiceBreakdown array (with Dutch
     * descriptions), and sums subtotal, discountTotal, totalTax and total.
     * Server-authoritative — derives every figure from the (re)computed lines.
     *
     * The optional $priceMode is threaded into recalculateLine so the per-line
     * net base is extracted correctly whether prices were entered incl. or excl.
     * BTW. The breakdown bases are always tax-exclusive, so the GL split shillinq
     * receives is identical regardless of entry mode.
     *
     * @param array<int, array<string, mixed>> $lines     The transaction's line items.
     * @param string|null                      $priceMode The transaction price mode
     *                                                    ('excl'|'incl'); null defaults to 'excl'.
     *
     * @return array<string, mixed> Computed totals: priceMode, subtotal, discountTotal,
     *                               taxBreakdown, invoiceBreakdown, totalTax, total.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     * @spec openspec/changes/pos-nl-btw-engine/specs/pos-nl-btw-engine/spec.md#REQ-BTW-002, #REQ-BTW-003, #REQ-BTW-004
     */
    public function computeTotals(array $lines, ?string $priceMode=null): array
    {
        $mode          = $this->normalizePriceMode(mode: $priceMode);
        $subtotal      = 0.0;
        $discountTotal = 0.0;
        $totalTax      = 0.0;
        $byRate        = [];

        foreach ($lines as $rawLine) {
            $line    = $this->recalculateLine(lineData: $rawLine, priceMode: $mode);
            $taxRate = (float) $line['taxRate'];

            // Net base is the tax-exclusive amount the line computed for this
            // price mode; the gross-before-discount uses the same mode so the
            // discount figure is consistent (incl-mode strips BTW out first).
            $net         = (float) $line['net'];
            $taxAmount   = (float) $line['taxAmount'];
            $grossNoDisc = $this->lineNetBeforeDiscount(line: $line, mode: $mode);

            $subtotal      += $net;
            $discountTotal += ($grossNoDisc - $net);
            $totalTax      += $taxAmount;

            $rateKey = (string) $taxRate;
            if (isset($byRate[$rateKey]) === false) {
                $byRate[$rateKey] = ['rate' => $taxRate, 'base' => 0.0, 'tax' => 0.0];
            }

            $byRate[$rateKey]['base'] += $net;
            $byRate[$rateKey]['tax']  += $taxAmount;
        }//end foreach

        $taxBreakdown     = [];
        $invoiceBreakdown = [];
        ksort($byRate, SORT_NUMERIC);
        foreach ($byRate as $entry) {
            $base = $this->money(value: $entry['base']);
            $tax  = $this->money(value: $entry['tax']);

            $taxBreakdown[] = [
                'rate' => $entry['rate'],
                'base' => $base,
                'tax'  => $tax,
            ];

            $invoiceBreakdown[] = [
                'rate'        => $entry['rate'],
                'base'        => $base,
                'tax'         => $tax,
                'description' => $this->rateDescription(rate: (float) $entry['rate']),
            ];
        }//end foreach

        $subtotal = $this->money(value: $subtotal);
        $totalTax = $this->money(value: $totalTax);

        return [
            'priceMode'        => $mode,
            'subtotal'         => $subtotal,
            'discountTotal'    => $this->money(value: $discountTotal),
            'taxBreakdown'     => $taxBreakdown,
            'invoiceBreakdown' => $invoiceBreakdown,
            'totalTax'         => $totalTax,
            'total'            => $this->money(value: ($subtotal + $totalTax)),
        ];
    }//end computeTotals()

    /**
     * Tax-exclusive line amount before the line discount, for the given mode.
     *
     * In 'excl' mode this is quantity * unitPrice; in 'incl' mode the BTW is
     * first stripped from the entered (gross) unit price so the discount delta
     * is measured on a consistent tax-exclusive basis.
     *
     * @param array<string, mixed> $line The recomputed line.
     * @param string               $mode The normalised price mode.
     *
     * @return float The net amount before discount.
     *
     * @spec openspec/changes/pos-nl-btw-engine/specs/pos-nl-btw-engine/spec.md#REQ-BTW-004
     */
    private function lineNetBeforeDiscount(array $line, string $mode): float
    {
        $quantity  = (float) $line['quantity'];
        $unitPrice = (float) $line['unitPrice'];
        $taxRate   = (float) $line['taxRate'];
        $gross     = ($quantity * $unitPrice);

        if ($mode === 'incl') {
            return ($gross / (1 + ($taxRate / 100)));
        }

        return $gross;
    }//end lineNetBeforeDiscount()

    /**
     * Aggregate a per-rate BTW compliance report across a set of transactions.
     *
     * Sums each transaction's server-computed invoiceBreakdown (falling back to
     * taxBreakdown for legacy records that predate this engine) into a single
     * per-rate split that shillinq can post as GL journal lines. Refunded
     * transactions contribute their breakdown as negative (reversing) amounts so
     * the report reflects net VAT liability.
     *
     * Only confirmed / settled / refunded transactions count toward the report;
     * drafts and parked carts are excluded as they are not yet fiscally final.
     *
     * @param array<int, array<string, mixed>> $transactions The transactions to aggregate.
     *
     * @return array<string, mixed> A report: rates (per-rate base/tax/description),
     *                              totals, and the count of transactions included.
     *
     * @spec openspec/changes/pos-nl-btw-engine/specs/pos-nl-btw-engine/spec.md#REQ-BTW-003
     */
    public function buildTaxReport(array $transactions): array
    {
        $byRate        = [];
        $totalBase     = 0.0;
        $totalTax      = 0.0;
        $includedCount = 0;

        foreach ($transactions as $transaction) {
            $status = (string) ($transaction['status'] ?? '');
            if (in_array($status, ['confirmed', 'settled', 'refunded'], true) === false) {
                continue;
            }

            $sign = 1.0;
            if ($status === 'refunded') {
                $sign = -1.0;
            }

            $rows = $transaction['invoiceBreakdown'] ?? $transaction['taxBreakdown'] ?? [];
            if (is_array($rows) === false) {
                continue;
            }

            $includedCount++;
            foreach ($rows as $row) {
                if (is_array($row) === false) {
                    continue;
                }

                $rate    = (float) ($row['rate'] ?? 0);
                $base    = ($sign * (float) ($row['base'] ?? 0));
                $tax     = ($sign * (float) ($row['tax'] ?? 0));
                $rateKey = (string) $rate;

                if (isset($byRate[$rateKey]) === false) {
                    $byRate[$rateKey] = ['rate' => $rate, 'base' => 0.0, 'tax' => 0.0];
                }

                $byRate[$rateKey]['base'] += $base;
                $byRate[$rateKey]['tax']  += $tax;
                $totalBase += $base;
                $totalTax  += $tax;
            }//end foreach
        }//end foreach

        $rates = [];
        ksort($byRate, SORT_NUMERIC);
        foreach ($byRate as $entry) {
            $rates[] = [
                'rate'        => $entry['rate'],
                'base'        => $this->money(value: $entry['base']),
                'tax'         => $this->money(value: $entry['tax']),
                'description' => $this->rateDescription(rate: (float) $entry['rate']),
            ];
        }

        return [
            'rates'            => $rates,
            'totalBase'        => $this->money(value: $totalBase),
            'totalTax'         => $this->money(value: $totalTax),
            'transactionCount' => $includedCount,
        ];
    }//end buildTaxReport()

    /**
     * Build the per-rate BTW compliance report for every transaction in this
     * app's register (optionally narrowed to a status).
     *
     * @param string|null $status Optional status filter (confirmed/settled/refunded).
     *
     * @return array<string, mixed> The aggregated report.
     *
     * @spec openspec/changes/pos-nl-btw-engine/specs/pos-nl-btw-engine/spec.md#REQ-BTW-003
     */
    public function taxReport(?string $status=null): array
    {
        $transactions = $this->fetchAllTransactions(status: $status);

        return $this->buildTaxReport(transactions: $transactions);
    }//end taxReport()

    /**
     * Recompute and persist totals for a transaction from its persisted lines.
     *
     * @param string $transactionId The transaction UUID.
     *
     * @return array<string, mixed> The updated transaction object.
     *
     * @throws OCSNotFoundException If the transaction does not exist in this app's register.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    public function recalculateTotals(string $transactionId): array
    {
        $transaction = $this->fetchTransaction(id: $transactionId);
        $mode        = $this->normalizePriceMode(mode: ($transaction['priceMode'] ?? null));
        $lines       = $this->fetchLines(transactionId: $transactionId);
        $totals      = $this->computeTotals(lines: $lines, priceMode: $mode);

        $transaction = array_merge($transaction, $totals);

        return $this->saveTransaction(id: $transactionId, transaction: $transaction);
    }//end recalculateTotals()

    /**
     * Confirm a draft or parked transaction.
     *
     * Recomputes server-authoritative totals, validates the cart is non-empty,
     * sets status=confirmed + confirmedAt, persists, then emits the
     * pipelinq.PosTransaction.confirmed CloudEvent (fire-and-forget).
     *
     * @param string $id     The transaction UUID.
     * @param string $userId The acting user UID.
     *
     * @return array<string, mixed> The confirmed transaction.
     *
     * @throws OCSNotFoundException   If the transaction does not exist.
     * @throws OCSBadRequestException If status is invalid or the cart is empty.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    public function confirmTransaction(string $id, string $userId): array
    {
        $transaction = $this->fetchTransaction(id: $id);
        $status      = (string) ($transaction['status'] ?? '');

        if (in_array($status, self::CONFIRMABLE_FROM, true) === false) {
            throw new OCSBadRequestException('Alleen concept- of geparkeerde transacties kunnen worden bevestigd.');
        }

        $lines = $this->fetchLines(transactionId: $id);
        if (count($lines) === 0) {
            throw new OCSBadRequestException('Voeg minimaal één artikel toe.');
        }

        $mode        = $this->normalizePriceMode(mode: ($transaction['priceMode'] ?? null));
        $totals      = $this->computeTotals(lines: $lines, priceMode: $mode);
        $confirmedAt = $this->now();

        $transaction = array_merge(
            $transaction,
            $totals,
            [
                'status'      => 'confirmed',
                'confirmedAt' => $confirmedAt,
                'parkedAt'    => null,
            ]
        );

        $saved = $this->saveTransaction(id: $id, transaction: $transaction);

        $eventId = $this->emitConfirmedEvent(transaction: $saved);
        if ($eventId !== '') {
            $saved['cloudEventId'] = $eventId;
            $saved = $this->saveTransaction(id: $id, transaction: $saved);
        }

        $this->logger->info('Pipelinq: POS transaction confirmed', ['id' => $id, 'userId' => $userId]);

        return $saved;
    }//end confirmTransaction()

    /**
     * Settle a confirmed transaction (payment received).
     *
     * @param string $id     The transaction UUID.
     * @param string $userId The acting user UID.
     *
     * @return array<string, mixed> The settled transaction.
     *
     * @throws OCSNotFoundException   If the transaction does not exist.
     * @throws OCSBadRequestException If the transaction is not confirmed.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    public function settleTransaction(string $id, string $userId): array
    {
        $transaction = $this->fetchTransaction(id: $id);

        if ((string) ($transaction['status'] ?? '') !== 'confirmed') {
            throw new OCSBadRequestException('Transactie moet bevestigd zijn voor afrekenen.');
        }

        $transaction['status']    = 'settled';
        $transaction['settledAt'] = $this->now();

        $this->logger->info('Pipelinq: POS transaction settled', ['id' => $id, 'userId' => $userId]);

        return $this->saveTransaction(id: $id, transaction: $transaction);
    }//end settleTransaction()

    /**
     * Refund / void a confirmed or settled transaction (manager only).
     *
     * @param string $id     The transaction UUID.
     * @param string $reason The refund reason (required).
     * @param string $userId The acting user UID.
     *
     * @return array<string, mixed> The refunded transaction.
     *
     * @throws OCSNotFoundException   If the transaction does not exist.
     * @throws OCSForbiddenException  If the user lacks manager permission.
     * @throws OCSBadRequestException If the status is invalid or the reason is empty.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    public function refundTransaction(string $id, string $reason, string $userId): array
    {
        if ($this->isManager(userId: $userId) === false) {
            throw new OCSForbiddenException('Alleen een beheerder mag een transactie terugboeken.');
        }

        if (trim($reason) === '') {
            throw new OCSBadRequestException('Vul een reden in voor de terugboeking.');
        }

        $transaction = $this->fetchTransaction(id: $id);
        $status      = (string) ($transaction['status'] ?? '');

        if (in_array($status, ['confirmed', 'settled'], true) === false) {
            throw new OCSBadRequestException('Alleen bevestigde of afgerekende transacties kunnen worden teruggeboekt.');
        }

        $transaction['status']       = 'refunded';
        $transaction['refundedAt']   = $this->now();
        $transaction['refundReason'] = $reason;

        $this->logger->info('Pipelinq: POS transaction refunded', ['id' => $id, 'userId' => $userId]);

        return $this->saveTransaction(id: $id, transaction: $transaction);
    }//end refundTransaction()

    /**
     * Park a draft transaction so it can be resumed later.
     *
     * @param string $id     The transaction UUID.
     * @param string $userId The acting user UID.
     *
     * @return array<string, mixed> The parked transaction.
     *
     * @throws OCSNotFoundException   If the transaction does not exist.
     * @throws OCSBadRequestException If the transaction is not a draft.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    public function parkTransaction(string $id, string $userId): array
    {
        $transaction = $this->fetchTransaction(id: $id);

        if ((string) ($transaction['status'] ?? '') !== 'draft') {
            throw new OCSBadRequestException('Alleen concept-transacties kunnen worden geparkeerd.');
        }

        $transaction['status']   = 'parked';
        $transaction['parkedAt'] = $this->now();

        $this->logger->info('Pipelinq: POS transaction parked', ['id' => $id, 'userId' => $userId]);

        return $this->saveTransaction(id: $id, transaction: $transaction);
    }//end parkTransaction()

    /**
     * Resume a parked transaction back to draft.
     *
     * @param string $id     The transaction UUID.
     * @param string $userId The acting user UID.
     *
     * @return array<string, mixed> The resumed transaction.
     *
     * @throws OCSNotFoundException   If the transaction does not exist.
     * @throws OCSBadRequestException If the transaction is not parked.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    public function resumeTransaction(string $id, string $userId): array
    {
        $transaction = $this->fetchTransaction(id: $id);

        if ((string) ($transaction['status'] ?? '') !== 'parked') {
            throw new OCSBadRequestException('Alleen geparkeerde transacties kunnen worden hervat.');
        }

        $transaction['status']   = 'draft';
        $transaction['parkedAt'] = null;

        $this->logger->info('Pipelinq: POS transaction resumed', ['id' => $id, 'userId' => $userId]);

        return $this->saveTransaction(id: $id, transaction: $transaction);
    }//end resumeTransaction()

    /**
     * Emit the pipelinq.PosTransaction.confirmed CloudEvent (fire-and-forget).
     *
     * Dispatched through OpenRegister's WebhookService, which delivers to any
     * webhook subscribed to the event name (e.g. Shillinq's accounting
     * consumer). If OR / WebhookService is unavailable, or no consumer is yet
     * configured, this is a silent no-op — confirmation must never fail because
     * a downstream subscriber is missing.
     *
     * @param array<string, mixed> $transaction The confirmed transaction.
     *
     * @return string The generated CloudEvents id, or empty string on failure.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    public function emitConfirmedEvent(array $transaction): string
    {
        $eventId = $this->uuid();
        $payload = $this->buildConfirmedPayload(eventId: $eventId, transaction: $transaction);

        try {
            $webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
            $event          = new Event();
            $webhookService->dispatchEvent(_event: $event, eventName: self::EVENT_CONFIRMED, payload: $payload);
            return $eventId;
        } catch (\Throwable $e) {
            // Fire-and-forget: a missing/failed downstream subscriber must not
            // block confirmation. Log and continue without a stored event id.
            $this->logger->warning(
                'Pipelinq: POS confirmed CloudEvent not dispatched (no consumer or OR unavailable)',
                ['exception' => $e->getMessage()]
            );
            return '';
        }//end try
    }//end emitConfirmedEvent()

    /**
     * Build the CloudEvents 1.0 envelope for a confirmed transaction.
     *
     * @param string               $eventId     The CloudEvents id.
     * @param array<string, mixed> $transaction The confirmed transaction.
     *
     * @return array<string, mixed> The CloudEvent payload.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    private function buildConfirmedPayload(string $eventId, array $transaction): array
    {
        $confirmedAt = (string) ($transaction['confirmedAt'] ?? '');

        $time = $this->now();
        if ($confirmedAt !== '') {
            $time = $confirmedAt;
        }

        return [
            'specversion'     => '1.0',
            'type'            => self::EVENT_CONFIRMED,
            'source'          => self::EVENT_SOURCE,
            'id'              => $eventId,
            'time'            => $time,
            'datacontenttype' => 'application/json',
            'data'            => [
                'transactionId'    => (string) ($transaction['id'] ?? $transaction['uuid'] ?? ''),
                'reference'        => (string) ($transaction['reference'] ?? ''),
                'cashier'          => (string) ($transaction['cashier'] ?? ''),
                'total'            => (float) ($transaction['total'] ?? 0),
                'totalTax'         => (float) ($transaction['totalTax'] ?? 0),
                'priceMode'        => $this->normalizePriceMode(mode: ($transaction['priceMode'] ?? null)),
                'taxBreakdown'     => ($transaction['taxBreakdown'] ?? []),
                'invoiceBreakdown' => ($transaction['invoiceBreakdown'] ?? []),
                'confirmedAt'      => $confirmedAt,
            ],
        ];
    }//end buildConfirmedPayload()

    /**
     * Whether a user has manager permission for refund / void.
     *
     * A manager is a member of the configured manager group
     * (`pos_manager_group`, default `admin`) or a Nextcloud administrator.
     * Fails closed: if no group is configured, only NC admins qualify.
     *
     * @param string $userId The user UID.
     *
     * @return bool Whether the user is a POS manager.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    public function isManager(string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        if ($this->groupManager->isAdmin($userId) === true) {
            return true;
        }

        $managerGroup = $this->appConfig->getValueString(Application::APP_ID, 'pos_manager_group', '');
        if ($managerGroup === '') {
            return false;
        }

        return $this->groupManager->isInGroup($userId, $managerGroup);
    }//end isManager()

    /**
     * Fetch a transaction from this app's register, as an array.
     *
     * @param string $id The transaction UUID.
     *
     * @return array<string, mixed> The transaction data.
     *
     * @throws OCSNotFoundException If the object is not found in this app's posTransaction schema.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    private function fetchTransaction(string $id): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException('Transactie niet gevonden.');
        }

        return $this->toArray(object: $object);
    }//end fetchTransaction()

    /**
     * Fetch all line items belonging to a transaction.
     *
     * @param string $transactionId The parent transaction UUID.
     *
     * @return array<int, array<string, mixed>> The line items.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    private function fetchLines(string $transactionId): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTransactionLine_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register'    => $register,
                        'schema'      => $schema,
                        'transaction' => $transactionId,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to fetch POS lines', ['exception' => $e->getMessage()]);
            return [];
        }

        $lines = [];
        foreach (($results ?? []) as $result) {
            $lines[] = $this->toArray(object: $result);
        }

        return $lines;
    }//end fetchLines()

    /**
     * Fetch all transactions in this app's register, optionally by status.
     *
     * @param string|null $status Optional status filter.
     *
     * @return array<int, array<string, mixed>> The transactions.
     *
     * @spec openspec/changes/pos-nl-btw-engine/specs/pos-nl-btw-engine/spec.md#REQ-BTW-003
     */
    private function fetchAllTransactions(?string $status=null): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

        $filters = [
            'register' => $register,
            'schema'   => $schema,
        ];
        if ($status !== null && $status !== '') {
            $filters['status'] = $status;
        }

        try {
            $results = $this->getObjectService()->findAll(config: ['filters' => $filters]);
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to fetch POS transactions', ['exception' => $e->getMessage()]);
            return [];
        }

        $transactions = [];
        foreach (($results ?? []) as $result) {
            $transactions[] = $this->toArray(object: $result);
        }

        return $transactions;
    }//end fetchAllTransactions()

    /**
     * Persist a transaction object via the OR ObjectService.
     *
     * @param string               $id          The transaction UUID.
     * @param array<string, mixed> $transaction The transaction data.
     *
     * @return array<string, mixed> The saved transaction as an array.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    private function saveTransaction(string $id, array $transaction): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

        // Never trust client-derived id/uuid; always write to the resolved id.
        unset($transaction['@self']);

        $saved = $this->getObjectService()->saveObject(
            object: $transaction,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $id
        );

        return $this->toArray(object: $saved);
    }//end saveTransaction()

    /**
     * Resolve the register + a schema config key into their stored IDs.
     *
     * @param string $schemaKey The app-config schema key (e.g. posTransaction_schema).
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws OCSNotFoundException If the register or schema is not configured.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    private function config(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

        if ($register === '' || $schema === '') {
            throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws RuntimeException If OpenRegister is not available.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
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
     * Current time as an ISO 8601 string.
     *
     * @return string The current timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
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
