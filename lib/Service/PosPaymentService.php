<?php

/**
 * Pipelinq PosPaymentService.
 *
 * Business logic for POS multi-tender payments: adding/removing tenders on a
 * transaction, server-authoritative tender-sum reconciliation against the
 * transaction total (with cash change), and per-tender GL CloudEvent emission on
 * settlement. All split amounts are validated server-side; client-computed
 * splits and glAccount values are never trusted (ADR-005).
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
 * @spec openspec/changes/pos-split-tender/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for POS multi-tender payment operations.
 *
 * Reconciliation is server-authoritative: validateTenderSum() derives the
 * tender sum from the persisted posTender rows and compares it to the
 * transaction's server-computed total, never to a client-supplied figure. A
 * cash tender that allows change may overpay (the excess is reported as change
 * due); any other over-tender, or any under-tender, leaves the transaction
 * unsettleable. addTender / removeTender enforce the per-transaction access rule
 * (PosAccessPolicy) so a cashier can only touch a transaction they own or are
 * grouped for — closing the IDOR — and refuse to mutate a settled transaction.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the collaborators a POS
 *  payment service legitimately needs (OR container, app config, access policy,
 *  optional webhook dispatch, logger); splitting them would add indirection
 *  without reducing real coupling.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   The public surface is the
 *  tender CRUD (add/remove/list), the reconciliation core (validateTenderSum /
 *  calculateChange), the tender-type CRUD + lookups and the GL event emitter /
 *  retry — all single-purpose and unit-tested individually.
 * @SuppressWarnings(PHPMD.TooManyMethods)         Same cohesion rationale: the
 *  tender CRUD + reconciliation + tender-type CRUD + GL emit/retry + the OR
 *  persistence helpers are one multi-tender payment concern, intentionally kept
 *  together; splitting them would scatter it across classes without reducing
 *  real complexity.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class aggregates the
 *  whole multi-tender payment concern (tender CRUD + reconciliation core +
 *  tender-type CRUD + GL emit/retry + OR persistence helpers) as many small,
 *  single-purpose methods; the cohesion is intentional.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)   The class aggregates the whole
 *  multi-tender payment concern as many small, single-purpose methods.
 *
 * @spec openspec/changes/pos-split-tender/tasks.md#2.1
 */
class PosPaymentService
{
    /**
     * CloudEvent type emitted per tender on settlement (GL posting).
     *
     * @var string
     */
    public const EVENT_TENDER_POSTED = 'nl.pipelinq.pos.tender.posted';

    /**
     * CloudEvents source identifier for this app's POS surface.
     *
     * @var string
     */
    private const EVENT_SOURCE = '/apps/pipelinq/pos';

    /**
     * Minimum accepted tender amount in EUR.
     *
     * @var float
     */
    private const MIN_TENDER_AMOUNT = 0.01;

    /**
     * Cents tolerance for floating-point tender-sum reconciliation.
     *
     * Two monetary figures are considered equal when they differ by less than
     * half a cent, so accumulated binary-float error never blocks a settlement
     * that balances to the cent.
     *
     * @var float
     */
    private const RECONCILE_EPSILON = 0.005;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     * @param IAppConfig         $appConfig The app config.
     * @param PosAccessPolicy    $policy    The shared POS access policy.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
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
     * Calculate the cash change due for an (over)paid amount.
     *
     * Returns the positive difference when the tendered cash exceeds the total;
     * zero when it is exactly equal or short. Pure function — used both to
     * surface the change hint and to decide whether a cash over-tender is a valid
     * settlement.
     *
     * @param float $cashTenderedAmount The cash amount tendered.
     * @param float $transactionTotal   The transaction total to cover.
     *
     * @return float The change due (>= 0), rounded to cents.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#2.4
     */
    public function calculateChange(float $cashTenderedAmount, float $transactionTotal): float
    {
        if ($cashTenderedAmount <= $transactionTotal) {
            return 0.0;
        }

        return $this->money(value: ($cashTenderedAmount - $transactionTotal));
    }//end calculateChange()

