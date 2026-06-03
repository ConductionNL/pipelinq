<?php

/**
 * Pipelinq KassakoppelingAuditService.
 *
 * Business logic for the Kassakoppeling POS audit log: creation of
 * cryptographically signed, hash-chain linked entries; listing; detail
 * fetch; signature verification; and Belastingdienst export.
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
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for Kassakoppeling POS audit log operations.
 *
 * Entries are append-only: once created they are never mutated or deleted.
 * Every new entry carries:
 *  - an HMAC-SHA256 signature over the canonical fields
 *  - a SHA-256 currentHash that chains this entry to the preceding one
 *
 * The signing key MUST be configured before entries can be created:
 *   occ config:app:set pipelinq kassakoppeling_secret --value="<strong-secret>"
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the three collaborators
 *   (ObjectService via container, SignatureService, ExportService) that a
 *   tamper-evident ledger service legitimately needs.
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
 */
class KassakoppelingAuditService
{
    /**
     * OpenRegister schema slug for the audit log.
     */
    private const SCHEMA = 'kassakoppelingAuditLog';

    /**
     * Allowed action values per design.md.
     */
    private const ALLOWED_ACTIONS = ['sale', 'void', 'refund', 'no-sale'];

    /**
     * Constructor.
     *
     * @param ContainerInterface             $container        The DI container (for lazy OR ObjectService).
     * @param IAppConfig                     $appConfig        The Nextcloud app config.
     * @param KassakoppelingSignatureService $signatureService The cryptographic signing service.
     * @param BelastingdienestExportService  $exportService    The export formatter.
     * @param LoggerInterface                $logger           The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private KassakoppelingSignatureService $signatureService,
        private BelastingdienestExportService $exportService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a new Kassakoppeling audit log entry.
     *
     * Validates required fields, fetches the previous entry's hash for this
     * register, generates the HMAC-SHA256 signature and SHA-256 currentHash,
     * then persists the entry via OpenRegister ObjectService.
     *
     * @param array<string,mixed> $data Raw entry data from the request.
     *
     * @return array<string,mixed> The persisted entry with all computed fields.
     *
     * @throws OCSBadRequestException When required fields are missing or invalid.
     * @throws RuntimeException       When OpenRegister is unavailable or the signing key is not set.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
     */
    public function createEntry(array $data): array
    {
        $this->validateRequiredFields(data: $data);

        // Fetch the hash of the previous entry for this register (or '0' if none).
        $registerNumber = (string) $data['registerNumber'];
        $lastEntry      = $this->getLastEntry(registerNumber: $registerNumber);
        if ($lastEntry !== null) {
            $previousHash = (string) ($lastEntry['currentHash'] ?? '0');
        } else {
            $previousHash = '0';
        }

        $data['previousHash'] = $previousHash;

        // Generate signature and hash.
        $data['signature']   = $this->signatureService->generateSignature($data);
        $data['currentHash'] = $this->signatureService->generateHash($data, $previousHash);

        // Immutability: remove any PUT/PATCH sentinels a client might supply.
        unset($data['@self'], $data['id'], $data['uuid']);

        // Persist via OpenRegister.
        [$register, $schema] = $this->resolveConfig();

        $saved = $this->getObjectService()->saveObject(
            $data,
            [],
            $register,
            $schema,
        );

        return $this->toArray(object: $saved);
    }//end createEntry()

