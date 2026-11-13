<?php

/**
 * Contract of PosTenderService
 *
 * The public surface of PosTenderService that other components call, so they depend
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

use OCA\Pipelinq\Service\InvalidTenderException;
use OCA\Pipelinq\Service\TenderTypeNotFoundException;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Contract of PosTenderService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PosTenderServiceInterface {

	/**
	 * Add a tender to a transaction.
	 *
	 * Validates: transaction exists; transaction status is NOT `settled`;
	 * tender amount >= 0.01; tenderType id resolves and is active; if the
	 * type requires a reference, `reference` is non-empty. Copies the type's
	 * glAccount onto the tender. Computes change for CASH overpayment.
	 *
	 * @param string $transactionId The posTransaction UUID.
	 * @param array<string, mixed> $payload The tender payload (tenderType, amount, reference?).
	 *
	 * @return array<string, mixed> The created tender.
	 *
	 * @throws OCSNotFoundException When the transaction is not found.
	 * @throws TenderTypeNotFoundException When the tender type is not found.
	 * @throws InvalidTenderException When the tender fails its validation invariant.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#REQ-PST-002
	 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#REQ-PST-005
	 */
	public function addTender(string $transactionId, array $payload): array;

	/**
	 * Create a new tender type (admin path).
	 *
	 * @param array<string, mixed> $data The tender type payload.
	 *
	 * @return array<string, mixed> The persisted tender type.
	 *
	 * @throws OCSBadRequestException When required fields are missing or the code is not unique.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#REQ-PST-001
	 */
	public function createTenderType(array $data): array;

	/**
	 * Delete a tender type (admin path). Rejects when active tenders reference it.
	 *
	 * @param string $id The tender type UUID.
	 *
	 * @return void
	 *
	 * @throws TenderTypeNotFoundException When the id does not resolve.
	 * @throws OCSBadRequestException When active tenders reference this type.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#REQ-PST-001
	 */
	public function deleteTenderType(string $id): void;

	/**
	 * Emit a single TenderPostedEvent for one tender + persist its event-id +
	 * increment glPostAttempts. Used both by the settle path and the retry
	 * job.
	 *
	 * @param string $transactionUuid The transaction UUID.
	 * @param string $transactionReference The human-readable transaction reference.
	 * @param array<string, mixed> $tender The tender object.
	 *
	 * @return string The emitted event id, or empty when the emission was a no-op.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#REQ-PST-006
	 */
	public function emitSingleTenderPosted(string $transactionUuid, string $transactionReference, array $tender): string;

	/**
	 * Look up a tender type by its UUID.
	 *
	 * @param string $id The tender type UUID.
	 *
	 * @return array<string, mixed> The tender type.
	 *
	 * @throws TenderTypeNotFoundException When the id does not resolve.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#REQ-PST-001
	 */
	public function getTenderTypeById(string $id): array;

	/**
	 * List all tenders attached to a transaction, sorted by sortOrder.
	 *
	 * `register` / `schema` MUST sit inside `filters`. OpenRegister's
	 * `ObjectService::prepareFindAllConfig()` resolves the query context from
	 * `$config['filters']['register']` / `['schema']` and from nowhere else,
	 * even though `findAll()`'s own docblock lists them as top-level keys. A
	 * top-level pair leaves `currentRegister` / `currentSchema` untouched, so
	 * `MagicMapper::findAll()` logs a warning and returns `[]` (pipelinq#793).
	 *
	 * That made this one line behave differently per endpoint: reached through
	 * `addTender()` a preceding `saveObject()` had already pinned the posTender
	 * context and the read worked, while `settle` — which is preceded only by a
	 * `find()`, and `find()` restores the context it borrowed — read nothing,
	 * so `assertBalancedForSettle()` saw `tenderSum = 0` and rejected every
	 * non-zero transaction with a 409 underpayment (pipelinq#799).
	 *
	 * @param string $transactionId The posTransaction UUID.
	 *
	 * @return array<int, array<string, mixed>> The tenders.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#REQ-PST-002
	 */
	public function getTendersForTransaction(string $transactionId): array;

	/**
	 * List all configured tender types.
	 *
	 * @param bool $activeOnly When true, filter to isActive=true.
	 *
	 * @return array<int, array<string, mixed>> The tender types sorted by sortOrder.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#REQ-PST-001
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) public API consumed positionally
	 *  by PosTenderController and the retry job; changing the signature is out of
	 *  scope for this mechanical pass
	 */
	public function listTenderTypes(bool $activeOnly = false): array;

	/**
	 * List tenders for which the GL CloudEvent has not yet been confirmed
	 * received by Shillinq (glPosted=false, attempts<MAX). The retry job
	 * iterates this list and re-emits.
	 *
	 * @return array<int, array<string, mixed>> The unposted tenders.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#REQ-PST-006
	 */
	public function listUnpostedTenders(): array;

	/**
	 * Remove a tender from a transaction.
	 *
	 * @param string $transactionId The posTransaction UUID (validates ownership).
	 * @param string $tenderId The posTender UUID.
	 *
	 * @return void
	 *
	 * @throws OCSNotFoundException When the transaction or tender is not found.
	 * @throws InvalidTenderException When the transaction is already settled.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#REQ-PST-003
	 */
	public function removeTender(string $transactionId, string $tenderId): void;

	/**
	 * Update an existing tender type (admin path). The `code` is preserved
	 * (immutable after creation).
	 *
	 * @param string $id The tender type UUID.
	 * @param array<string, mixed> $data The patch payload.
	 *
	 * @return array<string, mixed> The updated tender type.
	 *
	 * @throws TenderTypeNotFoundException When the id does not resolve.
	 * @throws OCSBadRequestException When required fields are missing.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#REQ-PST-001
	 */
	public function updateTenderType(string $id, array $data): array;

	/**
	 * Validate the tender-sum invariant: sum of posTender.amount MUST equal
	 * the transaction total before settlement. Returns the comparison.
	 *
	 * @param string $transactionId The posTransaction UUID.
	 *
	 * @return array{tenderSum: float, transactionTotal: float, variance: float, balanced: bool}
	 *
	 * @throws OCSNotFoundException When the transaction is not found.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#REQ-PST-004
	 */
	public function validateTenderSum(string $transactionId): array;
}//end interface