    /**
     * Reconcile the tenders on a transaction against its server-computed total.
     *
     * Server-authoritative: the tender sum is derived from the persisted
     * posTender rows, the total from the persisted posTransaction. The returned
     * array reports whether the transaction is settleable:
     *
     *   - variance        = total - tenderSum (positive = underpaid, negative = overpaid)
     *   - changeDue       = excess cash when a change-allowing cash tender is present
     *   - hasChangeTender = whether a tender of a change-allowing type was used
     *   - reconciled      = true when the sum exactly covers the total, OR the
     *                       overpayment is fully cash with a change-allowing tender
     *
     * An overpayment WITHOUT a change-allowing cash tender is NOT reconciled (you
     * cannot give change on a card), so settlement stays blocked.
     *
     * @param string $transactionId The transaction UUID.
     *
     * @return array{tenderSum: float, transactionTotal: float, variance: float,
     *               changeDue: float, hasChangeTender: bool, reconciled: bool} The reconciliation result.
     *
     * @throws OCSNotFoundException If the transaction does not exist in this app's register.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#2.3
     */
    public function validateTenderSum(string $transactionId): array
    {
        $transaction = $this->fetchTransaction(id: $transactionId);
        $total       = $this->money(value: (float) ($transaction['total'] ?? 0));

        $tenders         = $this->getTendersForTransaction(transactionId: $transactionId);
        $tenderSum       = 0.0;
        $changeCashSum   = 0.0;
        $hasChangeTender = false;

        foreach ($tenders as $tender) {
            $amount     = (float) ($tender['amount'] ?? 0);
            $tenderSum += $amount;

            if ($this->tenderAllowsChange(tender: $tender) === true) {
                $hasChangeTender = true;
                $changeCashSum  += $amount;
            }
        }

        $tenderSum = $this->money(value: $tenderSum);
        $variance  = $this->money(value: ($total - $tenderSum));

        $changeDue  = 0.0;
        $reconciled = false;

        if (abs($variance) < self::RECONCILE_EPSILON) {
            // Exact cover.
            $reconciled = true;
        } else if ($variance < 0.0 && $hasChangeTender === true) {
            // Overpaid: only valid when the overpayment can be returned as change,
            // i.e. the change-allowing cash tendered at least covers the excess
            // (you cannot give change out of a card or voucher tender).
            $overpayment = (-1 * $variance);
            if (($changeCashSum + self::RECONCILE_EPSILON) >= $overpayment) {
                $reconciled = true;
                $changeDue  = $this->money(value: $overpayment);
            }
        }//end if

        return [
            'tenderSum'        => $tenderSum,
            'transactionTotal' => $total,
            'variance'         => $variance,
            'changeDue'        => $changeDue,
            'hasChangeTender'  => $hasChangeTender,
            'reconciled'       => $reconciled,
        ];
    }//end validateTenderSum()

