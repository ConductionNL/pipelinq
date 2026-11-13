<?php

/**
 * Contract of ShillinqApService
 *
 * The public surface of ShillinqApService that other components call, so they depend
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
 * Contract of ShillinqApService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ShillinqApServiceInterface {

	/**
	 * Dispatch an expense approval event to the Shillinq AP webhook.
	 *
	 * @param array<string, mixed> $expense The expense object data (must contain uuid + amount).
	 * @param string $approvedBy The approving user id.
	 * @param string $approvedAt The ISO 8601 approval timestamp.
	 *
	 * @return bool True on successful dispatch, false on failure or when unconfigured.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
	 */
	public function dispatchApEvent(array $expense, string $approvedBy, string $approvedAt): bool;

	/**
	 * Current UTC timestamp in ISO 8601 format.
	 *
	 * Public so listeners and the retry controller can stamp apSyncedAt
	 * consistently without each coupling directly to the date-time classes.
	 *
	 * @return string The ISO 8601 timestamp.
	 * @spec openspec/specs/time-approval-workflow/spec.md#requirement-approved-time-entries-are-emitted-to-shillinqs-time-intake
	 */
	public function now(): string;

	/**
	 * Whether AP dispatch is enabled.
	 *
	 * True when the Shillinq app is installed on this server. Nobody types a
	 * webhook URL: the event travels internally through OpenRegister's
	 * WebhookService, so detecting Shillinq is all the configuration there is.
	 * Without Shillinq the listeners no-op silently.
	 *
	 * @return bool True when Shillinq is installed.
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-shillinq-hand-offs-follow-the-detected-app
	 */
	public function shouldDispatch(): bool;
}//end interface
