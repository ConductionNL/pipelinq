<?php

/**
 * Pipelinq PosCustomerLinkService.
 *
 * Links POS transactions to a Pipelinq contact (the natural person at the till)
 * and provides the supporting at-the-register surfaces: tenant-scoped contact
 * lookup, a server-computed purchase-history roll-up, marketing-consent capture
 * with privacy-respecting sync, and the on-account tender rule. Contacts live in
 * the SAME OpenRegister register as the transactions, so every read/write goes
 * through OpenRegister's ObjectService (find / findAll / saveObject) scoped to
 * this app's register — there is no external HTTP call and no service token, and
 * the register scope prevents cross-tenant customer leakage.
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
 * @link https://codeberg.org/Conduction/pipelinq
 *
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service that links POS transactions to Pipelinq contacts.
 *
 * Authorization: every operation is gated to a POS operator (PosAccessPolicy
 * ::isPosUser) and, for transaction mutations, to a caller that may access the
 * specific transaction (PosAccessPolicy::canAccessTransaction) — the same
 * cashier-owner / POS-group / admin rule the lifecycle guards enforce, which
 * closes the IDOR on the customer-attach endpoint. Reads are scoped to this
 * app's own register so a contact / transaction in another app or tenant
 * resolves to "not found" rather than leaking.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the collaborators a POS
 *  customer-link service legitimately needs (OR container, app config, access
 *  policy, logger).
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class aggregates the
 *  cohesive customer-link concern (lookup + decorate + attach + history roll-up
 *  + consent sync + the OR persistence helpers it shares with the rest of POS)
 *  as many small, single-purpose methods; splitting it would scatter one concern
 *  across several classes without reducing real complexity.
 *
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001
 */
class PosCustomerLinkService
{
    /**
     * App-config key toggling whether consent is mirrored onto the contact.
     *
     * @var string
     */
    public const SYNC_CONSENT_KEY = 'pos_sync_marketing_consent';

    /**
     * App-config key requiring a customer for on-account tender (default true).
     *
     * @var string
     */
    public const REQUIRE_CUSTOMER_ON_ACCOUNT_KEY = 'pos_require_customer_on_account';

    /**
     * App-config key for the default purchase-history depth.
     *
     * @var string
     */
    public const HISTORY_DEPTH_KEY = 'pos_customer_history_depth';

    /**
     * Default purchase-history depth when none is configured.
     *
     * @var int
     */
    private const DEFAULT_HISTORY_DEPTH = 10;

    /**
     * Hard ceiling on the number of contacts a single lookup may return.
     *
     * @var int
     */
    private const MAX_SEARCH_LIMIT = 50;

    /**
     * Minimum query length before a contact lookup runs.
     *
     * @var int
     */
    private const MIN_QUERY_LENGTH = 2;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (OR ObjectService).
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
     * Search Pipelinq contacts for the customer-lookup modal.
     *
     * Runs OpenRegister's full-text search over this app's contact schema and
     * decorates each hit with its privacy flag and a server-derived last
     * purchase date. The search is scoped to the configured register, so it can
     * never surface a contact from another tenant.
     *
     * @param string $query  The free-text query (name / email / phone).
     * @param int    $limit  The maximum number of contacts to return.
     * @param string $userId The acting user UID.
     *
     * @return array<int, array<string, mixed>> The decorated contact rows.
     *
     * @throws OCSForbiddenException  If the caller is not a POS operator.
     * @throws OCSBadRequestException If the query is too short.
     *
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-007
     */
    public function searchContacts(string $query, int $limit, string $userId): array
    {
        $this->requirePosUser(userId: $userId);

        $trimmed = trim($query);
        if (mb_strlen($trimmed) < self::MIN_QUERY_LENGTH) {
            throw new OCSBadRequestException('Voer minimaal twee tekens in om te zoeken.');
        }

        $bounded = max(1, min($limit, self::MAX_SEARCH_LIMIT));

        [$register, $schema] = $this->config(schemaKey: 'contact_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                    'search'  => $trimmed,
                    'limit'   => $bounded,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: contact lookup failed', ['exception' => $e->getMessage()]);
            return [];
        }

        $contacts = [];
        foreach (($results ?? []) as $result) {
            $contacts[] = $this->decorateContact(contact: $this->toArray(object: $result));
        }

        return $contacts;
    }//end searchContacts()

