<?php

/**
 * Contract of SubmittedLeadIntake
 *
 * The public surface of SubmittedLeadIntake that other components call, so they depend
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
 * Contract of SubmittedLeadIntake.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubmittedLeadIntakeInterface {

	/**
	 * The contact, client and title a new lead gets before it is stored.
	 *
	 * Nothing for a lead no form submitted (no submitter email), and nothing
	 * for a redelivered submission. A value the lead already has is kept.
	 *
	 * @param array<string, mixed> $lead The lead as it is about to be stored.
	 *
	 * @return array<string, string> The properties to set; empty when there is nothing to add.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function partiesFor(array $lead): array;

	/**
	 * Append the `submit` touchpoint of a stored lead, once per nonce.
	 *
	 * @param string               $leadId The stored lead's id.
	 * @param array<string, mixed> $lead   The stored lead.
	 *
	 * @return bool True when a touchpoint was written.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function recordTouchpoint(string $leadId, array $lead): bool;
}//end interface
