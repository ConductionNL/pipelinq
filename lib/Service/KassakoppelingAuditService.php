<?php

/**
 * Pipelinq KassakoppelingAuditService.
 *
 * Append-only ledger logic for the Belastingdienst Kassakoppeling audit log:
 * server-authoritative entry creation (timestamp + signature + hash chain are
 * computed here, never trusted from the client), filtered listing, single-entry
 * lookup, signature/chain verification and the Belastingdienst export. Entries
 * are written once via saveObject and are never updated except for the verified
 * / exportedAt audit metadata; there is no update or delete path.
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
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for the Kassakoppeling append-only audit log.
 *
 * Entry creation derives previousHash from the last entry on the same register,
 * stamps a server-side timestamp, then signs and chains the entry before it is
 * persisted through OpenRegister's ObjectService::saveObject. Reads are scoped
 * to this app's own register + kassakoppelingAuditLog schema, so an id from
 * another app/register resolves to a 404 (no IDOR). The export filters by date
 * range and delegates the document rendering to BelastingdienstExportService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the collaborators an
 *  audit-ledger service legitimately needs (OR container, app config, signature
 *  + export services, logger); splitting them would add indirection without
 *  reducing real coupling.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class aggregates the
 *  whole append-only ledger concern (create + sign + chain, filtered list with
 *  in-memory date range, single read, verify, per-register last-hash lookup,
 *  export and the OR persistence helpers) as many small, single-purpose,
 *  individually-tested methods; the cohesion is intentional and splitting it
 *  would scatter one transactional concern across several classes.
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
 */
class KassakoppelingAuditService
{
    /**
     * Allowed audit action values (mirrors the schema enum).
     *
     * @var string[]
     */
    private const ACTIONS = [
        'sale',
        'void',
        'refund',
        'no-sale',
    ];

    /**
     * Constructor.
     *
     * @param ContainerInterface             $container        The DI container.
     * @param IAppConfig                     $appConfig        The app config.
     * @param KassakoppelingSignatureService $signatureService The signature service.
     * @param BelastingdienstExportService   $exportService    The export service.
     * @param LoggerInterface                $logger           The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private KassakoppelingSignatureService $signatureService,
        private BelastingdienstExportService $exportService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a signed, hash-chained audit entry (append-only).
     *
     * Validates the required fields, derives previousHash from the last entry on
     * the same register, stamps the server timestamp, computes the signature and
     * currentHash, then persists. The returned array is the stored entry
     * including its signature and chain fields.
     *
     * @param array<string, mixed> $data       The client-supplied entry fields.
     * @param string               $operatorId The authenticated operator UID.
     *
     * @return array<string, mixed> The stored entry.
     *
     * @throws OCSBadRequestException When required fields are missing / invalid.
     * @throws OCSNotFoundException   When the register/schema is not configured.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
     */
    public function createEntry(array $data, string $operatorId): array
    {
        $registerNumber = trim((string) ($data['registerNumber'] ?? ''));
        if ($registerNumber === '') {
            throw new OCSBadRequestException('registerNumber is required.');
        }

        $action = (string) ($data['action'] ?? '');
        if (in_array($action, self::ACTIONS, true) === false) {
            throw new OCSBadRequestException('action must be one of: '.implode(', ', self::ACTIONS).'.');
        }

        if (array_key_exists('amount', $data) === false || is_numeric($data['amount']) === false) {
            throw new OCSBadRequestException('amount (integer cents) is required.');
        }

        $previousHash = $this->lastHash(registerNumber: $registerNumber);

        $entry = [
            'operatorId'      => $operatorId,
            'registerNumber'  => $registerNumber,
            'action'          => $action,
            'amount'          => (int) $data['amount'],
            'itemCount'       => $this->intOrNull(value: ($data['itemCount'] ?? null)),
            'taxAmount'       => $this->intOrNull(value: ($data['taxAmount'] ?? null)),
            'timestamp'       => $this->now(),
            'transactionUuid' => $this->stringOrNull(value: ($data['transactionUuid'] ?? null)),
            'description'     => $this->stringOrNull(value: ($data['description'] ?? null)),
            'previousHash'    => $previousHash,
            'verified'        => null,
            'exportedAt'      => null,
        ];

        $entry['signature']   = $this->signatureService->generateSignature(entryData: $entry);
        $entry['currentHash'] = $this->signatureService->generateHash(
            entryData: $entry,
            previousHash: $previousHash
        );

        $saved = $this->save(entry: $entry, uuid: $this->uuid());

        $this->logger->info(
            'Pipelinq: Kassakoppeling audit entry created',
            [
                'register' => $registerNumber,
                'action'   => $action,
                'operator' => $operatorId,
            ]
        );

        return $saved;
    }//end createEntry()