    /**
     * Attach (or clear) a customer on a transaction, server-authoritatively.
     *
     * The transaction is read scoped to this app's register (an id from another
     * app/tenant is a 404), the caller is checked against PosAccessPolicy
     * ::canAccessTransaction (closing the IDOR), the customer reference is
     * validated to be an existing contact in this register, and the on-account
     * rule is enforced. Only the customer + consent + tenderType fields are
     * touched; totals and lifecycle status are left to their owning services.
     *
     * @param string      $transactionId    The transaction UUID.
     * @param string|null $customerId       The contact UUID, or null to clear.
     * @param bool        $marketingConsent Whether marketing consent was given.
     * @param string|null $tenderType       Optional tender type for the sale.
     * @param string      $userId           The acting user UID.
     *
     * @return array<string, mixed> The updated transaction.
     *
     * @throws OCSForbiddenException  If the caller may not access the transaction.
     * @throws OCSNotFoundException   If the transaction or contact is not found.
     * @throws OCSBadRequestException If on-account is selected without a customer.
     *
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-002
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-005
     */
    public function attachCustomer(
        string $transactionId,
        ?string $customerId,
        bool $marketingConsent,
        ?string $tenderType,
        string $userId
    ): array {
        $this->requirePosUser(userId: $userId);

        $transaction = $this->fetchTransaction(id: $transactionId);
        if ($this->policy->canAccessTransaction(object: $transaction, userId: $userId) === false) {
            throw new OCSForbiddenException('U mag deze transactie niet wijzigen.');
        }

        $customer = $this->normalizeCustomerId(customerId: $customerId);
        $contact  = null;
        if ($customer !== null) {
            $contact = $this->fetchContact(id: $customer);
        }

        $tender = $this->normalizeTenderType(tenderType: $tenderType, current: ($transaction['tenderType'] ?? null));
        $this->assertOnAccountHasCustomer(tenderType: $tender, customerId: $customer);

        $consent = $this->resolveConsent(requested: $marketingConsent, customerId: $customer, contact: $contact);

        $transaction['customer']         = $customer;
        $transaction['marketingConsent'] = $consent;
        if ($tender !== null) {
            $transaction['tenderType'] = $tender;
        }

        $saved = $this->saveTransaction(id: $transactionId, transaction: $transaction);

        if ($consent === true && $contact !== null) {
            $this->syncConsentToContact(contactId: $customer, contact: $contact);
        }

        $this->logger->info(
            'Pipelinq: POS transaction customer linked',
            ['id' => $transactionId, 'customer' => $customer, 'consent' => $consent, 'userId' => $userId]
        );

        return $saved;
    }//end attachCustomer()

    /**
     * Resolve the effective marketing-consent flag for a transaction.
     *
     * Consent can only be true when a customer is linked, and is never recorded
     * against a do-not-contact contact (privacy-respecting capture).
     *
     * @param bool                      $requested  The requested consent value.
     * @param string|null               $customerId The linked customer, if any.
     * @param array<string, mixed>|null $contact    The fetched contact, if any.
     *
     * @return bool The effective consent value.
     *
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-004
     */
    private function resolveConsent(bool $requested, ?string $customerId, ?array $contact): bool
    {
        if ($requested === false || $customerId === null) {
            return false;
        }

        if ($contact !== null && $this->isDoNotContact(contact: $contact) === true) {
            return false;
        }

        return true;
    }//end resolveConsent()

