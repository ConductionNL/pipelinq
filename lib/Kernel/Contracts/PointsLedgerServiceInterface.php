<?php

/**
 * Contract of PointsLedgerService
 *
 * The public surface of PointsLedgerService that other components call, so they depend
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

/**
 * Contract of PointsLedgerService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PointsLedgerServiceInterface {

	/**
	 * This service for one operation: the next movement carries the
	 * operation id, and a repeat of it returns the first entry.
	 *
	 * The caller picks the id and passes the same id on every retry. An
	 * empty id is no id: the movement is appended as always.
	 *
	 * @param string $operationId The operation id, unique per account.
	 *
	 * @return static The service for that operation.
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#requirement-a-retried-movement-is-written-once-req-loy-011
	 */
	public function forOperation(string $operationId): static;

	/**
	 * Fetch the ledger history for an account, optionally bounded by date.
	 *
	 * @param string $accountId The account UUID.
	 * @param ?string $from ISO-8601 lower bound (inclusive) on timestamp.
	 * @param ?string $to ISO-8601 upper bound (inclusive) on timestamp.
	 *
	 * @return array<int, array<string, mixed>> The ledger entries, oldest first.
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function getLedgerHistory(string $accountId, ?string $from = null, ?string $to = null): array;

	/**
	 * Expire points (system-initiated by PointsExpiryBatchJob).
	 *
	 * @param string $accountId The account UUID.
	 * @param int $amount Positive integer to expire.
	 * @param string $reason Expiry reason (e.g. "12m inactivity").
	 *
	 * @return array<string, mixed> The ledger entry.
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-005-01
	 */
	public function expirePoints(string $accountId, int $amount, string $reason): array;
}//end interface
