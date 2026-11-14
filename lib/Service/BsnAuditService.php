<?php

/**
 * Pipelinq BsnAuditService.
 *
 * Writes immutable audit records to the BsnAuditRecord schema for every BSN-touching action.
 * Records are 5-year retained per RvIG guideline and pseudonymised at AVG art. 17 deletion.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\BsnAuditServiceInterface;
use OCA\Pipelinq\Service\BsnAudit\LookupAudit;
use OCA\Pipelinq\Util\EntityAccessorTrait;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Append-only audit-trail service for BSN bevragingen.
 *
 * Always writes; never reads / updates / deletes. The bsnAuditRecord schema has
 * `x-openregister-immutable: true` so a record cannot be modified via standard CRUD.
 * On Right-to-be-forgotten the system replaces the BSN-hash with a re-hashed value
 * via {@see pseudonymise()} — the rest of the audit chain (actor / doelbinding /
 * tijdstip) remains traceable.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-005
 */
class BsnAuditService implements BsnAuditServiceInterface {
	use EntityAccessorTrait;

	/**
	 * Default retention for audit records (5 years per RvIG guideline).
	 */
	private const RETENTION_YEARS = 5;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config for register/schema resolution.
	 * @param IRequest $request Request scope for IP / UA enrichment.
	 * @param LoggerInterface $logger Logger (raw BSN MUST never appear here).
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IRequest $request,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Record a BSN lookup attempt — always invoked, regardless of outcome.
	 *
	 * Stores ONLY the SHA-256 hash of the BSN. The raw BSN never reaches this method's
	 * persistent path; callers MUST pass the raw BSN so it can be hashed in-process and
	 * immediately discarded.
	 *
	 * @param string      $actor  Actor user UID.
	 * @param LookupAudit $lookup The subject, grounds, outcome and actor role of the lookup.
	 *
	 * @return string The UUID of the written audit record (empty string if writing fails).
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-005-01
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::mask is a pure stateless helper.
	 */
	public function recordLookup(string $actor, LookupAudit $lookup): string {
		$record = $this->auditRecord(actor: $actor, lookup: $lookup);

		// Mask the BSN in logs (REQ-BSN-009-01) — never the raw value.
		$logContext = [
			'action' => $lookup->result->action,
			'actor' => $actor,
			'bsn' => BsnValidationService::mask($lookup->subject->rawBsn),
		];

		try {
			$uuid = $this->saveRecord(record: $record);
			$this->logger->info('BSN audit record written', [...$logContext, 'outcome' => $lookup->result->outcome]);
			return $uuid;
		} catch (Throwable $e) {
			// Failing to write the audit record must NOT crash the calling flow — the
			// outcome is still surfaced through the controller. Log the error with the
			// masked BSN so postmortems are possible.
			$this->logger->error('BSN audit record write failed', [...$logContext, 'error' => $e->getMessage()]);
			return '';
		}
	}//end recordLookup()

	/**
	 * The bsnAuditRecord row for one lookup, without null fields.
	 *
	 * @param string      $actor  Actor user UID.
	 * @param LookupAudit $lookup The lookup.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function auditRecord(string $actor, LookupAudit $lookup): array {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$retainUntil = $now->modify('+' . self::RETENTION_YEARS . ' years');

		$record = [
			'action' => $lookup->result->action,
			'bsnHash' => $this->resolveSubjectHash(rawBsn: $lookup->subject->rawBsn, bsnHash: $lookup->subject->bsnHash),
			'actor' => $actor,
			'actorRole' => $lookup->actorRole,
			'moment' => $now->format(DATE_ATOM),
			'verzoekreden' => $lookup->reason->verzoekreden,
			'purposeBinding' => $lookup->reason->purposeBinding,
			'outcome' => $lookup->result->outcome,
			'responseCode' => $lookup->result->responseCode,
			'ipAddress' => self::anonymiseIp(ipAddress: $this->request->getRemoteAddress()),
			'userAgent' => 'Pipelinq/' . (AppIdentity::APP_ID) . ' (Nextcloud)',
			'haalcentraalCorrelationId' => $lookup->result->correlationId,
			'linkedRequest' => $lookup->reason->linkedRequest,
			'vogScreening' => $lookup->reason->vogScreening,
			'retainUntil' => $retainUntil->format(DATE_ATOM),
		];

		// Drop nulls — they pollute the audit record.
		return array_filter($record, static fn ($v) => $v !== null);
	}//end auditRecord()

	/**
	 * Save an audit row and return its UUID.
	 *
	 * @param array<string, mixed> $record The row.
	 *
	 * @return string The UUID, or empty when the store returned none.
	 *
	 * @throws Throwable When the register is not configured or the save fails.
	 */
	private function saveRecord(array $record): string {
		[$register, $schema] = $this->config();
		$saved = $this->getObjectService()->saveObject(
			object: $record,
			extend: [],
			register: $register,
			schema: $schema,
		);

		if (is_array($saved) === true) {
			return (string)($saved['@self']['id'] ?? $saved['id'] ?? '');
		}

		if (is_object($saved) === true) {
			// SaveObject() returns an ObjectEntity whose getUuid() is served by
			// Entity::__call — method_exists() is FALSE for it, so the handle is
			// read through the entity accessor (pipelinq#807).
			return $this->readEntityValue(entity: $saved, getter: 'getUuid');
		}

		return '';
	}//end saveRecord()

