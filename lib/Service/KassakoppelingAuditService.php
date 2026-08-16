<?php

/**
 * Pipelinq KassakoppelingAuditService.
 *
 * Business logic for the POS Kassakoppeling-compliant audit log: creates
 * cryptographically signed append-only entries, lists entries with filters,
 * verifies an individual entry, and assembles a date-range export pack for
 * the Belastingdienst (Dutch tax authority) via BelastingdienstExportService.
 *
 * Every signature + chain hash is computed server-side via
 * KassakoppelingSignatureService; client-supplied signature / previousHash /
 * currentHash values are explicitly ignored. The previousHash for a new
 * entry is always resolved from the latest existing entry on the same
 * registerNumber via getLastEntry, so two operators submitting in parallel
 * for the same register cannot bypass the chain. Entries are written through
 * OR's ObjectService::saveObject (the real API name — see
 * reference_or-objectservice-api).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for the POS Kassakoppeling audit log.
 *
 * Append-only lifecycle:
 *
 *   1. createEntry  — validate input, fetch the prior currentHash for the
 *      register, generate the HMAC + chain hash, save through OR. Returns
 *      the persisted entry including signature / previousHash / currentHash.
 *   2. listEntries  — return entries matching the supplied filters
 *      (registerNumber / operatorId / action / from / to).
 *   3. getEntry     — fetch a single entry by id.
 *   4. verifyEntry  — recompute the HMAC, update `verified` true/false,
 *      persist + return the new verification status.
 *   5. exportForBelastingdienst — assemble a date-range pack via
 *      BelastingdienstExportService and stamp `exportedAt` on every entry
 *      so the export trail is itself auditable.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Coordinator wires OR (via
 *   the container), the app config, the signature primitive and the export
 *   builder. Each is exercised by an independent unit test.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Audit coordinator with
 *   several independent guard-heavy operations; splitting adds indirection.
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
 */
class KassakoppelingAuditService {
	/**
	 * Schemas in scope for the audit log.
	 *
	 * @var string
	 */
	public const SCHEMA_KEY = 'kassakoppelingAuditLog_schema';

	/**
	 * Allowed actions on a kassakoppeling audit entry.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_ACTIONS = ['sale', 'void', 'refund', 'no-sale'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param KassakoppelingSignatureService $signature The signature primitive.
	 * @param BelastingdienstExportService $exporter The export builder.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private KassakoppelingSignatureService $signature,
		private BelastingdienstExportService $exporter,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Create a new audit entry.
	 *
	 * Validates the required fields, resolves `previousHash` from the prior
	 * entry on the same register (or `'0'` for the genesis entry), generates
	 * the HMAC + chain hash and persists the entry through OR. Client-side
	 * `signature` / `previousHash` / `currentHash` / `verified` / `exportedAt`
	 * are explicitly stripped before signing so the operator cannot pre-sign
	 * the entry under a tampered field set.
	 *
	 * @param array<string, mixed> $data The submitted entry fields.
	 *
	 * @return array<string, mixed> The persisted entry with signatures.
	 *
	 * @throws OCSBadRequestException When validation fails.
	 *
	 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
	 */
	public function createEntry(array $data): array {
		$entry = $this->sanitiseInput(data: $data);
		$this->validateInput(entry: $entry);

		$registerNumber = (string)$entry['registerNumber'];
		$previousHash = $this->getLastCurrentHash(registerNumber: $registerNumber);
		$entry['previousHash'] = $previousHash;
		$entry['signature'] = $this->signature->generateSignature(entryData: $entry);
		$entry['currentHash'] = $this->signature->generateHash(entryData: $entry, previousHash: $previousHash);
		$entry['verified'] = null;
		$entry['exportedAt'] = null;

		$persisted = $this->saveEntry(id: '', object: $entry);

		$this->logger->info(
			'Pipelinq: kassakoppeling audit entry created',
			[
				'registerNumber' => $registerNumber,
				'action' => (string)$entry['action'],
				'operatorId' => (string)$entry['operatorId'],
				'previousHash' => $previousHash,
			]
		);

		return $persisted;
	}//end createEntry()

