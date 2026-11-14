<?php

/**
 * Contract of WooRequestConversionService
 *
 * The public surface of WooRequestConversionService that other components call, so they depend
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
 * Contract of WooRequestConversionService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WooRequestConversionServiceInterface {

	/**
	 * What the ticket page may offer for this ticket.
	 *
	 * @param array<string, mixed> $ticket The ticket, read under the employee's RBAC.
	 *
	 * @return array{available: bool, canConvert: bool, status: string, caseReference: string}
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function availability(array $ticket): array;

	/**
	 * Start a Woo request for the ticket and mark the ticket converted.
	 *
	 * @param string               $ticketId The ticket id.
	 * @param array<string, mixed> $ticket   The ticket, read under the employee's RBAC.
	 *
	 * @return array{status: string, caseReference?: string, caseUrl?: string}
	 *   `converted`, `not-available` (no dossiq), `not-convertible` (no dossier
	 *   snapshot, or already converted), `intake-failed`, or `converted-unsynced`
	 *   when the case exists but the ticket write failed.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function convert(string $ticketId, array $ticket): array;
}//end interface