	/**
	 * The hash that identifies the data subject of an audit record.
	 *
	 * A caller that already HOLDS the subject's hash passes it through.
	 * Hashing an empty `$rawBsn` yields sha256("") — the same constant for
	 * every person — which is an audit record that names no data subject at
	 * all. That is what BrpController::revealAddress produced for every
	 * geheimhouding reveal, because the raw BSN is not reconstructible there
	 * and the persoon already carries its own hash.
	 *
	 * Extracted from {@see recordLookup()} to keep that method inside the
	 * length limit.
	 *
	 * @param string $rawBsn The raw BSN, possibly empty.
	 * @param string|null $bsnHash An already-computed hash, when the caller has one.
	 *
	 * @return string The subject hash to store.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::hash is a pure stateless helper — the same
	 * justification recordLookup() already carries for the call this was extracted from.
	 */
	private function resolveSubjectHash(
		#[\SensitiveParameter]
		string $rawBsn,
		?string $bsnHash,
	): string {
		if ($bsnHash !== null && $bsnHash !== '') {
			return $bsnHash;
		}

		return BsnValidationService::hash($rawBsn);
	}//end resolveSubjectHash()

	/**
	 * Pseudonymise audit records linked to a given BSN (AVG art. 17).
	 *
	 * Rewrites the bsnHash on every record to a salted SHA-256 derivative so the records
	 * can no longer be correlated to a known BSN through hash-table attacks — yet still
	 * remain linkable to one another via the new pseudonym for inspector audits.
	 *
	 * Records are NOT deleted; the immutable audit chain stays intact.
	 *
	 * @param string $rawBsn Raw BSN of the citizen exercising RTBF.
	 *
	 * @return int Number of records pseudonymised.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-008-02
	 */
	public function pseudonymise(
		#[\SensitiveParameter]
		string $rawBsn,
	): int {
		try {
			return $this->pseudonymiseRecords(rawBsn: $rawBsn);
		} catch (Throwable $e) {
			$this->logger->error(
				'BSN audit pseudonymise failed',
				['error' => $e->getMessage()]
			);
			return 0;
		}//end try
	}//end pseudonymise()

	/**
	 * Rewrite the bsnHash of every audit record linked to a BSN to its pseudonym.
	 *
	 * @param string $rawBsn Raw BSN of the citizen exercising RTBF.
	 *
	 * @return int Number of records pseudonymised; 0 when no pseudonym secret is configured.
	 *
	 * @throws RuntimeException When the audit register or schema is not configured.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-008-02
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::hash is a pure stateless helper.
	 */
	private function pseudonymiseRecords(
		#[\SensitiveParameter]
		string $rawBsn,
	): int {
		[$register, $schema] = $this->config();
		$oldHash = BsnValidationService::hash($rawBsn);
		// Pseudonym = HMAC(bsn, secret) — caller-side; we just bump it once.
		$secret = $this->pseudonymSecret();
		if ($secret === '') {
			return 0;
		}

		$newHash = hash_hmac('sha256', $rawBsn, $secret);

		$records = $this->recordsWithHash(hash: $oldHash, register: $register, schema: $schema);
		return $this->rewriteRecords(records: $records, newHash: $newHash, register: $register, schema: $schema);
	}//end pseudonymiseRecords()