	/**
	 * List audit entries with optional filters.
	 *
	 * Supported filter keys: registerNumber, operatorId, action, from
	 * (timestamp >=), to (timestamp <=). Unsupported keys are silently
	 * ignored — the API contract is whitelisted, not generic.
	 *
	 * @param array<string, mixed> $filters Optional filters.
	 *
	 * @return array<int, array<string, mixed>> The matching entries ordered
	 *                                          by timestamp ascending.
	 *
	 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
	 */
	public function listEntries(array $filters = []): array {
		[$register, $schema] = $this->config();

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: kassakoppeling audit listing failed; returning empty',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$rows = [];
		foreach (($results ?? []) as $result) {
			$rows[] = $this->toArray(object: $result);
		}

		$filtered = $this->applyFilters(entries: $rows, filters: $filters);

		usort(
			$filtered,
			function (array $left, array $right): int {
				return strcmp((string)($left['timestamp'] ?? ''), (string)($right['timestamp'] ?? ''));
			}
		);

		return $filtered;
	}//end listEntries()

	/**
	 * Fetch a single audit entry by id.
	 *
	 * @param string $id The entry uuid.
	 *
	 * @return array<string, mixed> The entry.
	 *
	 * @throws OCSNotFoundException When the entry is not found.
	 *
	 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
	 */
	public function getEntry(string $id): array {
		[$register, $schema] = $this->config();

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Audit entry niet gevonden.');
		}

