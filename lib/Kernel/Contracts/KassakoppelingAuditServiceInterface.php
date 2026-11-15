<?php

/**
 * Contract of KassakoppelingAuditService
 *
 * The public surface of KassakoppelingAuditService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Contract of KassakoppelingAuditService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface KassakoppelingAuditServiceInterface {

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
	 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md
	 */
	public function createEntry(array $data): array;

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
	 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md
	 */
	public function exportForBelastingdienst(string $fromDate, string $toDate, string $format = 'xml'): array;

	/**
	 * Fetch a single audit entry by id.
	 *
	 * @param string $id The entry uuid.
	 *
	 * @return array<string, mixed> The entry.
	 *
	 * @throws OCSNotFoundException When the entry is not found.
	 *
	 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md
	 */
	public function getEntry(string $id): array;

	/**
	 * List audit entries with optional filters.
	 *
	 * Supported filter keys: registerNumber, operatorId, action, from
	 * (timestamp >=), to (timestamp <=), verified (true, false or pending),
	 * exported (true or false), search (register, operator or transaction)
	 * and order (desc lists newest first). Each listed entry carries
	 * `verification`: true, false or pending. Unsupported keys are silently
	 * ignored — the API contract is whitelisted, not generic.
	 *
	 * @param array<string, mixed> $filters Optional filters.
	 *
	 * @return array<int, array<string, mixed>> The matching entries ordered
	 *                                          by timestamp, ascending unless
	 *                                          order is desc.
	 *
	 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md
	 */
	public function listEntries(array $filters = []): array;

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
	 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md
	 */
	public function verifyEntry(string $id): array;
}//end interface
