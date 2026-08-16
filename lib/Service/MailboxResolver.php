<?php

/**
 * Pipelinq MailboxResolver.
 *
 * BSN → mailbox-availability resolver with 24-hour TTL cache backed by
 * the MailboxResolution OR schema. Index lookups happen on `bsnHash`
 * (HMAC-SHA256) so plaintext BSN never appears in WHERE clauses
 * (REQ-MAILBOX-002, REQ-ENCRYPTION-008).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-mailbox-002
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-optin-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Mailbox lookup with TTL cache.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-mailbox-002
 */
class MailboxResolver {
	/**
	 * Default cache TTL in seconds (24h per Logius SLA).
	 *
	 * @var int
	 */
	public const DEFAULT_TTL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config service.
	 * @param EncryptionService $encryption Encryption service (BSN hashing + crypto).
	 * @param LogiusConnector $logiusConnector Logius API wrapper.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly EncryptionService $encryption,
		private readonly LogiusConnector $logiusConnector,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve mailbox availability for a BSN, caching the result.
	 *
	 * @param string $bsn The plaintext BSN.
	 * @param string $tenantId The tenant identifier.
	 *
	 * @return array{mailboxAvailable: bool, optedOut: bool, expiresAt: string, resolvedAt: string, bsnHash: string, source: string}
	 *
	 * @throws RuntimeException On configuration error.
	 */
	public function resolve(string $bsn, string $tenantId): array {
		$bsnHash = $this->encryption->hashBsn($bsn, $tenantId);
		$cached = $this->lookupCache(bsnHash: $bsnHash);
		if ($cached !== null) {
			$cached['source'] = 'cache';
			return $cached;
		}

		$available = false;
		try {
			$available = $this->logiusConnector->checkMailboxExists($bsn);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Berichtenbox mailbox check failed; treating as unavailable.',
				['exception' => $e->getMessage()]
			);
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$expiresAt = $now->modify('+' . self::DEFAULT_TTL_SECONDS . ' seconds');

		$row = [
			'bsn' => $this->encryption->encrypt($bsn, $tenantId),
			'bsnHash' => $bsnHash,
			'mailboxAvailable' => $available,
			'resolvedAt' => $now->format(DATE_ATOM),
			'expiresAt' => $expiresAt->format(DATE_ATOM),
			'optedOut' => false,
		];

		$this->writeCache(row: $row);

		return [
			'mailboxAvailable' => $available,
			'optedOut' => false,
			'resolvedAt' => $row['resolvedAt'],
			'expiresAt' => $row['expiresAt'],
			'bsnHash' => $bsnHash,
			'source' => 'logius',
		];
	}//end resolve()

	/**
	 * Mark a BSN as opted-out (REQ-OPTIN-011).
	 *
	 * @param string $bsn Plaintext BSN.
	 * @param string $tenantId Tenant identifier.
	 *
	 * @return void
	 */
	public function markOptedOut(string $bsn, string $tenantId): void {
		$bsnHash = $this->encryption->hashBsn($bsn, $tenantId);
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$row = [
			'bsn' => $this->encryption->encrypt($bsn, $tenantId),
			'bsnHash' => $bsnHash,
			'mailboxAvailable' => false,
			'resolvedAt' => $now->format(DATE_ATOM),
			'expiresAt' => $now->modify('+10 years')->format(DATE_ATOM),
			'optedOut' => true,
		];

		$this->writeCache(row: $row);
	}//end markOptedOut()

	/**
	 * Look up a cached resolution by bsnHash.
	 *
	 * @param string $bsnHash The HMAC of the BSN.
	 *
	 * @return array|null The cached row or null when expired/absent.
	 */
	private function lookupCache(string $bsnHash): ?array {
		[$register, $schema] = $this->config();
		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'bsnHash' => $bsnHash,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'MailboxResolver cache lookup failed.',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($data === null) {
				continue;
			}

			$expiresAt = $data['expiresAt'] ?? null;
			if (is_string($expiresAt) === false) {
				continue;
			}

			try {
				$expires = new DateTimeImmutable($expiresAt);
			} catch (\Throwable) {
				continue;
			}

			if ($expires > $now) {
				return [
					'mailboxAvailable' => (bool)($data['mailboxAvailable'] ?? false),
					'optedOut' => (bool)($data['optedOut'] ?? false),
					'resolvedAt' => (string)($data['resolvedAt'] ?? $now->format(DATE_ATOM)),
					'expiresAt' => $expiresAt,
					'bsnHash' => $bsnHash,
					'source' => 'cache',
				];
			}
		}//end foreach

		return null;
	}//end lookupCache()

	/**
	 * Persist (or upsert) a cache row.
	 *
	 * @param array $row The MailboxResolution payload.
	 *
	 * @return void
	 */
	private function writeCache(array $row): void {
		[$register, $schema] = $this->config();
		try {
			$this->getObjectService()->saveObject(
				object: $row,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: null
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'MailboxResolver cache write failed.',
				['exception' => $e->getMessage()]
			);
		}
	}//end writeCache()

	/**
	 * Get OR ObjectService.
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface
	 *
	 * @throws RuntimeException If OR not available.
	 */
	private function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Read register + schema config.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function config(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			'mailboxResolution_schema',
			''
		);

		if ($register === '' || $schema === '') {
			throw new RuntimeException('MailboxResolution register or schema not configured.');
		}

		return [$register, $schema];
	}//end config()

	/**
	 * Normalise an OR result entry to an associative array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array|null
	 */
	private function toArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true) {
			if (method_exists($row, 'jsonSerialize') === true) {
				$serialised = $row->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($row, 'getObject') === true) {
				$inner = $row->getObject();
				if (is_array($inner) === true) {
					return $inner;
				}
			}
		}

		return null;
	}//end toArray()
}//end class
