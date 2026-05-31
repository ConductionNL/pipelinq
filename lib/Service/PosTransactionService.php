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

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the collaborators a POS
 *  lifecycle service legitimately needs (OR container, app config, group
 *  manager, optional webhook dispatch, logger); splitting them would add
 *  indirection without reducing real coupling.
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
     * Compute taxAmount and lineTotal for a single line.
     *
     * Pure function: trusts only quantity, unitPrice, discount and taxRate from
     * the input and overwrites any client-supplied taxAmount / lineTotal. This
     * is the server-authoritative price computation referenced by REQ-POS-002.
     *
     * Formula:
     *   net       = quantity * unitPrice * (1 - discount/100)
     *   taxAmount = net * taxRate/100
     *   lineTotal = net + taxAmount
     *
     * @param array<string, mixed> $lineData The raw line data.
     *
     * @return array<string, mixed> The line data with computed taxAmount and lineTotal.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    public function recalculateLine(array $lineData): array
    {
        $quantity  = max(0.0, (float) ($lineData['quantity'] ?? 0));
        $unitPrice = max(0.0, (float) ($lineData['unitPrice'] ?? 0));
        $discount  = min(100.0, max(0.0, (float) ($lineData['discount'] ?? 0)));
        $taxRate   = min(100.0, max(0.0, (float) ($lineData['taxRate'] ?? 21)));

        $net       = ($quantity * $unitPrice * (1 - ($discount / 100)));
        $taxAmount = ($net * ($taxRate / 100));

        $lineData['quantity']  = $quantity;
        $lineData['unitPrice'] = $unitPrice;
        $lineData['discount']  = $discount;
        $lineData['taxRate']   = $taxRate;
        $lineData['taxAmount'] = $this->money($taxAmount);
        $lineData['lineTotal'] = $this->money(($net + $taxAmount));

        return $lineData;
    }//end recalculateLine()

    /**
     * Compute aggregate totals for a transaction from its line items.
     *
     * Pure function used by recalculateTotals(): groups tax by rate into a
     * taxBreakdown array and sums subtotal, discountTotal, totalTax and total.
     * Server-authoritative — derives every figure from the (re)computed lines.
     *
     * @param array<int, array<string, mixed>> $lines The transaction's line items.
     *
     * @return array<string, mixed> Computed totals: subtotal, discountTotal,
     *                               taxBreakdown, totalTax, total.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    public function computeTotals(array $lines): array
    {
        $subtotal      = 0.0;
        $discountTotal = 0.0;
        $totalTax      = 0.0;
        $byRate        = [];

        foreach ($lines as $rawLine) {
            $line      = $this->recalculateLine($rawLine);
            $quantity  = (float) $line['quantity'];
            $unitPrice = (float) $line['unitPrice'];
            $discount  = (float) $line['discount'];
            $taxRate   = (float) $line['taxRate'];

            $gross = ($quantity * $unitPrice);
            $net   = ($gross * (1 - ($discount / 100)));

            $subtotal      += $net;
            $discountTotal += ($gross - $net);
            $totalTax      += (float) $line['taxAmount'];

            $rateKey = (string) $taxRate;
            if (isset($byRate[$rateKey]) === false) {
                $byRate[$rateKey] = ['rate' => $taxRate, 'base' => 0.0, 'tax' => 0.0];
            }

            $byRate[$rateKey]['base'] += $net;
            $byRate[$rateKey]['tax']  += (float) $line['taxAmount'];
        }//end foreach

        $taxBreakdown = [];
        ksort($byRate, SORT_NUMERIC);
        foreach ($byRate as $entry) {
            $taxBreakdown[] = [
                'rate' => $entry['rate'],
                'base' => $this->money($entry['base']),
                'tax'  => $this->money($entry['tax']),
            ];
        }

        $subtotal = $this->money($subtotal);
        $totalTax = $this->money($totalTax);

        return [
            'subtotal'      => $subtotal,
            'discountTotal' => $this->money($discountTotal),
            'taxBreakdown'  => $taxBreakdown,
            'totalTax'      => $totalTax,
            'total'         => $this->money(($subtotal + $totalTax)),
        ];
    }//end computeTotals()

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
        $transaction = $this->fetchTransaction($transactionId);
        $lines       = $this->fetchLines($transactionId);
        $totals      = $this->computeTotals($lines);

        $transaction = array_merge($transaction, $totals);

        return $this->saveTransaction($transactionId, $transaction);
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
        $transaction = $this->fetchTransaction($id);
        $status      = (string) ($transaction['status'] ?? '');

        if (in_array($status, self::CONFIRMABLE_FROM, true) === false) {
            throw new OCSBadRequestException('Alleen concept- of geparkeerde transacties kunnen worden bevestigd.');
        }

        $lines = $this->fetchLines($id);
        if (count($lines) === 0) {
            throw new OCSBadRequestException('Voeg minimaal één artikel toe.');
        }

        $totals      = $this->computeTotals($lines);
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

        $saved = $this->saveTransaction($id, $transaction);

        $eventId = $this->emitConfirmedEvent($saved);
        if ($eventId !== '') {
            $saved['cloudEventId'] = $eventId;
            $saved                 = $this->saveTransaction($id, $saved);
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
        $transaction = $this->fetchTransaction($id);

        if ((string) ($transaction['status'] ?? '') !== 'confirmed') {
            throw new OCSBadRequestException('Transactie moet bevestigd zijn voor afrekenen.');
        }

        $transaction['status']    = 'settled';
        $transaction['settledAt'] = $this->now();

        $this->logger->info('Pipelinq: POS transaction settled', ['id' => $id, 'userId' => $userId]);

        return $this->saveTransaction($id, $transaction);
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
        if ($this->isManager($userId) === false) {
            throw new OCSForbiddenException('Alleen een beheerder mag een transactie terugboeken.');
        }

        if (trim($reason) === '') {
            throw new OCSBadRequestException('Vul een reden in voor de terugboeking.');
        }

        $transaction = $this->fetchTransaction($id);
        $status      = (string) ($transaction['status'] ?? '');

        if (in_array($status, ['confirmed', 'settled'], true) === false) {
            throw new OCSBadRequestException('Alleen bevestigde of afgerekende transacties kunnen worden teruggeboekt.');
        }

        $transaction['status']       = 'refunded';
        $transaction['refundedAt']   = $this->now();
        $transaction['refundReason'] = $reason;

        $this->logger->info('Pipelinq: POS transaction refunded', ['id' => $id, 'userId' => $userId]);

        return $this->saveTransaction($id, $transaction);
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
        $transaction = $this->fetchTransaction($id);

        if ((string) ($transaction['status'] ?? '') !== 'draft') {
            throw new OCSBadRequestException('Alleen concept-transacties kunnen worden geparkeerd.');
        }

        $transaction['status']   = 'parked';
        $transaction['parkedAt'] = $this->now();

        return $this->saveTransaction($id, $transaction);
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
        $transaction = $this->fetchTransaction($id);

        if ((string) ($transaction['status'] ?? '') !== 'parked') {
            throw new OCSBadRequestException('Alleen geparkeerde transacties kunnen worden hervat.');
        }

        $transaction['status']   = 'draft';
        $transaction['parkedAt'] = null;

        return $this->saveTransaction($id, $transaction);
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
        $payload = [
            'specversion'     => '1.0',
            'type'            => self::EVENT_CONFIRMED,
            'source'          => self::EVENT_SOURCE,
            'id'              => $eventId,
            'time'            => (string) ($transaction['confirmedAt'] ?? $this->now()),
            'datacontenttype' => 'application/json',
            'data'            => [
                'transactionId' => (string) ($transaction['id'] ?? $transaction['uuid'] ?? ''),
                'reference'     => (string) ($transaction['reference'] ?? ''),
                'cashier'       => (string) ($transaction['cashier'] ?? ''),
                'total'         => (float) ($transaction['total'] ?? 0),
                'totalTax'      => (float) ($transaction['totalTax'] ?? 0),
                'taxBreakdown'  => ($transaction['taxBreakdown'] ?? []),
                'confirmedAt'   => (string) ($transaction['confirmedAt'] ?? ''),
            ],
        ];

        try {
            $webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
            $event          = new \OCP\EventDispatcher\Event();
            $webhookService->dispatchEvent($event, self::EVENT_CONFIRMED, $payload);
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
        [$register, $schema] = $this->config('posTransaction_schema');

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException('Transactie niet gevonden.');
        }

        return $this->toArray($object);
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
        [$register, $schema] = $this->config('posTransactionLine_schema');

        try {
            $results = $this->getObjectService()->findAll(
                [
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
            $lines[] = $this->toArray($result);
        }

        return $lines;
    }//end fetchLines()

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
        [$register, $schema] = $this->config('posTransaction_schema');

        // Never trust client-derived id/uuid; always write to the resolved id.
        unset($transaction['@self']);

        $saved = $this->getObjectService()->saveObject($transaction, [], $register, $schema, $id);

        return $this->toArray($saved);
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
     * @throws \RuntimeException If OpenRegister is not available.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new \RuntimeException('OpenRegister service is not available.');
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
        return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
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