	/**
	 * Read the configured pseudonym secret, warning when none is set.
	 *
	 * @return string The secret, or an empty string when none is configured.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-008-02
	 */
	private function pseudonymSecret(): string {
		$secret = $this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'brp.pseudonym_secret',
			''
		);
		if ($secret === '') {
			$this->logger->warning(
				'No pseudonym secret configured; RTBF pseudonymise skipped',
				['actor' => 'system']
			);
		}

		return $secret;
	}//end pseudonymSecret()

	/**
	 * Find the audit records that carry a given bsnHash.
	 *
	 * @param string $hash The bsnHash to match.
	 * @param string $register The register id.
	 * @param string $schema The audit record schema id.
	 *
	 * @return mixed The findAll result.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-008-02
	 */
	private function recordsWithHash(string $hash, string $register, string $schema) {
		return $this->getObjectService()->findAll(
			config: [
				'filters' => [
					'bsnHash' => $hash,
					'register' => $register,
					'schema' => $schema,
				],
			]
		);
	}//end recordsWithHash()

	/**
	 * Rewrite each record to the new hash and count the records saved.
	 *
	 * @param mixed $records The records found for the old hash.
	 * @param string $newHash The pseudonym hash.
	 * @param string $register The register id.
	 * @param string $schema The audit record schema id.
	 *
	 * @return int Number of records saved.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-008-02
	 */
	private function rewriteRecords($records, string $newHash, string $register, string $schema): int {
		$count = 0;
		foreach (($records ?? []) as $record) {
			if ($this->rewriteRecord(record: $record, newHash: $newHash, register: $register, schema: $schema) === true) {
				$count++;
			}
		}

		return $count;
	}//end rewriteRecords()

	/**
	 * Save one record under the new hash.
	 *
	 * @param mixed $record The record as returned by OpenRegister.
	 * @param string $newHash The pseudonym hash.
	 * @param string $register The register id.
	 * @param string $schema The audit record schema id.
	 *
	 * @return bool Whether the record was saved; false when it has no id.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-008-02
	 */
	private function rewriteRecord($record, string $newHash, string $register, string $schema): bool {
		$arr = $this->recordArray(record: $record);

		$uuid = (string)($arr['@self']['id'] ?? $arr['id'] ?? '');
		if ($uuid === '') {
			return false;
		}

		// Immutable schema: callers MUST go through the system pseudonym path.
		$arr['bsnHash'] = $newHash;
		$arr['action'] = 'brp-rtbf-pseudonymised';
		$arr['outcome'] = 'pseudonymised';
		$this->getObjectService()->saveObject(object: $arr, extend: [], register: $register, schema: $schema, uuid: $uuid);
		return true;
	}//end rewriteRecord()

	/**
	 * Coerce an OpenRegister record into an array.
	 *
	 * @param mixed $record The record.
	 *
	 * @return array<mixed> The record data; empty when it is neither an array nor serialisable.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-008-02
	 */
	private function recordArray($record): array {
		$arr = [];
		if (is_array($record) === true) {
			$arr = $record;
		} elseif (method_exists($record, 'jsonSerialize') === true) {
			$arr = (array)$record->jsonSerialize();
		}

		return $arr;
	}//end recordArray()

	/**
	 * Anonymise an IPv4 address by zeroing the last octet (ipv6: zero the last 80 bits).
	 *
	 * @param string $ipAddress Raw IP from request scope.
	 *
	 * @return string Anonymised IP suitable for audit storage.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-009-01
	 */
	public static function anonymiseIp(string $ipAddress): string {
		if ($ipAddress === '') {
			return '';
		}

		if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			$parts = explode('.', $ipAddress);
			$parts[3] = '0';
			return implode('.', $parts);
		}

		// IPv6: zero the last 5 groups (preserves /48 prefix).
		if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
			$bin = inet_pton($ipAddress);
			if ($bin === false) {
				return '';
			}

			$masked = substr($bin, 0, 6) . str_repeat("\0", 10);
			$out = inet_ntop($masked);
			if ($out === false) {
				return '';
			}

			return $out;
		}

		return '';
	}//end anonymiseIp()

	/**
	 * Resolve the [register, schema] pair for bsnAuditRecord.
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @throws RuntimeException If configuration is missing.
	 */
	private function config(): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'bsnAuditRecord_schema', '');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('bsnAuditRecord register/schema not configured.');
		}

		return [$register, $schema];
	}//end config()

	/**
	 * Get the OR ObjectService (lazy resolution — OR may not be available at boot).
	 *
	 * @return object The OR ObjectService.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