		return $this->toArray(object: $object);
	}//end getEntry()

	/**
	 * Verify an entry's signature and persist the resulting flag.
	 *
	 * Recomputes the HMAC using the stored field values and `previousHash`;
	 * sets `verified` true/false; persists; returns the structured result so
	 * the caller can render the badge without a follow-up GET. The chain
	 * link is also checked (previousHash + currentHash) so a tampered field
	 * outside the signed-field set still fails verification.
	 *
	 * @param string $id The entry uuid.
	 *
	 * @return array{verified: bool, signatureValid: bool, hashValid: bool, entry: array<string, mixed>}
	 *
	 * @throws OCSNotFoundException When the entry is not found.
	 *
	 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
	 */
	public function verifyEntry(string $id): array {
		$entry = $this->getEntry(id: $id);
		$signatureValid = $this->signature->verifySignature(
			entryData: $entry,
			signature: (string)($entry['signature'] ?? '')
		);

		$previousHash = (string)($entry['previousHash'] ?? '');
		$recomputedCurrent = $this->signature->generateHash(entryData: $entry, previousHash: $previousHash);
		$storedCurrent = (string)($entry['currentHash'] ?? '');
		$hashValid = $storedCurrent !== '' && hash_equals($recomputedCurrent, $storedCurrent);

		$verified = ($signatureValid === true && $hashValid === true);
		$entry['verified'] = $verified;
		$entryId = (string)($entry['id'] ?? $entry['uuid'] ?? $id);
		$persisted = $this->saveEntry(id: $entryId, object: $entry);

		$this->logger->info(
			'Pipelinq: kassakoppeling audit entry verified',
			[
				'id' => $entryId,
				'verified' => $verified,
				'signatureValid' => $signatureValid,
				'hashValid' => $hashValid,
			]
		);

		return [
			'verified' => $verified,
			'signatureValid' => $signatureValid,
			'hashValid' => $hashValid,
			'entry' => $persisted,
		];
	}//end verifyEntry()

	/**
	 * Export a date-range slice of the audit log for the Belastingdienst.
	 *
	 * Loads every entry whose `timestamp` falls within [fromDate, toDate]
	 * (inclusive, ISO 8601), assembles the export pack via
	 * {@see BelastingdienstExportService} in XML or JSON, stamps `exportedAt`
	 * on every included entry (so the export trail is itself auditable) and
	 * returns both the rendered file body and the suggested filename. The
	 * full per-register chain integrity is computed and folded into the
	 * export metadata.
	 *
	 * @param string $fromDate ISO date YYYY-MM-DD or full ISO 8601.
	 * @param string $toDate ISO date YYYY-MM-DD or full ISO 8601.
	 * @param string $format `xml` or `json` (default `xml`).
	 *
	 * @return array{body: string, contentType: string, filename: string, entryCount: int}
	 *
	 * @throws OCSBadRequestException When the date range is malformed.
	 *
	 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
	 */
	public function exportForBelastingdienst(string $fromDate, string $toDate, string $format = 'xml'): array {
		$from = $this->parseDate(value: $fromDate, lower: true);
		$to = $this->parseDate(value: $toDate, lower: false);
		if ($from === null || $to === null || $from > $to) {
			throw new OCSBadRequestException('Ongeldige datum range voor de Belastingdienst export.');
		}

		$entries = $this->listEntries(
			filters: [
				'from' => $from->format(DateTimeInterface::ATOM),
				'to' => $to->format(DateTimeInterface::ATOM),
			]
		);

		$manifest = $this->exporter->buildManifest(
			entries: $entries,
			fromDate: $from->format('Y-m-d'),
			toDate: $to->format('Y-m-d')
		);

		$normalizedFormat = strtolower($format);
		$body = $this->exporter->exportAsXml(entries: $entries, manifest: $manifest);
		$contentType = 'application/xml';
		$extension = 'xml';
		if ($normalizedFormat === 'json') {
			$body = $this->exporter->exportAsJson(entries: $entries, manifest: $manifest);
			$contentType = 'application/json';
			$extension = 'json';
		}

		$stamp = $this->now();
		foreach ($entries as $entry) {
			if (($entry['exportedAt'] ?? null) !== null) {
				continue;
			}

			$entry['exportedAt'] = $stamp;
			$entryId = (string)($entry['id'] ?? $entry['uuid'] ?? '');
			if ($entryId === '') {
				continue;
			}

			try {
				$this->saveEntry(id: $entryId, object: $entry);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Pipelinq: kassakoppeling exportedAt stamp failed (export still served)',
					['id' => $entryId, 'exception' => $e->getMessage()]
				);
			}
		}

		$filename = sprintf(
			'kassakoppeling-export-%s-to-%s.%s',
			$from->format('Y-m-d'),
			$to->format('Y-m-d'),
			$extension
		);

		return [
			'body' => $body,
			'contentType' => $contentType,
			'filename' => $filename,
			'entryCount' => count($entries),
		];
	}//end exportForBelastingdienst()

	/**
	 * Fetch the latest entry for a register (used to chain a new entry).
	 *
	 * Returns the entry array, or null when no prior entry exists (genesis
	 * case — the caller uses {@see KassakoppelingSignatureService::GENESIS_HASH}).
	 *
	 * @param string $registerNumber The register identifier.
	 *
	 * @return array<string, mixed>|null The latest entry, or null.
	 *
	 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.2
	 */
	public function getLastEntry(string $registerNumber): ?array {
		if ($registerNumber === '') {
			return null;
		}

		$entries = $this->listEntries(filters: ['registerNumber' => $registerNumber]);
		if ($entries === []) {
			return null;
		}

		return $entries[count($entries) - 1];
	}//end getLastEntry()

	/**
	 * Return the previousHash to use for the next entry on a register.
	 *
	 * Internal helper: returns the prior `currentHash` or
	 * {@see KassakoppelingSignatureService::GENESIS_HASH} when the register
	 * has no entries yet.
	 *
	 * @param string $registerNumber The register identifier.
	 *
	 * @return string The hash to set as `previousHash` on the next entry.
	 */
	private function getLastCurrentHash(string $registerNumber): string {
		$last = $this->getLastEntry(registerNumber: $registerNumber);
		if ($last === null) {
			return KassakoppelingSignatureService::GENESIS_HASH;
		}

		$hash = (string)($last['currentHash'] ?? '');
		if ($hash === '') {
			return KassakoppelingSignatureService::GENESIS_HASH;
		}

		return $hash;
	}//end getLastCurrentHash()

	/**
	 * Strip client-supplied chain / verification fields from a submission.
	 *
	 * The server is the single authority for signature, previousHash,
	 * currentHash, verified and exportedAt — accepting them from the client
	 * would let an operator pre-sign a tampered entry. We always recompute.
	 *
	 * @param array<string, mixed> $data The submitted data.
	 *
	 * @return array<string, mixed> The sanitised entry.
	 */
	private function sanitiseInput(array $data): array {
		$allowed = [
			'operatorId',
			'registerNumber',
			'action',
			'amount',
			'itemCount',
			'taxAmount',
			'timestamp',
			'transactionUuid',
			'description',
		];

		$entry = [];
		foreach ($allowed as $key) {
			if (array_key_exists($key, $data) === true) {
				$entry[$key] = $data[$key];
			}
		}

		if (isset($entry['timestamp']) === false || $entry['timestamp'] === '') {
			$entry['timestamp'] = $this->now();
		}

		if (isset($entry['amount']) === true) {
			$entry['amount'] = (int)$entry['amount'];
		}

		if (isset($entry['itemCount']) === true) {
			$entry['itemCount'] = (int)$entry['itemCount'];
		}

		if (isset($entry['taxAmount']) === true) {
			$entry['taxAmount'] = (int)$entry['taxAmount'];
		}

		return $entry;
	}//end sanitiseInput()

	/**
	 * Validate a sanitised entry before signing.
	 *
	 * @param array<string, mixed> $entry The sanitised entry.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException When validation fails.
	 */
	private function validateInput(array $entry): void {
		foreach (['operatorId', 'registerNumber', 'action'] as $field) {
			$value = (string)($entry[$field] ?? '');
			if ($value === '') {
				throw new OCSBadRequestException('Veld "' . $field . '" is verplicht.');
			}
		}

		if (in_array((string)$entry['action'], self::ALLOWED_ACTIONS, true) === false) {
			throw new OCSBadRequestException('Onbekende action; verwacht een van: ' . implode(', ', self::ALLOWED_ACTIONS) . '.');
		}

		if (array_key_exists('amount', $entry) === false) {
			throw new OCSBadRequestException('Veld "amount" is verplicht (cents).');
		}
	}//end validateInput()

	/**
	 * Apply the whitelisted filter keys to an in-memory entry list.
	 *
	 * @param array<int, array<string, mixed>> $entries The candidate entries.
	 * @param array<string, mixed> $filters The whitelisted filters.
	 *
	 * @return array<int, array<string, mixed>> The filtered entries.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Flat sequence of independent whitelist filters; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Flat sequence of independent whitelist filters; extraction adds no clarity.
	 */
	private function applyFilters(array $entries, array $filters): array {
		$register = (string)($filters['registerNumber'] ?? '');
		$operator = (string)($filters['operatorId'] ?? '');
		$action = (string)($filters['action'] ?? '');
		$from = (string)($filters['from'] ?? '');
		$to = (string)($filters['to'] ?? '');

		$kept = [];
		foreach ($entries as $entry) {
			if ($register !== '' && (string)($entry['registerNumber'] ?? '') !== $register) {
				continue;
			}

			if ($operator !== '' && stripos((string)($entry['operatorId'] ?? ''), $operator) === false) {
				continue;
			}

			if ($action !== '' && (string)($entry['action'] ?? '') !== $action) {
				continue;
			}

			$timestamp = (string)($entry['timestamp'] ?? '');
			if ($from !== '' && $timestamp !== '' && strcmp($timestamp, $from) < 0) {
				continue;
			}

			if ($to !== '' && $timestamp !== '' && strcmp($timestamp, $to) > 0) {
				continue;
			}

			$kept[] = $entry;
		}//end foreach

		return $kept;
	}//end applyFilters()

	/**
	 * Parse an ISO 8601 date or date-time into a UTC DateTimeImmutable.
	 *
	 * `lower=true` snaps a bare YYYY-MM-DD to 00:00:00 (range start);
	 * `lower=false` snaps it to 23:59:59 (range end).
	 *
	 * @param string $value The candidate.
	 * @param bool $lower Whether to snap a bare date to the lower bound.
	 *
	 * @return DateTimeImmutable|null The parsed value, or null on failure.
	 */
	private function parseDate(string $value, bool $lower): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		try {
			$parsed = new DateTimeImmutable($value, new DateTimeZone('UTC'));
		} catch (\Throwable $exception) {
			return null;
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			if ($lower === true) {
				return $parsed->setTime(0, 0, 0);
			}

			return $parsed->setTime(23, 59, 59);
		}

		return $parsed;
	}//end parseDate()

	/**
	 * Persist an entry through OR's ObjectService.
	 *
	 * @param string $id The uuid (empty to create).
	 * @param array<string, mixed> $object The entry to persist.
	 *
	 * @return array<string, mixed> The persisted entry.
	 */
	private function saveEntry(string $id, array $object): array {
		[$register, $schema] = $this->config();

		unset($object['@self']);

		$saved = $this->getObjectService()->saveObject(
			object: $object,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}//end saveEntry()

	/**
	 * Resolve the configured register + audit schema ids.
	 *
	 * @return array{0: string, 1: string} The [register, schema] ids.
	 *
	 * @throws OCSNotFoundException When the register or schema is unset.
	 */
	private function config(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, self::SCHEMA_KEY, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Kassakoppeling register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}//end config()

	/**
	 * Resolve the OR ObjectService through the container.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException When OR is not available.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Normalise an OR entity / array / object into a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The object as an array.
	 */
	private function toArray(mixed $object): array {
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

		return (array)$object;
	}//end toArray()

	/**
	 * Current UTC ISO 8601 timestamp.
	 *
	 * @return string The timestamp.
	 */
	private function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
	}//end now()
}//end class