    /**
     * List audit entries with optional filters.
     *
     * Supported filter keys: registerNumber, operatorId, action, fromDate, toDate.
     *
     * @param array<string,mixed> $filters Optional filter map.
     *
     * @return array<int,array<string,mixed>> The matching audit entries.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
     */
    public function listEntries(array $filters=[]): array
    {
        [$register, $schema] = $this->resolveConfig();

        $orFilters = [
            'register' => $register,
            'schema'   => $schema,
        ];

        // Map simple equality filters.
        foreach (['registerNumber', 'operatorId', 'action'] as $key) {
            if (isset($filters[$key]) === true && (string) $filters[$key] !== '') {
                $orFilters[$key] = $filters[$key];
            }
        }

        // Date-range filters (timestamp >= fromDate and <= toDate).
        if (isset($filters['fromDate']) === true && (string) $filters['fromDate'] !== '') {
            $orFilters['timestamp>='] = $filters['fromDate'];
        }

        if (isset($filters['toDate']) === true && (string) $filters['toDate'] !== '') {
            $orFilters['timestamp<='] = $filters['toDate'];
        }

        try {
            $results = $this->getObjectService()->findAll(
                ['filters' => $orFilters]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('KassakoppelingAuditService: failed to list entries', ['exception' => $e->getMessage()]);
            return [];
        }

        $entries = [];
        foreach (($results ?? []) as $result) {
            $entries[] = $this->toArray(object: $result);
        }

        return $entries;
    }//end listEntries()

    /**
     * Fetch a single audit entry by ID.
     *
     * @param string $id The entry UUID or numeric ID.
     *
     * @return array<string,mixed> The entry data.
     *
     * @throws OCSNotFoundException When the entry is not found.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
     */
    public function getEntry(string $id): array
    {
        [$register, $schema] = $this->resolveConfig();

        try {
            $object = $this->getObjectService()->find(
                $id,
                $register,
                $schema,
            );
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException('Audit entry not found.');
        }

        return $this->toArray(object: $object);
    }//end getEntry()

    /**
     * Verify the cryptographic signature of an audit entry and update its
     * `verified` flag in the store.
     *
     * @param string $id The entry UUID.
     *
     * @return bool True when the signature is valid, false when tampered.
     *
     * @throws OCSNotFoundException When the entry is not found.
     * @throws RuntimeException     When the signing key is not configured.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
     */
    public function verifyEntry(string $id): bool
    {
        $entry     = $this->getEntry(id: $id);
        $signature = (string) ($entry['signature'] ?? '');
        $verified  = false;

        try {
            $verified = $this->signatureService->verifySignature($entry, $signature);
        } catch (\Throwable $e) {
            $this->logger->error('KassakoppelingAuditService: signature verification failed', ['exception' => $e->getMessage()]);
        }

        // Persist the verified flag.
        [$register, $schema] = $this->resolveConfig();
        $entryId = (string) ($entry['id'] ?? $entry['uuid'] ?? $id);

        try {
            $this->getObjectService()->saveObject(
                array_merge($entry, ['verified' => $verified]),
                [],
                $register,
                $schema,
                $entryId,
            );
        } catch (\Throwable $e) {
            $this->logger->error('KassakoppelingAuditService: failed to persist verified flag', ['exception' => $e->getMessage()]);
        }

        return $verified;
    }//end verifyEntry()

    /**
     * Export audit entries for a date range as an XML or JSON string.
     *
     * @param string $fromDate Start of the date range (ISO 8601 / YYYY-MM-DD).
     * @param string $toDate   End of the date range (ISO 8601 / YYYY-MM-DD).
     * @param string $format   'xml' or 'json'. Defaults to 'xml'.
     *
     * @return string The formatted export string.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
     */
    public function exportForBelastingdienst(string $fromDate, string $toDate, string $format='xml'): string
    {
        $entries = $this->listEntries(filters: ['fromDate' => $fromDate, 'toDate' => $toDate]);

        if ($format === 'json') {
            return $this->exportService->exportAsJson($entries);
        }

        return $this->exportService->exportAsXml($entries);
    }//end exportForBelastingdienst()

    /**
     * Fetch the most recent audit entry for a given register.
     *
     * Returns null when no entry exists yet (first entry for the register).
     *
     * @param string $registerNumber The POS register identifier.
     *
     * @return array<string,mixed>|null The latest entry or null.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
     */
    public function getLastEntry(string $registerNumber): ?array
    {
        [$register, $schema] = $this->resolveConfig();

        try {
            $results = $this->getObjectService()->findAll(
                [
                    'filters' => [
                        'register'       => $register,
                        'schema'         => $schema,
                        'registerNumber' => $registerNumber,
                    ],
                    'order'   => ['timestamp' => 'DESC'],
                    'limit'   => 1,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('KassakoppelingAuditService: failed to fetch last entry', ['exception' => $e->getMessage()]);
            return null;
        }

        if (empty($results) === true) {
            return null;
        }

        $first = reset($results);
        if ($first === false) {
            return null;
        }

        return $this->toArray(object: $first);
    }//end getLastEntry()

    /**
     * Validate that all required fields are present and the action is valid.
     *
     * @param array<string,mixed> $data Entry data to validate.
     *
     * @return void
     *
     * @throws OCSBadRequestException When required fields are missing or invalid.
     */
    private function validateRequiredFields(array $data): void
    {
        $required = ['operatorId', 'registerNumber', 'action', 'amount', 'timestamp'];
        foreach ($required as $field) {
            if (isset($data[$field]) === false || (string) $data[$field] === '') {
                throw new OCSBadRequestException("Required field '{$field}' is missing or empty.");
            }
        }

        $action = (string) $data['action'];
        if (in_array($action, self::ALLOWED_ACTIONS, true) === false) {
            throw new OCSBadRequestException(
                "Invalid action '{$action}'. Allowed: ".implode(', ', self::ALLOWED_ACTIONS).'.'
            );
        }

        $amount       = $data['amount'];
        $isValidAmount = (is_int($amount) && $amount >= 0) || ctype_digit((string) $amount);
        if ($isValidAmount === false) {
            throw new OCSBadRequestException("Field 'amount' must be a non-negative integer (cents).");
        }
    }//end validateRequiredFields()

    /**
     * Resolve register and schema IDs from app config.
     *
     * @return array{0: string, 1: string} [register, schema]
     *
     * @throws OCSNotFoundException When the configuration is incomplete.
     */
    private function resolveConfig(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'kassakoppelingAuditLog_schema', '');

        if ($register === '' || $schema === '') {
            throw new OCSNotFoundException(
                'Kassakoppeling audit register or schema is not configured.'
            );
        }

        return [$register, $schema];
    }//end resolveConfig()

    /**
     * Lazily resolve the OpenRegister ObjectService from the DI container.
     *
     * @return object The ObjectService.
     *
     * @throws RuntimeException When OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister ObjectService is not available: '.$e->getMessage());
        }
    }//end getObjectService()

    /**
     * Normalise an OpenRegister entity/array to a plain PHP array.
     *
     * @param mixed $object The raw object from the OR ObjectService.
     *
     * @return array<string,mixed> Plain array representation.
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
