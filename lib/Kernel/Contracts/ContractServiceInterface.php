<?php

/**
 * Contract of ContractService
 *
 * The public surface of ContractService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of ContractService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContractServiceInterface {

	/**
	 * Validate a proposed status transition.
	 *
	 * Guards (REQ Contract Lifecycle Management):
	 *   - any transition out of a terminal state (renewed/churned/cancelled) is rejected
	 *   - `renewed` requires a won renewal lead (renewalLeadOutcome === 'won')
	 *   - `expiring` may only be set by the renewal engine ($byEngine === true)
	 *   - `cancelled` requires a non-empty cancellationReason
	 *   - the target must be a valid state
	 *
	 * @param array<string,mixed> $contract The current contract object.
	 * @param string $newStatus The proposed status.
	 * @param bool $byEngine Whether the caller is the renewal engine.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the transition is not allowed.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  $byEngine marks renewal-engine callers; part of the lifecycle contract, not a branch flag.
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential transition guards; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Sequential transition guards; extraction adds no clarity.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
	 */
	public function assertTransitionAllowed(array $contract, string $newStatus, bool $byEngine = false): void;

	/**
	 * Build a successor-contract draft from a renewed predecessor.
	 *
	 * StartDate = predecessor endDate + 1 day; status `draft`;
	 * predecessorContractRef set; renewal-specific fields reset.
	 *
	 * @param array<string,mixed> $predecessor The renewed contract.
	 *
	 * @return array<string,mixed> The successor draft payload.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-renewal-lead-automation
	 */
	public function buildSuccessorDraft(array $predecessor): array;

	/**
	 * Generate the next unique contract number (C-{year}-{seq}).
	 *
	 * Sequence is derived from the count of existing contracts in the current
	 * calendar year plus one; uniqueness is re-checked against existing numbers.
	 *
	 * @param array<int, array<string,mixed>> $existing Existing contract objects.
	 * @param int|null $year The year (defaults to current).
	 *
	 * @return string The next contract number.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
	 */
	public function generateContractNumber(array $existing, ?int $year = null): string;

	/**
	 * Resolve the configured register and contract-schema IDs.
	 *
	 * Fails closed: '' on either id means "unconfigured", and every caller
	 * refuses the OpenRegister call on it. An empty id must never be handed to
	 * OpenRegister — ObjectService skips setRegister()/setSchema() for an empty
	 * value, so the query silently inherits whatever context an earlier call in
	 * the same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @return array{0:string,1:string} [registerId, schemaId], each '' when
	 *                                  unconfigured.
	 *
	 * @spec exclude config-key plumbing — resolves the OR register/schema ids the lifecycle ops scope to
	 */
	public function getRegisterAndSchema(): array;

	/**
	 * Load all contracts from OpenRegister ([] on failure).
	 *
	 * @return array<int, array<string,mixed>> The contract objects.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
	 */
	public function loadAll(): array;

	/**
	 * Persist a contract object via OpenRegister.
	 *
	 * @param array<string,mixed> $data The contract payload.
	 * @param string|null $uuid The existing UUID, or null to create.
	 *
	 * @return array<string,mixed>|null The saved object (array), or null on failure.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
	 */
	public function save(array $data, ?string $uuid = null): ?array;
}//end interface
