<?php

/**
 * Contract of PosBookkeepingService
 *
 * The public surface of PosBookkeepingService that other components call, so they depend
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
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Contract of PosBookkeepingService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PosBookkeepingServiceInterface {

	/**
	 * Dispatch the journal raise for a Z-report and persist the outcome.
	 *
	 * Server-internal (no manager gate) so the repair step and the retry job can
	 * re-raise an in-flight Z-report directly. Returns the persisted Z-report.
	 *
	 * The optional $idempotencyKey lets the migration repair step re-raise an
	 * in-flight record with its ORIGINAL key; when omitted the deterministic
	 * SHA256(zReport.uuid + reportDate) key is computed (which equals the
	 * original for any record built by this service), so shillinq always
	 * de-duplicates against the same journal.
	 *
	 * @param array<string, mixed> $zReport The Z-report object.
	 * @param string|null $idempotencyKey Optional original idempotency key.
	 *
	 * @return array<string, mixed> The persisted Z-report after the raise.
	 *
	 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
	 */
	public function dispatchRaise(array $zReport, ?string $idempotencyKey = null): array;

	/**
	 * Generate a Z-report for a given reportDate and optional terminal.
	 *
	 * Aggregates every confirmed/settled posTransaction whose confirmedAt falls
	 * on the supplied reportDate (in UTC). When a terminalId is supplied only
	 * transactions on that terminal contribute; when null, the report spans all
	 * terminals. Returns the persisted posZReport array.
	 *
	 * Server-authoritative: subtotal, discountTotal, taxBreakdown, totalTax,
	 * total, paymentMethodBreakdown, transactionCount and transactionIds are
	 * derived from the transactions; the only client input is the date and
	 * optional terminal filter. A non-empty report is created `ready` with a
	 * `pending` bookkeepingStatus so the journal raise can be triggered.
	 *
	 * @param string $reportDate The settlement date in YYYY-MM-DD.
	 * @param string|null $terminalId Optional terminal filter (e.g. kassa-01).
	 *
	 * @return array<string, mixed> The persisted posZReport.
	 *
	 * @throws OCSBadRequestException When the reportDate cannot be parsed.
	 *
	 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
	 */
	public function generateZReport(string $reportDate, ?string $terminalId = null): array;

	/**
	 * Raise the POS-day journal entry in shillinq through the ADR-019 registry.
	 *
	 * Manager-gated (POS manager OR Nextcloud admin via PosAccessPolicy::
	 * isManager). Builds a `shillinq.JournalEntry.raise` CloudEvent carrying the
	 * Z-report **business facts** (date, totals, taxBreakdown) with the
	 * deterministic `X-Idempotency-Key`, dispatches it through OpenRegister's
	 * WebhookService (the ADR-019 registry path) and projects the outcome onto
	 * the parent posZReport:
	 *
	 *   - dispatched         -> bookkeepingStatus = raised (shillinq de-dups via
	 *                           the idempotency key and builds the journal).
	 *   - not configured /   -> bookkeepingStatus = pending (queued for retry;
	 *     shillinq unreachable    the Z-report close is never blocked).
	 *   - max attempts hit   -> bookkeepingStatus = failed + admin alert.
	 *
	 * pipelinq builds NO GL-balanced ledger lines and owns NO chart of accounts;
	 * shillinq maps the taxBreakdown to GL accounts and posts the journal.
	 *
	 * @param string $zReportId The Z-report UUID.
	 * @param string $userId The acting user UID (manager / admin).
	 *
	 * @return array<string, mixed> The persisted Z-report after the raise.
	 *
	 * @throws OCSForbiddenException When the user is not a POS manager / admin.
	 * @throws OCSNotFoundException When the Z-report is missing.
	 *
	 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
	 */
	public function raiseJournalEntry(string $zReportId, string $userId): array;

	/**
	 * Whether journal-raise dispatch is enabled: only when the Shillinq app is
	 * installed on this server. Without it the raise no-ops and leaves
	 * bookkeepingStatus pending, as the WIP and AP hand-offs do.
	 *
	 * @return bool True when Shillinq is installed.
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-shillinq-hand-offs-follow-the-detected-app
	 */
	public function shouldDispatch(): bool;
}//end interface