    /**
     * Add a tender to a transaction (server-authoritative, IDOR-safe).
     *
     * Validates: the caller may access the transaction (owner/group/admin), the
     * transaction is not already settled, the amount is >= 0.01, the referenced
     * tender type exists and is active, and — when the type requires a reference —
     * a non-empty reference was supplied. The glAccount is copied server-side from
     * the tender type (a client-supplied glAccount is ignored). Returns the
     * created tender.
     *
     * @param string               $transactionId The transaction UUID.
     * @param array<string, mixed> $tender        The raw tender input.
     * @param string               $userId        The acting user UID.
     *
     * @return array<string, mixed> The created tender.
     *
     * @throws OCSNotFoundException   If the transaction or tender type does not exist.
     * @throws OCSForbiddenException  If the caller may not access the transaction.
     * @throws OCSBadRequestException If the amount is too low, the type is inactive, or a required reference is missing.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#2.5
     */
    public function addTender(string $transactionId, array $tender, string $userId): array
    {
        $transaction = $this->fetchTransaction(id: $transactionId);
        $this->assertCanAccess(transaction: $transaction, userId: $userId);
        $this->assertNotSettled(transaction: $transaction, action: 'add');

        $amount = (float) ($tender['amount'] ?? 0);
        if ($amount < self::MIN_TENDER_AMOUNT) {
            throw new OCSBadRequestException('Het bedrag van een betaling moet minimaal € 0,01 zijn.');
        }

        $tenderTypeId = (string) ($tender['tenderType'] ?? '');
        $tenderType   = $this->fetchTenderType(id: $tenderTypeId);

        if ((bool) ($tenderType['isActive'] ?? true) === false) {
            throw new OCSBadRequestException('Dit betalingstype is niet beschikbaar voor nieuwe verkopen.');
        }

        $reference = trim((string) ($tender['reference'] ?? ''));
        if ((bool) ($tenderType['requiresReference'] ?? false) === true && $reference === '') {
            throw new OCSBadRequestException('Referentie is vereist voor dit betalingstype.');
        }

        // Server-authoritative tender: trust only the validated fields and copy
        // the GL account from the tender type. Never persist a client glAccount.
        $clean = [
            'transaction' => $transactionId,
            'tenderType'  => $tenderTypeId,
            'amount'      => $this->money(value: $amount),
            'reference'   => $reference,
            'glAccount'   => (string) ($tenderType['glAccount'] ?? ''),
            'notes'       => trim((string) ($tender['notes'] ?? '')),
            'sortOrder'   => (int) ($tender['sortOrder'] ?? 0),
        ];

        $created = $this->saveTender(id: $this->uuid(), tender: $clean);

        $this->logger->info(
            'Pipelinq: POS tender added',
            ['transaction' => $transactionId, 'tenderType' => $tenderTypeId, 'userId' => $userId]
        );

        return $created;
    }//end addTender()

    /**
     * Remove a tender from an unsettled transaction (server-authoritative, IDOR-safe).
     *
     * Validates that the caller may access the transaction, that the transaction
     * is not settled, and that the tender exists AND belongs to the transaction
     * (so a cashier cannot delete a tender off another sale by id). Deletes the
     * tender via the OR ObjectService.
     *
     * @param string $transactionId The transaction UUID.
     * @param string $tenderId      The tender UUID.
     * @param string $userId        The acting user UID.
     *
     * @return void
     *
     * @throws OCSNotFoundException   If the transaction or tender does not exist (or does not belong).
     * @throws OCSForbiddenException  If the caller may not access the transaction.
     * @throws OCSBadRequestException If the transaction is already settled.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#2.6
     */
    public function removeTender(string $transactionId, string $tenderId, string $userId): void
    {
        $transaction = $this->fetchTransaction(id: $transactionId);
        $this->assertCanAccess(transaction: $transaction, userId: $userId);
        $this->assertNotSettled(transaction: $transaction, action: 'remove');

        $tender = $this->fetchTender(id: $tenderId);
        if ((string) ($tender['transaction'] ?? '') !== $transactionId) {
            throw new OCSNotFoundException('Betaling niet gevonden op deze transactie.');
        }

        [$register, $schema] = $this->config(schemaKey: 'posTender_schema');
        $this->getObjectService()->deleteObject(register: $register, schema: $schema, uuid: $tenderId);

        $this->logger->info(
            'Pipelinq: POS tender removed',
            ['transaction' => $transactionId, 'tender' => $tenderId, 'userId' => $userId]
        );
    }//end removeTender()

