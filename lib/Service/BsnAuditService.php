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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Append-only audit-trail service for BSN bevragingen.
 *
 * Always writes; never reads / updates / deletes. The bsnAuditRecord schema has
 * `x-openregister-immutable: true` so a record cannot be modified via standard CRUD.
 * On Right-to-be-forgotten the system replaces the BSN-hash with a re-hashed value
 * via {@see pseudonymise()} — the rest of the audit chain (actor / doelbinding /
 * tijdstip) remains traceable.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-005
 */
class BsnAuditService {
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
	 * @param string $actor Actor user UID.
	 * @param string $rawBsn Raw 9-digit BSN (hashed in-process; never stored).
	 * @param string $verzoekreden Verzoekreden (compliance audit field).
	 * @param string $doelbinding Doelbinding (compliance audit field).
	 * @param string $uitkomst Outcome enum value (see schema).
	 * @param string $action Action enum value (default `brp-lookup-uitgevoerd`).
	 * @param int|null $responseCode HTTP status from HaalCentraal (200, 404, 503).
	 * @param string|null $haalcentraalCorrelationId Correlation ID for trace.
	 * @param string|null $linkedRequest UUID of linked Pipelinq verzoek.
	 * @param string|null $actorRole Role of actor (behandelaar-burgerzaken).
	 * @param bool $vogScreening VOG-screening flag for Justis.
	 *
	 * @return string The UUID of the written audit record (empty string if writing fails).
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-005-01
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One audit record maps 1:1 to these compliance fields; a DTO would only shift the list.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    $vogScreening is an audit fact recorded verbatim on the record, not a behaviour switch.
	 * @SuppressWarnings(PHPMD.LongVariable)           $haalcentraalCorrelationId mirrors the named-arg caller; renaming breaks the call site.
	 * @SuppressWarnings(PHPMD.StaticAccess)           BsnValidationService hash/mask are pure stateless helpers.
	 */
	public function recordLookup(
		string $actor,
		string $rawBsn,
		string $verzoekreden,
		string $doelbinding,
		string $uitkomst,
		string $action = 'brp-lookup-uitgevoerd',
		?int $responseCode = null,
		?string $haalcentraalCorrelationId = null,
		?string $linkedRequest = null,
		?string $actorRole = null,
		bool $vogScreening = false,
	): string {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$retainUntil = $now->modify('+' . self::RETENTION_YEARS . ' years');

		$record = [
			'action' => $action,
			'bsnHash' => BsnValidationService::hash($rawBsn),
			'actor' => $actor,
			'actorRole' => $actorRole,
			'moment' => $now->format(DATE_ATOM),
			'verzoekreden' => $verzoekreden,
			'doelbinding' => $doelbinding,
			'uitkomst' => $uitkomst,
			'responseCode' => $responseCode,
			'ipAdres' => self::anonymiseIp(ipAddress: $this->request->getRemoteAddress()),
			'userAgent' => 'Pipelinq/' . (Application::APP_ID) . ' (Nextcloud)',
			'haalcentraalCorrelationId' => $haalcentraalCorrelationId,
			'linkedRequest' => $linkedRequest,
			'vogScreening' => $vogScreening,
			'retainUntil' => $retainUntil->format(DATE_ATOM),
		];

		// Drop nulls — they pollute the audit record.
		$record = array_filter($record, static fn ($v) => $v !== null);

		// Mask the BSN in logs (REQ-BSN-009-01) — never the raw value.
		$maskedBsn = BsnValidationService::mask($rawBsn);

		try {
			[$register, $schema] = $this->config();
			$saved = $this->getObjectService()->saveObject(
				object: $record,
				extend: [],
				register: $register,
				schema: $schema,
			);

			$uuid = '';
			if (is_array($saved) === true) {
				$uuid = (string)($saved['@self']['id'] ?? $saved['id'] ?? '');
			} elseif (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {
				$uuid = (string)$saved->getUuid();
			}

			$this->logger->info(
				'BSN audit record written',
				[
					'action' => $action,
					'actor' => $actor,
					'bsn' => $maskedBsn,
					'uitkomst' => $uitkomst,
				]
			);
			return $uuid;
		} catch (Throwable $e) {
			// Failing to write the audit record must NOT crash the calling flow — the
			// outcome is still surfaced through the controller. Log the error with the
			// masked BSN so postmortems are possible.
			$this->logger->error(
				'BSN audit record write failed',
				[
					'action' => $action,
					'actor' => $actor,
					'bsn' => $maskedBsn,
					'error' => $e->getMessage(),
				]
			);
			return '';
		}//end try
	}//end recordLookup()

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
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-008-02
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::hash is a pure stateless helper.
	 */
	public function pseudonymise(string $rawBsn): int {
		try {
			[$register, $schema] = $this->config();
			$oldHash = BsnValidationService::hash($rawBsn);
			// Pseudonym = HMAC(bsn, secret) — caller-side; we just bump it once.
			$secret = $this->appConfig->getValueString(
				Application::APP_ID,
				'brp.pseudonym_secret',
				''
			);
			if ($secret === '') {
				$this->logger->warning(
					'No pseudonym secret configured; RTBF pseudonymise skipped',
					['actor' => 'system']
				);
				return 0;
			}

			$newHash = hash_hmac('sha256', $rawBsn, $secret);

			$records = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'bsnHash' => $oldHash,
						'register' => $register,
						'schema' => $schema,
					],
				]
			);

			$count = 0;
			foreach (($records ?? []) as $record) {
				$arr = [];
				if (is_array($record) === true) {
					$arr = $record;
				} elseif (method_exists($record, 'jsonSerialize') === true) {
					$arr = (array)$record->jsonSerialize();
				}

				$uuid = (string)($arr['@self']['id'] ?? $arr['id'] ?? '');
				if ($uuid === '') {
					continue;
				}

				// Immutable schema: callers MUST go through the system pseudonym path.
				$arr['bsnHash'] = $newHash;
				$arr['action'] = 'brp-rtbf-gepseudonimiseerd';
				$arr['uitkomst'] = 'gepseudonimiseerd';
				$this->getObjectService()->saveObject(
					object: $arr,
					extend: [],
					register: $register,
					schema: $schema,
					uuid: $uuid,
				);
				$count++;
			}//end foreach

			return $count;
		} catch (Throwable $e) {
			$this->logger->error(
				'BSN audit pseudonymise failed',
				['error' => $e->getMessage()]
			);
			return 0;
		}//end try
	}//end pseudonymise()

	/**
	 * Anonymise an IPv4 address by zeroing the last octet (ipv6: zero the last 80 bits).
	 *
	 * @param string $ipAddress Raw IP from request scope.
	 *
	 * @return string Anonymised IP suitable for audit storage.
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-009-01
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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'bsnAuditRecord_schema', '');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('bsnAuditRecord register/schema not configured.');
		}

		return [$register, $schema];
	}//end config()

	/**
	 * Get the OR ObjectService (lazy resolution — OR may not be available at boot).
	 *
	 * @return object The OR ObjectService.
	 *
	 * @throws RuntimeException If OR is unavailable.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