    /**
     * Build a customer's purchase history for the register panel.
     *
     * Fetches the contact's transactions scoped to this app's register, sorts
     * newest-first, trims to the configured depth, and computes a per-row
     * summary (item count, total, tender type, date) plus a lifetime-spend
     * roll-up — all server-side, never trusting client figures.
     *
     * @param string   $customerId The contact UUID.
     * @param int|null $limit      Optional override of the history depth.
     * @param string   $userId     The acting user UID.
     *
     * @return array<string, mixed> The history payload (rows + summary).
     *
     * @throws OCSForbiddenException If the caller is not a POS operator.
     * @throws OCSNotFoundException  If the contact is not found.
     *
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-003
     */
    public function purchaseHistory(string $customerId, ?int $limit, string $userId): array
    {
        $this->requirePosUser(userId: $userId);

        $customer = $this->normalizeCustomerId(customerId: $customerId);
        if ($customer === null) {
            throw new OCSNotFoundException('Klant niet gevonden.');
        }

        // Confirm the contact exists in this register before exposing history.
        $this->fetchContact(id: $customer);

        $depth = $this->resolveHistoryDepth(override: $limit);
        $rows  = $this->buildHistory(transactions: $this->fetchCustomerTransactions(customerId: $customer), depth: $depth);

        $lifetimeSpend = 0.0;
        foreach ($rows as $row) {
            $lifetimeSpend += (float) $row['total'];
        }

        return [
            'customer'      => $customer,
            'count'         => count($rows),
            'lifetimeSpend' => round($lifetimeSpend, 2),
            'transactions'  => $rows,
        ];
    }//end purchaseHistory()

    /**
     * Reduce raw transactions to the server-authoritative history rows.
     *
     * Pure function: trusts only the persisted, server-computed `total` and the
     * tender / date / line-count fields. Drafts and parked carts are excluded —
     * the panel only shows fiscally meaningful purchases.
     *
     * @param array<int, array<string, mixed>> $transactions The customer's transactions.
     * @param int                              $depth        The number of rows to keep.
     *
     * @return array<int, array<string, mixed>> The trimmed, summarised history rows.
     *
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-003
     */
    public function buildHistory(array $transactions, int $depth): array
    {
        $rows = [];
        foreach ($transactions as $transaction) {
            $status = (string) ($transaction['status'] ?? '');
            if (in_array($status, ['confirmed', 'settled', 'refunded'], true) === false) {
                continue;
            }

            $rows[] = [
                'id'         => (string) ($transaction['id'] ?? ($transaction['uuid'] ?? '')),
                'reference'  => (string) ($transaction['reference'] ?? ''),
                'date'       => $this->historyDate(transaction: $transaction),
                'itemCount'  => $this->lineCount(transaction: $transaction),
                'total'      => round((float) ($transaction['total'] ?? 0), 2),
                'tenderType' => (string) ($transaction['tenderType'] ?? ''),
                'status'     => $status,
            ];
        }//end foreach

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

        return array_slice($rows, 0, $depth);
    }//end buildHistory()

    /**
     * Whether on-account tender is permitted without a linked customer.
     *
     * Enforces the configurable rule (default: require a customer) that an
     * "op rekening" sale must identify a debtor.
     *
     * @param string|null $tenderType The proposed tender type.
     * @param string|null $customerId The proposed customer reference.
     *
     * @return void
     *
     * @throws OCSBadRequestException When on-account is chosen without a customer.
     *
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-005
     */
    public function assertOnAccountHasCustomer(?string $tenderType, ?string $customerId): void
    {
        if ($tenderType !== 'onAccount') {
            return;
        }

        if ($this->requiresCustomerForOnAccount() === false) {
            return;
        }

        if ($customerId === null || $customerId === '') {
            throw new OCSBadRequestException("Klant is verplicht voor 'op rekening' transacties.");
        }
    }//end assertOnAccountHasCustomer()

    /**
     * Decorate a raw contact row for the lookup list.
     *
     * Adds the privacy flag and the contact's server-derived last purchase date
     * so the modal can render both without trusting client state.
     *
     * @param array<string, mixed> $contact The raw contact object.
     *
     * @return array<string, mixed> The decorated contact.
     *
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-007
     */
    private function decorateContact(array $contact): array
    {
        $id = (string) ($contact['id'] ?? ($contact['uuid'] ?? ''));

        return [
            'id'               => $id,
            'name'             => (string) ($contact['name'] ?? ''),
            'email'            => (string) ($contact['email'] ?? ''),
            'phone'            => (string) ($contact['phone'] ?? ''),
            'doNotContact'     => $this->isDoNotContact(contact: $contact),
            'marketingConsent' => (bool) ($contact['marketingConsent'] ?? false),
            'lastPurchaseDate' => $this->lastPurchaseDate(customerId: $id),
        ];
    }//end decorateContact()