    /**
     * List all tenders on a transaction, sorted by sortOrder ascending.
     *
     * @param string $transactionId The transaction UUID.
     *
     * @return array<int, array<string, mixed>> The tenders.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#2.7
     */
    public function getTendersForTransaction(string $transactionId): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTender_schema');

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
            $this->logger->warning('Pipelinq: failed to fetch POS tenders', ['exception' => $e->getMessage()]);
            return [];
        }

        $tenders = [];
        foreach (($results ?? []) as $result) {
            $tenders[] = $this->toArray(object: $result);
        }

        usort(
            $tenders,
            static fn (array $a, array $b): int => ((int) ($a['sortOrder'] ?? 0) <=> (int) ($b['sortOrder'] ?? 0))
        );

        return $tenders;
    }//end getTendersForTransaction()

    /**
     * List the active tender types, sorted by sortOrder ascending.
     *
     * @return array<int, array<string, mixed>> The active tender types.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#4.2
     */
    public function getActiveTenderTypes(): array
    {
        $types  = $this->fetchAllTenderTypes();
        $active = array_values(
            array_filter($types, static fn (array $t): bool => (bool) ($t['isActive'] ?? true) === true)
        );

        usort(
            $active,
            static fn (array $a, array $b): int => ((int) ($a['sortOrder'] ?? 0) <=> (int) ($b['sortOrder'] ?? 0))
        );

        return $active;
    }//end getActiveTenderTypes()

    /**
     * Look up a tender type by its stable code (CASH, CARD, VOUCHER, ...).
     *
     * @param string $code The tender type code.
     *
     * @return array<string, mixed> The matching tender type.
     *
     * @throws OCSNotFoundException If no tender type with that code exists.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#2.2
     */
    public function getTenderTypeByCode(string $code): array
    {
        foreach ($this->fetchAllTenderTypes() as $type) {
            if ((string) ($type['code'] ?? '') === $code) {
                return $type;
            }
        }

        throw new OCSNotFoundException('Betalingstype niet gevonden.');
    }//end getTenderTypeByCode()

    /**
     * Fetch a single tender type by UUID (public detail accessor).
     *
     * @param string $id The tender type UUID.
     *
     * @return array<string, mixed> The tender type.
     *
     * @throws OCSNotFoundException If the tender type does not exist.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#4.3
     */
    public function getTenderType(string $id): array
    {
        return $this->fetchTenderType(id: $id);
    }//end getTenderType()

    /**
     * Create a tender type (admin-gated at the controller).
     *
     * Validates the required name / code / glAccount and that the code is unique
     * across all existing tender types. Trusts only the known fields.
     *
     * @param array<string, mixed> $input The raw tender type input.
     *
     * @return array<string, mixed> The created tender type.
     *
     * @throws OCSBadRequestException If a required field is missing or the code is duplicate.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#4.4
     */
    public function createTenderType(array $input): array
    {
        $clean = $this->validateTenderTypeInput(input: $input, currentId: null);

        return $this->saveTenderType(id: $this->uuid(), type: $clean);
    }//end createTenderType()

    /**
     * Update a tender type (admin-gated at the controller).
     *
     * The code is immutable after creation: any client-supplied code is ignored
     * and the persisted code is retained. Re-validates GL account and uniqueness.
     *
     * @param string               $id    The tender type UUID.
     * @param array<string, mixed> $input The raw update input.
     *
     * @return array<string, mixed> The updated tender type.
     *
     * @throws OCSNotFoundException   If the tender type does not exist.
     * @throws OCSBadRequestException If a required field is invalid.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#4.5
     */
    public function updateTenderType(string $id, array $input): array
    {
        $existing = $this->fetchTenderType(id: $id);

        // Code is read-only after creation: pin it to the persisted value.
        $input['code'] = (string) ($existing['code'] ?? '');
        $clean         = $this->validateTenderTypeInput(input: $input, currentId: $id);

        return $this->saveTenderType(id: $id, type: $clean);
    }//end updateTenderType()

    /**
     * Delete a tender type, refusing when tenders still reference it.
     *
     * @param string $id The tender type UUID.
     *
     * @return void
     *
     * @throws OCSNotFoundException   If the tender type does not exist.
     * @throws OCSBadRequestException If active tenders reference the type.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#4.6
     */
    public function deleteTenderType(string $id): void
    {
        $this->fetchTenderType(id: $id);

        $references = $this->countTendersForType(tenderTypeId: $id);
        if ($references > 0) {
            throw new OCSBadRequestException(
                sprintf('Kan betalingstype niet verwijderen: %d betaling(en) verwijzen ernaar.', $references)
            );
        }

        [$register, $schema] = $this->config(schemaKey: 'posTenderType_schema');
        $this->getObjectService()->deleteObject(register: $register, schema: $schema, uuid: $id);
    }//end deleteTenderType()

    /**
     * Validate and normalise tender-type input, enforcing code uniqueness.
     *
     * @param array<string, mixed> $input     The raw input.
     * @param string|null          $currentId The id being updated (excluded from the uniqueness check), or null on create.
     *
     * @return array<string, mixed> The validated, server-shaped tender type.
     *
     * @throws OCSBadRequestException If name/code/glAccount are missing or the code is duplicate.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#4.4
     */
    private function validateTenderTypeInput(array $input, ?string $currentId): array
    {
        $name      = trim((string) ($input['name'] ?? ''));
        $code      = trim((string) ($input['code'] ?? ''));
        $glAccount = trim((string) ($input['glAccount'] ?? ''));

        if ($name === '') {
            throw new OCSBadRequestException('Naam is vereist.');
        }

        if ($code === '') {
            throw new OCSBadRequestException('Code is vereist.');
        }

        if ($glAccount === '') {
            throw new OCSBadRequestException('Grootboekrekening is vereist.');
        }

        foreach ($this->fetchAllTenderTypes() as $type) {
            $existingId = (string) ($type['id'] ?? $type['uuid'] ?? '');
            if ($currentId !== null && $existingId === $currentId) {
                continue;
            }

            if ((string) ($type['code'] ?? '') === $code) {
                throw new OCSBadRequestException(sprintf('Er bestaat al een betalingstype met code "%s".', $code));
            }
        }

        return [
            'name'              => $name,
            'code'              => $code,
            'description'       => trim((string) ($input['description'] ?? '')),
            'glAccount'         => $glAccount,
            'requiresReference' => (bool) ($input['requiresReference'] ?? false),
            'requiresPin'       => (bool) ($input['requiresPin'] ?? false),
            'allowsChange'      => (bool) ($input['allowsChange'] ?? false),
            'isActive'          => (bool) ($input['isActive'] ?? true),
            'sortOrder'         => (int) ($input['sortOrder'] ?? 0),
        ];
    }//end validateTenderTypeInput()

    /**
     * Persist a tender type via the OR ObjectService.
     *
     * @param string               $id   The tender type UUID.
     * @param array<string, mixed> $type The tender type data.
     *
     * @return array<string, mixed> The saved tender type as an array.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#4.4
     */
    private function saveTenderType(string $id, array $type): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTenderType_schema');

        unset($type['@self']);

        $saved = $this->getObjectService()->saveObject(
            object: $type,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $id
        );

        return $this->toArray(object: $saved);
    }//end saveTenderType()

    /**
     * Count the tenders that reference a given tender type (for delete-guarding).
     *
     * @param string $tenderTypeId The tender type UUID.
     *
     * @return int The number of tenders referencing the type.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#4.6
     */
    public function countTendersForType(string $tenderTypeId): int
    {
        [$register, $schema] = $this->config(schemaKey: 'posTender_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register'   => $register,
                        'schema'     => $schema,
                        'tenderType' => $tenderTypeId,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: failed to count tenders for type (fail closed)',
                ['exception' => $e->getMessage(), 'tenderType' => $tenderTypeId]
            );
            // Fail closed: a count we cannot verify blocks the delete.
            return 1;
        }

        return count(($results ?? []));
    }//end countTendersForType()

    /**
     * Emit one tender.posted CloudEvent per tender on a settled transaction.
     *
     * Fire-and-forget per tender: a missing/failed downstream subscriber (e.g.
     * shillinq's GL consumer unavailable) must never affect the already-settled
     * transaction. Each event carries a fresh CloudEvents id so the consumer can
     * dedupe retries idempotently. Returns the number of events emitted.
     *
     * @param array<string, mixed> $transaction The settled transaction.
     *
     * @return int The number of CloudEvents emitted.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#6.2
     */
    public function emitTenderPostedEvents(array $transaction): int
    {
        $transactionId = (string) ($transaction['id'] ?? $transaction['uuid'] ?? '');
        if ($transactionId === '') {
            return 0;
        }

        $tenders = $this->getTendersForTransaction(transactionId: $transactionId);
        $emitted = 0;

        foreach ($tenders as $tender) {
            if ($this->postTender(transaction: $transaction, tender: $tender) === true) {
                $emitted++;
            }
        }

        return $emitted;
    }//end emitTenderPostedEvents()

    /**
     * Emit one tender.posted CloudEvent for a single tender and record the outcome.
     *
     * On a successful dispatch the tender is flagged glPosted=true; either way the
     * glPostAttempts counter is incremented so the retry job can cap its work.
     * Fire-and-forget: a dispatch failure is logged and reported (false) but never
     * thrown — the settled transaction must not be affected.
     *
     * @param array<string, mixed> $transaction The settled transaction.
     * @param array<string, mixed> $tender      The tender to post.
     *
     * @return bool Whether the CloudEvent was dispatched.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#6.2
     */
    private function postTender(array $transaction, array $tender): bool
    {
        $tenderId = (string) ($tender['id'] ?? $tender['uuid'] ?? '');
        $payload  = $this->buildTenderPostedPayload(transaction: $transaction, tender: $tender);
        $posted   = false;

        try {
            $webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
            $event          = new Event();
            $webhookService->dispatchEvent(
                _event: $event,
                eventName: self::EVENT_TENDER_POSTED,
                payload: $payload
            );
            $posted = true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: POS tender.posted CloudEvent not dispatched (no consumer or OR unavailable)',
                ['exception' => $e->getMessage(), 'tender' => $tenderId]
            );
        }//end try

        // Record the posting outcome so the retry job can resume only the
        // tenders that did not post, and stop after the configured cap.
        if ($tenderId !== '') {
            $tender['glPosted']       = $posted;
            $tender['glPostAttempts'] = ((int) ($tender['glPostAttempts'] ?? 0) + 1);
            try {
                $this->saveTender(id: $tenderId, tender: $tender);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Pipelinq: failed to record tender GL posting state',
                    ['exception' => $e->getMessage(), 'tender' => $tenderId]
                );
            }
        }

        return $posted;
    }//end postTender()

    /**
     * Retry GL posting across all settled transactions with unposted tenders.
     *
     * Scans every persisted tender, collects the parent transaction ids of those
     * not yet posted (under the attempt cap), and re-runs retryUnpostedTenders
     * for each. Returns the total number of tenders re-posted this sweep. Used by
     * the TenderPostedRetryJob; safe to call with no live consumer (no-op).
     *
     * @param int $maxAttempts The per-tender attempt cap.
     *
     * @return int The total number of tenders re-posted.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#6.3
     */
    public function retryAllUnpostedTenders(int $maxAttempts): int
    {
        [$register, $schema] = $this->config(schemaKey: 'posTender_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                        'glPosted' => false,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: failed to scan unposted tenders (retry sweep)',
                ['exception' => $e->getMessage()]
            );
            return 0;
        }

        $transactionIds = [];
        foreach (($results ?? []) as $result) {
            $tender = $this->toArray(object: $result);
            $txnId  = (string) ($tender['transaction'] ?? '');
            if ($txnId !== '') {
                $transactionIds[$txnId] = true;
            }
        }

        $reposted = 0;
        foreach (array_keys($transactionIds) as $txnId) {
            try {
                $reposted += $this->retryUnpostedTenders(transactionId: $txnId, maxAttempts: $maxAttempts);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Pipelinq: tender retry failed for transaction',
                    ['exception' => $e->getMessage(), 'transaction' => $txnId]
                );
            }
        }

        return $reposted;
    }//end retryAllUnpostedTenders()

    /**
     * Re-emit GL CloudEvents for settled tenders that have not yet posted.
     *
     * Used by the retry background job: scans the tenders on a settled
     * transaction and re-posts any with glPosted=false that are still under the
     * attempt cap. Idempotent at the consumer side (each event carries a fresh
     * CloudEvents id; shillinq dedupes by event id). Returns the number re-posted.
     *
     * @param string $transactionId The settled transaction UUID.
     * @param int    $maxAttempts   The per-tender attempt cap.
     *
     * @return int The number of tenders re-posted this run.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#6.3
     */
    public function retryUnpostedTenders(string $transactionId, int $maxAttempts): int
    {
        $transaction = $this->fetchTransaction(id: $transactionId);
        if ((string) ($transaction['status'] ?? '') !== 'settled') {
            return 0;
        }

        $reposted = 0;
        foreach ($this->getTendersForTransaction(transactionId: $transactionId) as $tender) {
            if ((bool) ($tender['glPosted'] ?? false) === true) {
                continue;
            }

            if ((int) ($tender['glPostAttempts'] ?? 0) >= $maxAttempts) {
                continue;
            }

            if ($this->postTender(transaction: $transaction, tender: $tender) === true) {
                $reposted++;
            }
        }

        return $reposted;
    }//end retryUnpostedTenders()

    /**
     * Build the CloudEvents 1.0 envelope for a single posted tender.
     *
     * @param array<string, mixed> $transaction The settled transaction.
     * @param array<string, mixed> $tender      The tender being posted.
     *
     * @return array<string, mixed> The CloudEvent payload.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#6.1
     */
    private function buildTenderPostedPayload(array $transaction, array $tender): array
    {
        $tenderTypeCode = $this->resolveTenderTypeCode(tenderTypeId: (string) ($tender['tenderType'] ?? ''));

        return [
            'specversion'     => '1.0',
            'type'            => self::EVENT_TENDER_POSTED,
            'source'          => self::EVENT_SOURCE,
            'id'              => $this->uuid(),
            'time'            => $this->now(),
            'datacontenttype' => 'application/json',
            'data'            => [
                'transactionId'        => (string) ($transaction['id'] ?? $transaction['uuid'] ?? ''),
                'transactionReference' => (string) ($transaction['reference'] ?? ''),
                'tenderId'             => (string) ($tender['id'] ?? $tender['uuid'] ?? ''),
                'tenderType'           => $tenderTypeCode,
                'amount'               => (float) ($tender['amount'] ?? 0),
                'reference'            => (string) ($tender['reference'] ?? ''),
                'glAccount'            => (string) ($tender['glAccount'] ?? ''),
            ],
        ];
    }//end buildTenderPostedPayload()

    /**
     * Resolve a tender type UUID to its stable code, falling back to the UUID.
     *
     * @param string $tenderTypeId The tender type UUID.
     *
     * @return string The tender type code (or the UUID when unresolved).
     */
    private function resolveTenderTypeCode(string $tenderTypeId): string
    {
        if ($tenderTypeId === '') {
            return '';
        }

        try {
            $type = $this->fetchTenderType(id: $tenderTypeId);
            return (string) ($type['code'] ?? $tenderTypeId);
        } catch (\Throwable $e) {
            return $tenderTypeId;
        }
    }//end resolveTenderTypeCode()

    /**
     * Whether a tender's type allows change (resolved server-side).
     *
     * @param array<string, mixed> $tender The tender.
     *
     * @return bool Whether the tender's type allows change.
     */
    private function tenderAllowsChange(array $tender): bool
    {
        $tenderTypeId = (string) ($tender['tenderType'] ?? '');
        if ($tenderTypeId === '') {
            return false;
        }

        try {
            $type = $this->fetchTenderType(id: $tenderTypeId);
        } catch (\Throwable $e) {
            return false;
        }

        return (bool) ($type['allowsChange'] ?? false);
    }//end tenderAllowsChange()

    /**
     * Assert the caller may access the transaction (closes the IDOR).
     *
     * @param array<string, mixed> $transaction The transaction.
     * @param string               $userId      The acting user UID.
     *
     * @return void
     *
     * @throws OCSForbiddenException If the caller may not access the transaction.
     */
    private function assertCanAccess(array $transaction, string $userId): void
    {
        if ($this->policy->canAccessTransaction(object: $transaction, userId: $userId) === false) {
            throw new OCSForbiddenException(
                'U mag de betalingen van deze transactie niet wijzigen. Alleen de eigen '
                .'kassamedewerker, een lid van de POS-groep of een beheerder is gemachtigd.'
            );
        }
    }//end assertCanAccess()

    /**
     * Assert the transaction is not already settled.
     *
     * @param array<string, mixed> $transaction The transaction.
     * @param string               $action      'add' or 'remove' (selects the message).
     *
     * @return void
     *
     * @throws OCSBadRequestException If the transaction status is settled.
     */
    private function assertNotSettled(array $transaction, string $action): void
    {
        if ((string) ($transaction['status'] ?? '') !== 'settled') {
            return;
        }

        if ($action === 'remove') {
            throw new OCSBadRequestException('Betalingen kunnen niet van een afgeronde transactie worden verwijderd.');
        }

        throw new OCSBadRequestException('Er kunnen geen betalingen aan een afgeronde transactie worden toegevoegd.');
    }//end assertNotSettled()

    /**
     * Fetch a transaction from this app's register, as an array.
     *
     * @param string $id The transaction UUID.
     *
     * @return array<string, mixed> The transaction data.
     *
     * @throws OCSNotFoundException If not found in this app's posTransaction schema.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#2.3
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
     * Fetch a single tender by UUID.
     *
     * @param string $id The tender UUID.
     *
     * @return array<string, mixed> The tender data.
     *
     * @throws OCSNotFoundException If the tender is not found in this app's schema.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#2.6
     */
    private function fetchTender(string $id): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTender_schema');

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException('Betaling niet gevonden.');
        }

        return $this->toArray(object: $object);
    }//end fetchTender()

    /**
     * Fetch a single tender type by UUID.
     *
     * @param string $id The tender type UUID.
     *
     * @return array<string, mixed> The tender type data.
     *
     * @throws OCSNotFoundException If the tender type is not found in this app's schema.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#2.5
     */
    private function fetchTenderType(string $id): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTenderType_schema');

        if ($id === '') {
            throw new OCSNotFoundException('Betalingstype niet gevonden.');
        }

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException('Betalingstype niet gevonden.');
        }

        return $this->toArray(object: $object);
    }//end fetchTenderType()

    /**
     * Fetch all tender types in this app's register.
     *
     * @return array<int, array<string, mixed>> The tender types.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#4.2
     */
    private function fetchAllTenderTypes(): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTenderType_schema');

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
            $this->logger->warning('Pipelinq: failed to fetch tender types', ['exception' => $e->getMessage()]);
            return [];
        }

        $types = [];
        foreach (($results ?? []) as $result) {
            $types[] = $this->toArray(object: $result);
        }

        return $types;
    }//end fetchAllTenderTypes()

    /**
     * Persist a tender via the OR ObjectService.
     *
     * @param string               $id     The tender UUID.
     * @param array<string, mixed> $tender The tender data.
     *
     * @return array<string, mixed> The saved tender as an array.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#2.5
     */
    private function saveTender(string $id, array $tender): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTender_schema');

        unset($tender['@self']);

        $saved = $this->getObjectService()->saveObject(
            object: $tender,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $id
        );

        return $this->toArray(object: $saved);
    }//end saveTender()

    /**
     * Resolve the register + a schema config key into their stored IDs.
     *
     * @param string $schemaKey The app-config schema key (e.g. posTender_schema).
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws OCSNotFoundException If the register or schema is not configured.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#2.1
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
     * @spec openspec/changes/pos-split-tender/tasks.md#2.1
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