    /**
     * List audit entries, optionally filtered.
     *
     * Supported filters: registerNumber, operatorId, action, fromDate, toDate.
     * Date filtering is applied in-memory against the ISO timestamp. Results are
     * sorted by timestamp ascending (chain order).
     *
     * @param array<string, mixed> $filters The filter map.
     *
     * @return array<int, array<string, mixed>> The matching entries.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
     */
    public function listEntries(array $filters=[]): array
    {
        [$register, $schema] = $this->config();

        $orFilters = [
            'register' => $register,
            'schema'   => $schema,
        ];
        foreach (['registerNumber', 'operatorId', 'action'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $orFilters[$key] = $value;
            }
        }

        try {
            $results = $this->getObjectService()->findAll(config: ['filters' => $orFilters]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: failed to list Kassakoppeling audit entries',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $entries = [];
        foreach (($results ?? []) as $result) {
            $entries[] = $this->toArray(object: $result);
        }

        $entries = $this->applyDateRange(
            entries: $entries,
            fromDate: (string) ($filters['fromDate'] ?? ''),
            toDate: (string) ($filters['toDate'] ?? '')
        );

        usort(
                $entries,
                static function (array $a, array $b): int {
                    return strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? ''));
                }
                );

        return $entries;
    }//end listEntries()

    /**
     * Fetch a single audit entry by id (scoped to this app, IDOR-safe).
     *
     * @param string $id The entry UUID.
     *
     * @return array<string, mixed> The entry.
     *
     * @throws OCSNotFoundException When the entry is not found in this app's schema.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
     */
    public function getEntry(string $id): array
    {
        [$register, $schema] = $this->config();

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException('Audit entry not found.');
        }