    /**
     * Derive a contact's most recent purchase date, server-side.
     *
     * @param string $customerId The contact UUID.
     *
     * @return string|null The ISO date of the latest fiscally-final purchase.
     *
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001
     */
    private function lastPurchaseDate(?string $customerId): ?string
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        $rows = $this->buildHistory(transactions: $this->fetchCustomerTransactions(customerId: $customerId), depth: 1);
        if ($rows === []) {
            return null;
        }

        $date = (string) ($rows[0]['date'] ?? '');
        if ($date === '') {
            return null;
        }

        return $date;
    }//end lastPurchaseDate()

    /**
     * Mirror marketing consent onto the contact (best-effort, privacy-aware).
     *
     * Skipped when the admin has disabled consent sync or the contact is flagged
     * do-not-contact (the customer's standing preference is never overwritten).
     * A sync failure is logged but never fails the calling operation — POS is the
     * authoritative store for the consent captured on the transaction.
     *
     * @param string               $contactId The contact UUID.
     * @param array<string, mixed> $contact   The already-fetched contact object.
     *
     * @return void
     *
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-004
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-007
     */
    private function syncConsentToContact(string $contactId, array $contact): void
    {
        if ($this->consentSyncEnabled() === false) {
            return;
        }

        if ($this->isDoNotContact(contact: $contact) === true) {
            $this->logger->info(
                'Pipelinq: consent sync skipped (do-not-contact)',
                ['contact' => $contactId]
            );
            return;
        }

        if (((bool) ($contact['marketingConsent'] ?? false)) === true) {
            return;
        }

        [$register, $schema]         = $this->config(schemaKey: 'contact_schema');
        $contact['marketingConsent'] = true;

        try {
            $this->getObjectService()->saveObject(
                object: $contact,
                extend: [],
                register: $register,
                schema: $schema,
                uuid: $contactId
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: consent sync to contact failed',
                ['contact' => $contactId, 'exception' => $e->getMessage()]
            );
        }
    }//end syncConsentToContact()

    /**
     * Require the caller to be a POS operator.
     *
     * @param string $userId The acting user UID.
     *
     * @return void
     *
     * @throws OCSForbiddenException When the caller is not a POS operator.
     */
    private function requirePosUser(string $userId): void
    {
        if ($this->policy->isPosUser(userId: $userId) === false) {
            throw new OCSForbiddenException('POS-rechten zijn vereist.');
        }
    }//end requirePosUser()

    /**
     * Normalise a customer reference to a non-empty UUID string or null.
     *
     * @param string|null $customerId The raw customer reference.
     *
     * @return string|null The trimmed UUID, or null when absent.
     */
    private function normalizeCustomerId(?string $customerId): ?string
    {
        if ($customerId === null) {
            return null;
        }

        $trimmed = trim($customerId);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }//end normalizeCustomerId()

    /**
     * Normalise a tender type to a known value (or leave it unchanged).
     *
     * @param string|null $tenderType The proposed tender type.
     * @param mixed       $current    The transaction's current tender type.
     *
     * @return string|null The normalised tender type, or null when unset.
     */
    private function normalizeTenderType(?string $tenderType, mixed $current): ?string
    {
        if ($tenderType === null) {
            $existing = '';
            if (is_string($current) === true) {
                $existing = trim($current);
            }

            if ($existing === '') {
                return null;
            }

            return $existing;
        }

        $value = strtolower(trim($tenderType));
        $map   = ['cash' => 'cash', 'card' => 'card', 'onaccount' => 'onAccount'];

        return ($map[$value] ?? null);
    }//end normalizeTenderType()

    /**
     * Whether a contact carries the do-not-contact privacy flag.
     *
     * @param array<string, mixed> $contact The contact object.
     *
     * @return bool True when the contact must not be approached.
     */
    private function isDoNotContact(array $contact): bool
    {
        return ((bool) ($contact['doNotContact'] ?? false));
    }//end isDoNotContact()

    /**
     * Resolve the effective purchase-history depth.
     *
     * @param int|null $override An optional caller-supplied depth.
     *
     * @return int The bounded history depth.
     */
    private function resolveHistoryDepth(?int $override): int
    {
        if ($override !== null && $override > 0) {
            return min($override, self::MAX_SEARCH_LIMIT);
        }

        $configured = $this->appConfig->getValueInt(
            Application::APP_ID,
            self::HISTORY_DEPTH_KEY,
            self::DEFAULT_HISTORY_DEPTH
        );

        if ($configured <= 0) {
            return self::DEFAULT_HISTORY_DEPTH;
        }

        return min($configured, self::MAX_SEARCH_LIMIT);
    }//end resolveHistoryDepth()

    /**
     * Whether marketing-consent sync to the contact is enabled (default true).
     *
     * @return bool True when sync is enabled.
     */
    private function consentSyncEnabled(): bool
    {
        return ($this->appConfig->getValueString(
            Application::APP_ID,
            self::SYNC_CONSENT_KEY,
            'true'
        ) !== 'false');
    }//end consentSyncEnabled()

    /**
     * Whether a customer is required for on-account tender (default true).
     *
     * @return bool True when a customer is mandatory for on-account sales.
     */
    private function requiresCustomerForOnAccount(): bool
    {
        return ($this->appConfig->getValueString(
            Application::APP_ID,
            self::REQUIRE_CUSTOMER_ON_ACCOUNT_KEY,
            'true'
        ) !== 'false');
    }//end requiresCustomerForOnAccount()

    /**
     * Count the line items recorded on a transaction.
     *
     * @param array<string, mixed> $transaction The transaction object.
     *
     * @return int The line count.
     */
    private function lineCount(array $transaction): int
    {
        $lines = $transaction['lines'] ?? $transaction['invoiceBreakdown'] ?? null;
        if (is_array($lines) === true) {
            return count($lines);
        }

        return 0;
    }//end lineCount()

    /**
     * Best-effort ISO date for a transaction's place in the history timeline.
     *
     * Prefers the lifecycle timestamp matching the status, falling back to the
     * object's own created date.
     *
     * @param array<string, mixed> $transaction The transaction object.
     *
     * @return string The ISO date string (may be empty when unknown).
     */
    private function historyDate(array $transaction): string
    {
        foreach (['settledAt', 'confirmedAt', 'refundedAt'] as $field) {
            $value = $transaction[$field] ?? null;
            if (is_string($value) === true && $value !== '') {
                return $value;
            }
        }

        $self = $transaction['@self'] ?? [];
        if (is_array($self) === true && is_string($self['created'] ?? null) === true) {
            return (string) $self['created'];
        }

        return '';
    }//end historyDate()

    /**
     * Fetch a transaction scoped to this app's posTransaction schema.
     *
     * @param string $id The transaction UUID.
     *
     * @return array<string, mixed> The transaction data.
     *
     * @throws OCSNotFoundException If the transaction is not found in this register.
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
     * Fetch a contact scoped to this app's contact schema.
     *
     * @param string $id The contact UUID.
     *
     * @return array<string, mixed> The contact data.
     *
     * @throws OCSNotFoundException If the contact is not found in this register.
     */
    private function fetchContact(string $id): array
    {
        [$register, $schema] = $this->config(schemaKey: 'contact_schema');

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException('Klant niet gevonden.');
        }

        return $this->toArray(object: $object);
    }//end fetchContact()

    /**
     * Fetch every transaction linked to a customer in this register.
     *
     * @param string $customerId The contact UUID.
     *
     * @return array<int, array<string, mixed>> The customer's transactions.
     */
    private function fetchCustomerTransactions(string $customerId): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                        'customer' => $customerId,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: failed to fetch customer transactions',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $transactions = [];
        foreach (($results ?? []) as $result) {
            $transactions[] = $this->toArray(object: $result);
        }

        return $transactions;
    }//end fetchCustomerTransactions()

    /**
     * Persist a transaction via the OR ObjectService.
     *
     * @param string               $id          The transaction UUID.
     * @param array<string, mixed> $transaction The transaction data.
     *
     * @return array<string, mixed> The saved transaction as an array.
     */
    private function saveTransaction(string $id, array $transaction): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

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
     * @param string $schemaKey The app-config schema key.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws OCSNotFoundException If the register or schema is not configured.
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
}//end class
