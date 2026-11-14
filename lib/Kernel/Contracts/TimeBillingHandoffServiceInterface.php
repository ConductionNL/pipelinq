<?php

/**
 * Contract of TimeBillingHandoffService
 *
 * The public surface of TimeBillingHandoffService that other components call, so they depend
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
 * Contract of TimeBillingHandoffService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimeBillingHandoffServiceInterface {

	/**
	 * Whether the shillinq time-intake handoff is available right now.
	 *
	 * True only when the `shillinq_time_intake_enabled` flag is on AND the
	 * shillinq app is installed and enabled for the acting user. When false,
	 * callers keep offering the deep-link handoff to the detected Shillinq app
	 * unchanged.
	 *
	 * @return bool Whether the intake emit may be attempted.
	 *
	 * @spec openspec/specs/time-approval-workflow/spec.md
	 */
	public function handoffAvailable(): bool;

	/**
	 * Notify administrators about every batch currently `failed`.
	 *
	 * The periodic retry job's ONLY job (orchestrator ruling): it never
	 * re-attempts the intake call itself (no Nextcloud session in job
	 * context, and the intake resolves its tenant from one) — it re-notifies
	 * so the failure is not forgotten, grouped once per `billingBatchId` per
	 * run. The guaranteed re-send is the manual "Send to billing" action.
	 *
	 * @return array<int, string> The batch ids that were re-notified.
	 *
	 * @spec openspec/specs/time-approval-workflow/spec.md
	 */
	public function notifyPendingFailures(): array;

	/**
	 * Send a client's approved, un-billed entries for a period to billing.
	 *
	 * Returns a status array (never throws to the caller):
	 *   - `not-available` — shillinq absent/disabled or the flag is off.
	 *   - `empty`         — nothing approved and un-billed in the period.
	 *   - `synced`        — the batch was accepted (`invoiceId`/`invoiceNumber`/`duplicated`/`entryCount`).
	 *   - `conflict`      — 409, batch in-flight; entries stay `pending`.
	 *   - `unmapped`      — 422, actionable message naming the unmapped client/rate; entries stay `pending`.
	 *   - `failed`        — transport/5xx/malformed payload; entries marked `failed`, admins notified.
	 *
	 * @param string $clientId The client UUID.
	 * @param string $periodStart The period start date (ISO 8601, inclusive).
	 * @param string $periodEnd The period end date (ISO 8601, inclusive).
	 *
	 * @return array<string, mixed> The outcome.
	 *
	 * @spec openspec/specs/time-approval-workflow/spec.md
	 */
	public function sendToBilling(string $clientId, string $periodStart, string $periodEnd): array;
}//end interface