        return $this->toArray(object: $object);
    }//end getEntry()

    /**
     * Verify an entry's signature and chain link, persisting the verified flag.
     *
     * Recomputes the signature over the stored fields and confirms the
     * currentHash matches the SHA-256 of the entry + previousHash. The verified
     * audit-metadata flag is updated in place (the cryptographic fields
     * themselves are never rewritten). Returns the boolean outcome.
     *
     * @param string $id The entry UUID.
     *
     * @return bool Whether the entry is cryptographically intact.
     *
     * @throws OCSNotFoundException When the entry is not found.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
     */
    public function verifyEntry(string $id): bool
    {
        $entry = $this->getEntry(id: $id);

        $signatureOk = $this->signatureService->verifySignature(
            entryData: $entry,
            signature: (string) ($entry['signature'] ?? '')
        );

        $recomputedHash = $this->signatureService->generateHash(
            entryData: $entry,
            previousHash: (string) ($entry['previousHash'] ?? '0')
        );
        $hashOk         = hash_equals($recomputedHash, (string) ($entry['currentHash'] ?? ''));

        $verified = ($signatureOk === true && $hashOk === true);

        $entry['verified'] = $verified;
        $this->save(entry: $entry, uuid: (string) ($entry['id'] ?? $entry['uuid'] ?? $id));

        return $verified;
    }//end verifyEntry()

    /**
     * Fetch the most recent entry on a register, or null.
     *
     * @param string $registerNumber The register number.
     *
     * @return array<string, mixed>|null The last entry, or null when none exist.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
     */
    public function getLastEntry(string $registerNumber): ?array
    {
        $entries = $this->listEntries(filters: ['registerNumber' => $registerNumber]);
        if (count($entries) === 0) {
            return null;
        }

        return $entries[(count($entries) - 1)];
    }//end getLastEntry()

    /**
     * Build a Belastingdienst export for a date range in the requested format.
     *
     * @param string $fromDate The inclusive ISO/date lower bound (empty = open).
     * @param string $toDate   The inclusive ISO/date upper bound (empty = open).
     * @param string $format   The export format ('xml' or 'json').
     *
     * @return string The rendered export document.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
     */
    public function exportForBelastingdienst(string $fromDate, string $toDate, string $format='xml'): string
    {
        $entries = $this->listEntries(filters: ['fromDate' => $fromDate, 'toDate' => $toDate]);

        if ($format === 'json') {
            return $this->exportService->exportAsJson(entries: $entries);
        }

        return $this->exportService->exportAsXml(entries: $entries);
    }//end exportForBelastingdienst()

    /**
     * Derive the previousHash for a new entry on a register.
     *
     * @param string $registerNumber The register number.
     *
     * @return string The prior entry's currentHash, or '0' for the first entry.
     */
    private function lastHash(string $registerNumber): string
    {
        $last = $this->getLastEntry(registerNumber: $registerNumber);
        if ($last === null) {
            return '0';
        }

        $hash = (string) ($last['currentHash'] ?? '');
        if ($hash === '') {
            return '0';
        }

        return $hash;
    }//end lastHash()

    /**
     * Filter entries to an inclusive ISO date range.
     *
     * @param array<int, array<string, mixed>> $entries  The entries.
     * @param string                           $fromDate The lower bound (empty = open).
     * @param string                           $toDate   The upper bound (empty = open).
     *
     * @return array<int, array<string, mixed>> The filtered entries.
     */
    private function applyDateRange(array $entries, string $fromDate, string $toDate): array
    {
        if ($fromDate === '' && $toDate === '') {
            return $entries;
        }

        // Make the upper bound inclusive of a whole day when only a date is given.
        $upper = $toDate;
        if ($upper !== '' && strlen($upper) <= 10) {
            $upper = $upper.'T23:59:59Z';
        }

        return array_values(
            array_filter(
                $entries,
                static function (array $entry) use ($fromDate, $upper): bool {
                    $timestamp = (string) ($entry['timestamp'] ?? '');
                    if ($fromDate !== '' && strcmp($timestamp, $fromDate) < 0) {
                        return false;
                    }

                    if ($upper !== '' && strcmp($timestamp, $upper) > 0) {
                        return false;
                    }

                    return true;
                }
            )
        );
    }//end applyDateRange()

    /**
     * Persist an audit entry through the OR ObjectService.
     *
     * @param array<string, mixed> $entry The entry data.
     * @param string               $uuid  The object UUID.
     *
     * @return array<string, mixed> The stored entry.
     *
     * @throws OCSNotFoundException When the register/schema is not configured.
     */
    private function save(array $entry, string $uuid): array
    {
        [$register, $schema] = $this->config();

        unset($entry['@self']);

        $saved = $this->getObjectService()->saveObject(
            object: $entry,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $uuid
        );

        return $this->toArray(object: $saved);
    }//end save()

    /**
     * Resolve the register + kassakoppelingAuditLog schema config into their IDs.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws OCSNotFoundException When the register or schema is not configured.
     */
    private function config(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'kassakoppelingAuditLog_schema', '');

        if ($register === '' || $schema === '') {
            throw new OCSNotFoundException('Kassakoppeling register or schema is not configured.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws RuntimeException When OpenRegister is not available.
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
     * Coerce a value to an int or null.
     *
     * @param mixed $value The value.
     *
     * @return int|null The int, or null when not numeric.
     */
    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || is_numeric($value) === false) {
            return null;
        }

        return (int) $value;
    }//end intOrNull()

    /**
     * Coerce a value to a non-empty trimmed string or null.
     *
     * @param mixed $value The value.
     *
     * @return string|null The string, or null when empty.
     */
    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        return $string;
    }//end stringOrNull()

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
